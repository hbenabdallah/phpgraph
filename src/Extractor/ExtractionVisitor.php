<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Edge;
use PhpGraph\Graph\Node;
use PhpGraph\Graph\NodeKind;
use PhpGraph\Graph\Relation;
use PhpParser\Node as AstNode;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt;
use PhpParser\Node\UnionType;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

final class ExtractionVisitor extends NodeVisitorAbstract
{
    private readonly string $fileId;

    /** @var list<Node> */
    private array $nodes = [];

    /** @var list<Edge> */
    private array $edges = [];

    /** @var list<PendingCall> */
    private array $pending = [];

    private ?string $currentClass = null;

    private ?string $currentParent = null;

    private ?string $currentCallable = null;

    /** @var array<string, string> */
    private array $propertyTypes = [];

    /** @var array<string, TypeExpr> */
    private array $localTypes = [];

    /**
     * Variables typed by an inline `@var`, which assignments in the same statement do not override.
     *
     * @var array<string, int>
     */
    private array $pinned = [];

    /** @var list<array<string, TypeExpr>> */
    private array $outerScopes = [];

    /** @var array<string, string> */
    private array $returnTypes = [];

    /** @var array<string, string> */
    private array $declaredPropertyTypes = [];

    private readonly BusExtractor $bus;

    private readonly RouteExtractor $http;

    /** @var list<int> object ids of the Laravel route groups pushing a path prefix */
    private array $routeGroups = [];

    /** @var list<array{name: string, handles: ?string, method: ?string, key: ?string, path: ?string, methods: list<string>}> */
    private array $classAttributes = [];

    /** @var array<string, string> */
    private array $constants = [];

    /** @var array<string, string> container service id => class or `@alias` */
    private array $services = [];

    /** @var list<array{id: ?string, instanceof: ?string, name: string, attributes: array<string, string>}> */
    private array $serviceTags = [];

    /** @var array<string, string> */
    private array $parameterTypes = [];

    public function __construct(private readonly string $path)
    {
        $this->fileId = 'file:' . $path;
        $this->bus = new BusExtractor();
        // Laravel prefixes the routes of routes/api.php with /api.
        $this->http = new RouteExtractor($path, preg_match('#(^|/)routes/api\.php$#', $path) === 1 ? ['api'] : []);
        $this->nodes[] = new Node($this->fileId, $path, NodeKind::File, $path, 1);
    }

    public function result(): FileExtraction
    {
        return new FileExtraction(
            $this->nodes,
            $this->edges,
            $this->pending,
            $this->returnTypes,
            $this->declaredPropertyTypes,
            $this->bus->handlers(),
            $this->bus->dispatches(),
            $this->constants,
            $this->http->routes(),
            $this->http->requests(),
            $this->services,
            $this->serviceTags,
            $this->parameterTypes,
        );
    }

    /**
     * @return int|null
     */
    public function enterNode(AstNode $node)
    {
        // Inside a function, or in a script outside any class.
        if ($node instanceof Stmt && ($this->currentCallable !== null || $this->currentClass === null)) {
            $this->pinInlineVarTypes($node);
        }

        if ($node instanceof Stmt\Use_ || $node instanceof Stmt\GroupUse) {
            $this->onUse($node);

            return null;
        }

        if ($node instanceof Stmt\ClassLike) {
            return $this->onClassLike($node);
        }

        if ($node instanceof Stmt\ClassMethod) {
            $this->onCallable(
                $this->currentClass . '::' . $node->name->toString(),
                $this->shortName((string) $this->currentClass) . '::' . $node->name->toString() . '()',
                NodeKind::Method,
                $node,
            );

            return null;
        }

        if ($node instanceof Stmt\Function_) {
            $name = $node->namespacedName?->toString() ?? $node->name->toString();
            $this->onCallable($name, $this->shortName($name) . '()', NodeKind::Func, $node);

            return null;
        }

        if ($node instanceof Stmt\Property) {
            foreach ($this->typeNames($node->type) as $type) {
                $this->reference($this->owner(), $type);
            }

            return null;
        }

        if ($node instanceof Stmt\TraitUse) {
            foreach ($node->traits as $trait) {
                $this->link($this->owner(), $this->resolveName($trait), Relation::UsesTrait);
            }

            return null;
        }

        if ($node instanceof Stmt\Catch_) {
            foreach ($node->types as $type) {
                $this->reference($this->owner(), $this->resolveName($type));
            }
            if ($node->var !== null && \is_string($node->var->name)) {
                $class = \count($node->types) === 1 ? $this->resolveName($node->types[0]) : null;
                $this->assignLocal($node->var->name, $class === null ? null : TypeExpr::named($class));
            }

            return null;
        }

        if ($node instanceof AstNode\Attribute) {
            $this->reference($this->owner(), $this->resolveName($node->name));

            return null;
        }

        if ($node instanceof Stmt\Foreach_) {
            $this->forgetAssigned($node->keyVar);
            $this->forgetAssigned($node->valueVar);

            return null;
        }

        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $this->enterClosure($node);

            return null;
        }

        $this->onExpression($node);

        return null;
    }

    public function leaveNode(AstNode $node)
    {
        if ($this->routeGroups !== [] && end($this->routeGroups) === spl_object_id($node)) {
            array_pop($this->routeGroups);
            $this->http->popPrefix();
        }

        if ($node instanceof Expr\Assign || $node instanceof Expr\AssignRef) {
            if ($node->var instanceof Expr\Variable && \is_string($node->var->name)) {
                $this->assignLocal($node->var->name, $this->typeOf($node->expr));
            } else {
                $this->forgetAssigned($node->var);
            }
        } elseif ($node instanceof Expr\AssignOp) {
            $this->forgetAssigned($node->var);
        }

        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $this->localTypes = array_pop($this->outerScopes) ?? [];
        }

        if ($node instanceof Stmt) {
            $this->pinned = array_filter($this->pinned, static fn (int $id): bool => $id !== spl_object_id($node));
        }

        if ($node instanceof Stmt\ClassLike && $node->name !== null) {
            $this->currentClass = null;
            $this->currentParent = null;
            $this->propertyTypes = [];
            $this->classAttributes = [];
        }

        if ($node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_) {
            $this->currentCallable = null;
            $this->localTypes = [];
            $this->pinned = [];
            $this->outerScopes = [];
        }

        return null;
    }

    private function onUse(Stmt\Use_|Stmt\GroupUse $node): void
    {
        foreach ($node->uses as $use) {
            $type = $use->type !== Stmt\Use_::TYPE_UNKNOWN ? $use->type : $node->type;
            if ($type !== Stmt\Use_::TYPE_NORMAL) {
                continue;
            }

            $name = $node instanceof Stmt\GroupUse
                ? $node->prefix->toString() . '\\' . $use->name->toString()
                : $use->name->toString();

            $this->link($this->fileId, $name, Relation::Imports);
        }
    }

    private function onClassLike(Stmt\ClassLike $node): ?int
    {
        if ($node->name === null) {
            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
        }

        $fqcn = $node->namespacedName?->toString() ?? $node->name->toString();
        $kind = match (true) {
            $node instanceof Stmt\Interface_ => NodeKind::PhpInterface,
            $node instanceof Stmt\Trait_ => NodeKind::PhpTrait,
            $node instanceof Stmt\Enum_ => NodeKind::PhpEnum,
            default => NodeKind::PhpClass,
        };

        $this->nodes[] = new Node($fqcn, $node->name->toString(), $kind, $this->path, $node->getStartLine());
        $this->link($this->fileId, $fqcn, Relation::Defines);

        $this->currentClass = $fqcn;
        $this->currentParent = null;
        $this->classAttributes = $this->attributes($node->attrGroups);
        foreach ($node->stmts as $statement) {
            if ($statement instanceof Stmt\ClassConst) {
                foreach ($statement->consts as $constant) {
                    if ($constant->value instanceof AstNode\Scalar\String_) {
                        $this->constants[$fqcn . '::' . $constant->name->toString()] = $constant->value->value;
                    }
                }
            }
        }
        $this->propertyTypes = $this->collectPropertyTypes($node);
        foreach ($this->propertyTypes as $property => $type) {
            $this->declaredPropertyTypes[$fqcn . '::' . $property] = $type;
        }

        if ($node instanceof Stmt\Class_) {
            if ($node->extends !== null) {
                $parent = $node->extends->toString();
                $this->currentParent = $parent;
                $this->link($fqcn, $parent, Relation::Extends);
                if (str_ends_with($parent, 'EventServiceProvider')) {
                    $this->bus->onListenMap($this->listenMap($node));
                }
            }
            foreach ($node->implements as $interface) {
                $this->link($fqcn, $interface->toString(), Relation::Implements);
            }
        } elseif ($node instanceof Stmt\Interface_) {
            foreach ($node->extends as $interface) {
                $this->link($fqcn, $interface->toString(), Relation::Extends);
            }
        } elseif ($node instanceof Stmt\Enum_) {
            foreach ($node->implements as $interface) {
                $this->link($fqcn, $interface->toString(), Relation::Implements);
            }
        }

        return null;
    }

    private function onCallable(string $id, string $label, NodeKind $kind, Stmt\ClassMethod|Stmt\Function_ $node): void
    {
        $this->nodes[] = new Node($id, $label, $kind, $this->path, $node->getStartLine());

        if ($kind === NodeKind::Method && $this->currentClass !== null) {
            $this->link($this->currentClass, $id, Relation::HasMethod);
        } else {
            $this->link($this->fileId, $id, Relation::Defines);
        }

        $this->currentCallable = $id;
        $this->localTypes = [];
        $this->pinned = [];
        $this->outerScopes = [];

        foreach ($node->params as $param) {
            foreach ($this->typeNames($param->type) as $type) {
                $this->reference($id, $type);
            }
        }
        $this->typeParameters($node->params);

        foreach ($this->typeNames($node->returnType) as $type) {
            $this->reference($id, $type);
        }

        if ($kind === NodeKind::Method) {
            $firstParameter = $this->singleType($node->params[0]->type ?? null);
            if ($firstParameter !== null) {
                $this->parameterTypes[$id] = $firstParameter;
            }
            if ($node instanceof Stmt\ClassMethod && $this->currentClass !== null && strtolower($node->name->toString()) === 'getsubscribedevents') {
                $this->onSubscribedEvents($node);
            }
            $returnType = $this->declaredReturnType($node);
            if ($returnType !== null) {
                $this->returnTypes[$id] = $returnType;
            }
            if ($this->currentClass !== null) {
                $this->http->onControllerMethod(
                    $this->currentClass,
                    $node->name->toString(),
                    $this->routeAttributes($node->attrGroups),
                    array_values(array_filter($this->classAttributes, fn (array $attribute): bool => $this->isRouteAttribute($attribute['name']))),
                    $node->getStartLine(),
                );
                $this->bus->onMethod(
                    $this->currentClass,
                    $id,
                    $node->name->toString(),
                    $this->attributes($node->attrGroups),
                    $this->classAttributes,
                    array_values(array_map(fn (AstNode\Param $param): ?string => $this->singleType($param->type), $node->params)),
                );
            }
        }
    }

    /**
     * The single class a method returns: the native type first, then the `@return` docblock.
     */
    private function declaredReturnType(Stmt\ClassMethod|Stmt\Function_ $node): ?string
    {
        if ($node->returnType instanceof Name && strtolower($node->returnType->toString()) === 'static') {
            return TypeExpr::STATIC;
        }

        $native = $this->singleType($node->returnType);
        if ($native !== null) {
            return $native;
        }

        $documented = $node->getAttribute(DocTypeResolver::RETURN_TYPE);

        return \is_string($documented) ? $this->documentedClass($documented) : null;
    }

    private function documentedClass(string $type): ?string
    {
        return $type === 'self' ? $this->currentClass : $type;
    }

    /**
     * @param array<AstNode\Param> $params
     */
    private function typeParameters(array $params): void
    {
        foreach ($params as $param) {
            if ($param->var instanceof Expr\Variable && \is_string($param->var->name)) {
                $type = $this->singleType($param->type);
                $this->assignLocal($param->var->name, $type === null ? null : TypeExpr::named($type));
            }
        }
    }

    private function enterClosure(Expr\Closure|Expr\ArrowFunction $node): void
    {
        $this->outerScopes[] = $this->localTypes;

        if ($node instanceof Expr\Closure) {
            $inherited = [];
            foreach ($node->uses as $use) {
                if (\is_string($use->var->name) && isset($this->localTypes[$use->var->name])) {
                    $inherited[$use->var->name] = $this->localTypes[$use->var->name];
                }
            }
            $this->localTypes = $inherited;
        }

        $this->typeParameters($node->params);
    }

    /**
     * Applies inline `@var` docblocks written on a statement inside a function body.
     */
    private function pinInlineVarTypes(Stmt $node): void
    {
        $types = $node->getAttribute(DocTypeResolver::VAR_TYPES);
        if (!\is_array($types) || $node instanceof Stmt\Property || $node instanceof Stmt\ClassMethod) {
            return;
        }

        foreach ($types as $name => $type) {
            if ($name === '' && $node instanceof Stmt\Expression && $node->expr instanceof Expr\Assign
                && $node->expr->var instanceof Expr\Variable && \is_string($node->expr->var->name)
            ) {
                $name = $node->expr->var->name;
            }
            $class = \is_string($type) ? $this->documentedClass($type === TypeExpr::STATIC ? 'self' : $type) : null;
            if (!\is_string($name) || $name === '' || $class === null) {
                continue;
            }
            $this->localTypes[$name] = TypeExpr::named($class);
            $this->pinned[$name] = spl_object_id($node);
        }
    }

    /**
     * Last assignment read wins: there is no branch analysis. An assignment of unknown type forgets the variable.
     */
    private function assignLocal(string $name, ?TypeExpr $type): void
    {
        if (isset($this->pinned[$name])) {
            return;
        }

        if ($type === null) {
            unset($this->localTypes[$name]);
        } else {
            $this->localTypes[$name] = $type;
        }
    }

    private function forgetAssigned(?AstNode $target): void
    {
        if ($target instanceof Expr\Variable && \is_string($target->name)) {
            $this->assignLocal($target->name, null);
        } elseif ($target instanceof Expr\List_ || $target instanceof Expr\Array_) {
            foreach ($target->items as $item) {
                $this->forgetAssigned($item?->value);
            }
        }
    }

    private function onExpression(AstNode $node): void
    {
        if ($node instanceof Expr\New_ && $node->class instanceof Name) {
            $this->link($this->owner(), $this->resolveName($node->class), Relation::Instantiates);

            return;
        }

        if (($node instanceof Expr\StaticCall || $node instanceof Expr\MethodCall) && $node->name instanceof Identifier) {
            $this->onPossibleRoute($node);
            $this->onPossibleRequest($node);
        }

        if ($node instanceof Expr\MethodCall && $node->name instanceof Identifier) {
            $this->onPossibleServiceDefinition($node);
        }

        if ($node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall || $node instanceof Expr\FuncCall) {
            $this->onPossibleListenerRegistration($node);
        }

        if ($node instanceof Expr\StaticCall && $node->class instanceof Name) {
            $class = $this->resolveName($node->class);
            if ($class === null) {
                return;
            }
            if ($node->name instanceof Identifier) {
                $this->onPossibleSend($node->name->toString(), $node->args, null, false, $class);
            }
            if ($node->name instanceof Identifier && $this->currentCallable !== null) {
                $this->pending[] = new PendingCall($this->currentCallable, TypeExpr::named($class), $node->name->toString(), true);
            } else {
                $this->reference($this->owner(), $class);
            }

            return;
        }

        if (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) && $node->name instanceof Identifier) {
            if ($this->currentCallable !== null) {
                $this->pending[] = new PendingCall(
                    $this->currentCallable,
                    $this->typeOf($node->var),
                    $node->name->toString(),
                    false,
                );
            }
            $this->onPossibleSend(
                $node->name->toString(),
                $node->args,
                $this->typeOf($node->var),
                $node->var instanceof Expr\Variable && $node->var->name === 'this',
            );

            return;
        }

        // ($this->placeOrder)() and $handler($command): a call to __invoke on an invokable object. Only when the object
        // is typed: an untyped $callback() is most often a closure or a callable string, not a method call.
        if ($node instanceof Expr\FuncCall && $node->name instanceof Expr && $this->currentCallable !== null) {
            $invoked = $this->typeOf($node->name);
            if ($invoked !== null) {
                $this->pending[] = new PendingCall($this->currentCallable, $invoked, '__invoke', false);
            }

            return;
        }

        if ($node instanceof Expr\FuncCall && $node->name instanceof Name) {
            $this->onPossibleSend($node->name->getLast(), $node->args, null, false, null, true);

            return;
        }

        if (($node instanceof Expr\ClassConstFetch || $node instanceof Expr\StaticPropertyFetch) && $node->class instanceof Name) {
            $this->reference($this->owner(), $this->resolveName($node->class));

            return;
        }

        if ($node instanceof Expr\Instanceof_ && $node->class instanceof Name) {
            $this->reference($this->owner(), $this->resolveName($node->class));
        }
    }

    /**
     * @param array<AstNode> $args
     */
    private function onPossibleSend(string $method, array $args, ?TypeExpr $receiver, bool $receiverIsThis, ?string $staticClass = null, bool $isFunction = false): void
    {
        // Scripts send messages too (bin/console-like entry points, examples): the file is then the sender.
        if ($this->currentCallable === null && $this->currentClass !== null) {
            return;
        }

        // A routed send names its channel, then passes the payload: sendWithRouting('order.place', $data).
        // Only when the key is a string: a project's own publishEvent($event) is an ordinary send.
        $keyPosition = BusExtractor::ROUTING_SENDS[strtolower($method)] ?? null;
        // A named event: $dispatcher->dispatch($event, 'order.placed'), or dispatch('order.placed', $event) before Symfony 4.3.
        if (strtolower($method) === 'dispatch' && \count($args) >= 2) {
            $named = [1 => 0, 0 => 1];
            foreach ($named as $position => $payload) {
                $candidate = $this->argumentNode($args, $position);
                if ($candidate !== null && $this->stringValue($candidate->value) !== null) {
                    $first = $this->argumentNode($args, $payload);
                    $this->bus->onCall(new PendingDispatch(
                        $this->currentCallable ?? $this->fileId,
                        $method,
                        $receiver,
                        $receiverIsThis,
                        $staticClass,
                        $isFunction,
                        $first === null ? null : $this->typeOf($first->value),
                        true,
                        $this->stringValue($candidate->value),
                    ));

                    return;
                }
            }
        }
        $keyArgument = $keyPosition === null ? null : $this->argumentNode($args, $keyPosition);
        $key = $keyArgument === null ? null : $this->stringValue($keyArgument->value);
        if ($key === null) {
            $keyPosition = null;
        }
        $first = $this->argumentNode($args, $keyPosition === null ? 0 : $keyPosition + 1);
        $message = $first === null ? null : $this->typeOf($first->value);

        $this->bus->onCall(new PendingDispatch(
            $this->currentCallable ?? $this->fileId,
            $method,
            $receiver,
            $receiverIsThis,
            $staticClass,
            $isFunction,
            $message,
            $first !== null || $key !== null,
            $key,
        ));
    }

    /**
     * Symfony services declared in PHP: `$services->set('app.mailer', Mailer::class)`, `->set(Mailer::class)`,
     * `->alias('app.mailer', Mailer::class)`. Read, never run.
     */
    private function onPossibleServiceDefinition(Expr\MethodCall $node): void
    {
        if (!$node->name instanceof Identifier) {
            return;
        }

        $name = strtolower($node->name->toString());
        $first = $this->argumentNode($node->args, 0);
        $second = $this->argumentNode($node->args, 1);

        // ->set('id')->parent('abstract.id') and ->set('id', X::class)->tag('messenger.message_handler', [...]).
        if (($name === 'tag' || $name === 'parent') && $first !== null) {
            [$id, $instanceof] = $this->definedService($node->var);
            if ($name === 'parent' && $id !== null && $first->value instanceof AstNode\Scalar\String_) {
                $this->services[$id] ??= '@' . $first->value->value;
            } elseif ($name === 'tag' && ($id !== null || $instanceof !== null) && $first->value instanceof AstNode\Scalar\String_) {
                $attributes = [];
                foreach ($second?->value instanceof Expr\Array_ ? $second->value->items : [] as $item) {
                    $value = $item->value instanceof AstNode\Scalar\String_ ? $item->value->value : $this->classConstant($item->value);
                    if ($item->key instanceof AstNode\Scalar\String_ && $value !== null) {
                        $attributes[$item->key->value] = $value;
                    }
                }
                $this->serviceTags[] = ['id' => $id, 'instanceof' => $instanceof, 'name' => $first->value->value, 'attributes' => $attributes];
            }

            return;
        }

        if (($name !== 'set' && $name !== 'alias') || $first === null) {
            return;
        }

        $id = $first->value instanceof AstNode\Scalar\String_ ? $first->value->value : $this->classConstant($first->value);
        $target = $second === null ? null : ($second->value instanceof AstNode\Scalar\String_ ? $second->value->value : $this->classConstant($second->value));
        if ($id === null) {
            return;
        }

        if ($name === 'alias' && $target !== null) {
            $this->services[$id] = '@' . $target;
        } elseif ($name === 'set' && ($target !== null || str_contains($id, '\\'))) {
            $this->services[$id] = $target ?? $id;
        }
    }

    /**
     * A listener wired by code: Symfony `$dispatcher->addListener($event, $callable)`, Laravel
     * `Event::listen($event, $listener)`, WordPress `add_action($hook, $callable)` / `add_filter`.
     */
    private function onPossibleListenerRegistration(Expr\MethodCall|Expr\StaticCall|Expr\FuncCall $node): void
    {
        $name = match (true) {
            $node instanceof Expr\FuncCall => $node->name instanceof Name ? strtolower($node->name->getLast()) : null,
            $node->name instanceof Identifier => strtolower($node->name->toString()),
            default => null,
        };
        $registers = match (true) {
            $node instanceof Expr\FuncCall => \in_array($name, ['add_action', 'add_filter'], true),
            $node instanceof Expr\StaticCall => $name === 'listen' && $node->class instanceof Name
                && \in_array($this->resolveName($node->class), ['Event', 'Illuminate\\Support\\Facades\\Event'], true),
            default => $name === 'addlistener',
        };
        $event = $this->argumentNode($node->args, 0);
        $listener = $this->argumentNode($node->args, 1);
        if (!$registers || $event === null || $listener === null) {
            return;
        }

        $message = $this->classConstant($event->value);
        $key = $message === null ? $this->stringValue($event->value) : null;
        [$handler, $method] = $this->callable($listener->value);
        if ($handler !== null) {
            $this->bus->onRegisteredListener($handler, $method, $message, $key);
        }
    }

    /**
     * What a callable names: [$this, 'm'], [__CLASS__, 'm'], [Listener::class, 'm'], 'Listener::m', 'a_function',
     * Listener::class or new Listener() (its handle() or __invoke()), [$typed, 'm']. Closures name nothing.
     *
     * @return array{?string, ?string} the class (or function) and the method id, null for handle()/__invoke()
     */
    private function callable(Expr $callable): array
    {
        if ($callable instanceof Expr\Array_ && \count($callable->items) === 2) {
            $target = $callable->items[0]->value;
            $method = $callable->items[1]->value;
            if (!$method instanceof AstNode\Scalar\String_) {
                return [null, null];
            }
            $class = match (true) {
                $target instanceof AstNode\Scalar\MagicConst\Class_ => $this->currentClass,
                $target instanceof AstNode\Scalar\String_ => ltrim($target->value, '\\'),
                $target instanceof Expr\ClassConstFetch => $this->classConstant($target),
                default => $this->typeOf($target)?->className,
            };

            return $class === null ? [null, null] : [$class, $class . '::' . $method->value];
        }

        if ($callable instanceof AstNode\Scalar\String_) {
            if (str_contains($callable->value, '::')) {
                [$class, $method] = explode('::', ltrim($callable->value, '\\'), 2);

                return [$class, $class . '::' . $method];
            }
            if (preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/', $callable->value) === 1) {
                return [ltrim($callable->value, '\\'), ltrim($callable->value, '\\')];
            }

            return [null, null];
        }

        $class = $this->classConstant($callable) ?? ($callable instanceof Expr\New_ && $callable->class instanceof Name ? $this->resolveName($callable->class) : null);

        return [$class, null];
    }

    /**
     * The service a configurator chain defines: [id, null] for ->set('id', ...), [null, type] for ->instanceof(Type::class).
     *
     * @return array{?string, ?string}
     */
    private function definedService(Expr $chain): array
    {
        for (; $chain instanceof Expr\MethodCall; $chain = $chain->var) {
            if (!$chain->name instanceof Identifier) {
                continue;
            }
            $argument = $this->argumentNode($chain->args, 0)?->value;
            $value = $argument instanceof AstNode\Scalar\String_ ? $argument->value : ($argument === null ? null : $this->classConstant($argument));
            $name = strtolower($chain->name->toString());
            if ($name === 'set') {
                return [$value, null];
            }
            if ($name === 'instanceof') {
                return [null, $value];
            }
        }

        return [null, null];
    }

    /**
     * Symfony event subscribers: `return [OrderPlaced::class => 'onOrderPlaced', 'kernel.request' => ['onRequest', 10]]`.
     * Read from the code, never called.
     */
    private function onSubscribedEvents(Stmt\ClassMethod $method): void
    {
        $return = null;
        foreach ($method->stmts ?? [] as $statement) {
            if ($statement instanceof Stmt\Return_ && $statement->expr instanceof Expr\Array_) {
                $return = $statement->expr;
                break;
            }
        }

        foreach ($return === null ? [] : $return->items as $item) {
            if ($item->key === null) {
                continue;
            }
            $class = $this->classConstant($item->key);
            $key = $class === null ? $this->stringValue($item->key) : null;
            if ($class === null && $key === null) {
                continue;
            }

            // 'method', ['method', priority], or [['method', priority], ['other']].
            $methods = [];
            if ($item->value instanceof AstNode\Scalar\String_) {
                $methods[] = $item->value->value;
            } elseif ($item->value instanceof Expr\Array_) {
                foreach ($item->value->items as $index => $listener) {
                    if ($listener->value instanceof AstNode\Scalar\String_ && $index === 0) {
                        $methods[] = $listener->value->value;
                        break;
                    }
                    $nested = $listener->value instanceof Expr\Array_ ? ($listener->value->items[0] ?? null)?->value : null;
                    if ($nested instanceof AstNode\Scalar\String_) {
                        $methods[] = $nested->value;
                    }
                }
            }

            foreach ($methods as $name) {
                $this->bus->onConfiguredHandler((string) $this->currentClass, $this->currentClass . '::' . $name, $class, $key);
            }
        }
    }

    /**
     * Laravel route definitions, and the path prefix of a route group: Route::prefix('admin')->group(fn () => ...),
     * Route::group(['prefix' => 'admin'], fn () => ...).
     */
    private function onPossibleRoute(Expr\StaticCall|Expr\MethodCall $node): void
    {
        if (!$node->name instanceof Identifier || !$this->isLaravelRouteChain($node)) {
            return;
        }

        $name = strtolower($node->name->toString());
        if ($name === 'group') {
            $prefix = '';
            $first = $this->argumentNode($node->args, 0);
            if ($first !== null && $first->value instanceof Expr\Array_) {
                foreach ($first->value->items as $item) {
                    if ($item->key instanceof AstNode\Scalar\String_ && $item->key->value === 'prefix' && $item->value instanceof AstNode\Scalar\String_) {
                        $prefix = $item->value->value;
                    }
                }
            }
            for ($call = $node; $call instanceof Expr\MethodCall || $call instanceof Expr\StaticCall; $call = $call instanceof Expr\MethodCall ? $call->var : null) {
                $argument = $this->argumentNode($call->args, 0);
                if ($call->name instanceof Identifier && strtolower($call->name->toString()) === 'prefix' && $argument?->value instanceof AstNode\Scalar\String_) {
                    $prefix = $argument->value->value . '/' . $prefix;
                }
            }
            $this->routeGroups[] = spl_object_id($node);
            $this->http->pushPrefix(trim($prefix, '/'));

            return;
        }

        $this->http->onLaravelRoute($name, $node->args, $node->getStartLine(), fn (Name $class): ?string => $this->resolveName($class));
    }

    /**
     * An HTTP call: on an HTTP client, through Laravel's Http facade, or to an absolute URL whatever the receiver.
     */
    private function onPossibleRequest(Expr\StaticCall|Expr\MethodCall $node): void
    {
        if (!$node->name instanceof Identifier || $this->isLaravelRouteChain($node)) {
            return;
        }

        $arguments = RouteExtractor::requestArguments($node->name->toString(), $node->args);
        $url = $arguments === null ? null : RouteExtractor::urlPattern($arguments[1]);
        if ($arguments === null || $url === null) {
            return;
        }

        $root = $this->chainRoot($node);
        $this->http->onRequest(new PendingRequest(
            $this->currentCallable ?? $this->fileId,
            $node instanceof Expr\MethodCall && !$root instanceof Expr\StaticCall ? $this->typeOf($node->var) : null,
            $root instanceof Expr\StaticCall && $root->class instanceof Name ? $this->resolveName($root->class) : null,
            $arguments[0],
            $url['path'],
            $url['literal'],
            $url['absolute'],
        ));
    }

    private function isLaravelRouteChain(Expr\StaticCall|Expr\MethodCall $node): bool
    {
        $root = $this->chainRoot($node);
        if (!$root instanceof Expr\StaticCall || !$root->class instanceof Name) {
            return false;
        }

        $class = $this->resolveName($root->class);

        return $class === 'Route' || $class === 'Illuminate\Support\Facades\Route';
    }

    /**
     * The first call of a chain: Route::middleware('auth') in Route::middleware('auth')->get(...).
     */
    private function chainRoot(Expr $node): Expr
    {
        while ($node instanceof Expr\MethodCall && ($node->var instanceof Expr\MethodCall || $node->var instanceof Expr\StaticCall)) {
            $node = $node->var;
        }

        return $node;
    }

    /**
     * @param array<AstNode\AttributeGroup> $groups
     *
     * @return list<array{name: string, path: ?string, methods: list<string>}>
     */
    private function routeAttributes(array $groups): array
    {
        return array_values(array_filter($this->attributes($groups), fn (array $attribute): bool => $this->isRouteAttribute($attribute['name'])));
    }

    private function isRouteAttribute(string $name): bool
    {
        return $name === 'Route' || str_ends_with($name, '\Route');
    }

    /**
     * @param array<AstNode> $args
     */
    private function argumentNode(array $args, int $position): ?AstNode\Arg
    {
        $argument = $args[$position] ?? null;

        return $argument instanceof AstNode\Arg && !$argument->unpack ? $argument : null;
    }

    /**
     * A string literal, or a class constant that may hold one: `const:Class::NAME`, resolved by the builder.
     */
    private function stringValue(Expr $expr): ?string
    {
        if ($expr instanceof AstNode\Scalar\String_) {
            return $expr->value;
        }

        if ($expr instanceof Expr\ClassConstFetch && $expr->class instanceof Name && $expr->name instanceof Identifier) {
            $class = $this->resolveName($expr->class);

            return $class === null ? null : 'const:' . $class . '::' . $expr->name->toString();
        }

        return null;
    }

    /**
     * Attribute names and the arguments the bus detection reads: `handles: Message::class`, `method: 'onMessage'`.
     *
     * @param array<AstNode\AttributeGroup> $groups
     *
     * @return list<array{name: string, handles: ?string, method: ?string, key: ?string, path: ?string, methods: list<string>}>
     */
    private function attributes(array $groups): array
    {
        $attributes = [];
        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                $handles = $method = $key = $path = null;
                $methods = [];
                foreach ($attribute->args as $position => $argument) {
                    $name = $argument->name?->toString();
                    if (($name === null && $position === 0) || $name === 'path') {
                        $path = $argument->value instanceof AstNode\Scalar\String_ ? $argument->value->value : $path;
                    }
                    if ($name === 'methods') {
                        $values = $argument->value instanceof Expr\Array_ ? array_map(static fn ($item) => $item->value, $argument->value->items) : [$argument->value];
                        foreach ($values as $value) {
                            if ($value instanceof AstNode\Scalar\String_) {
                                $methods[] = strtoupper($value->value);
                            }
                        }
                    } elseif ($name === 'handles' && $argument->value instanceof Expr\ClassConstFetch && $argument->value->class instanceof Name) {
                        $handles = $this->resolveName($argument->value->class);
                    } elseif ($name === 'method' && $argument->value instanceof AstNode\Scalar\String_) {
                        $method = $argument->value->value;
                    } elseif (($name === null && $position === 0) || $name === 'routingKey' || $name === 'listenTo') {
                        $key = $this->stringValue($argument->value);
                    }
                }
                $attributes[] = [
                    'name' => (string) $this->resolveName($attribute->name),
                    'handles' => $handles,
                    'method' => $method,
                    'key' => $key,
                    'path' => $path,
                    'methods' => $methods,
                ];
            }
        }

        return $attributes;
    }

    /**
     * Laravel's `protected $listen = [OrderShipped::class => [SendShipmentNotification::class]]`.
     *
     * @return array<string, list<string>>
     */
    private function listenMap(Stmt\Class_ $node): array
    {
        $map = [];
        foreach ($node->stmts as $statement) {
            if (!$statement instanceof Stmt\Property) {
                continue;
            }
            foreach ($statement->props as $property) {
                if ($property->name->toString() !== 'listen' || !$property->default instanceof Expr\Array_) {
                    continue;
                }
                foreach ($property->default->items as $item) {
                    $event = $this->classConstant($item->key);
                    if ($event === null || !$item->value instanceof Expr\Array_) {
                        continue;
                    }
                    foreach ($item->value->items as $listener) {
                        $class = $this->classConstant($listener->value);
                        if ($class !== null) {
                            $map[$event][] = $class;
                        }
                    }
                }
            }
        }

        return $map;
    }

    private function classConstant(?Expr $expr): ?string
    {
        return $expr instanceof Expr\ClassConstFetch && $expr->class instanceof Name && $expr->name instanceof Identifier
            && strtolower($expr->name->toString()) === 'class'
            ? $this->resolveName($expr->class)
            : null;
    }

    /**
     * The type of an expression, possibly depending on other files: chains of calls and property fetches.
     */
    private function typeOf(Expr $expr): ?TypeExpr
    {
        if ($expr instanceof Expr\Variable && \is_string($expr->name)) {
            if ($expr->name === 'this') {
                return $this->currentClass === null ? null : TypeExpr::named($this->currentClass);
            }

            return $this->localTypes[$expr->name] ?? null;
        }

        if (($expr instanceof Expr\PropertyFetch || $expr instanceof Expr\NullsafePropertyFetch) && $expr->name instanceof Identifier) {
            $property = $expr->name->toString();
            if ($expr->var instanceof Expr\Variable && $expr->var->name === 'this' && isset($this->propertyTypes[$property])) {
                return TypeExpr::named($this->propertyTypes[$property]);
            }
            $receiver = $this->typeOf($expr->var);

            return $receiver === null ? null : TypeExpr::propertyOf($receiver, $property);
        }

        if (($expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall) && $expr->name instanceof Identifier) {
            $receiver = $this->typeOf($expr->var);

            return $receiver === null ? null : TypeExpr::returnOf($receiver, $expr->name->toString());
        }

        if ($expr instanceof Expr\StaticCall && $expr->class instanceof Name && $expr->name instanceof Identifier) {
            $class = $this->resolveName($expr->class);

            return $class === null ? null : TypeExpr::returnOf(TypeExpr::named($class), $expr->name->toString());
        }

        if ($expr instanceof Expr\New_ && $expr->class instanceof Name) {
            $class = $this->resolveName($expr->class);

            return $class === null ? null : TypeExpr::named($class);
        }

        if ($expr instanceof Expr\Clone_) {
            return $this->typeOf($expr->expr);
        }

        if ($expr instanceof Expr\Assign) {
            return $this->typeOf($expr->expr);
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function collectPropertyTypes(Stmt\ClassLike $node): array
    {
        $types = [];

        foreach ($node->stmts as $statement) {
            if ($statement instanceof Stmt\Property) {
                $type = $this->singleType($statement->type) ?? $this->documentedPropertyType($statement);
                if ($type === null) {
                    continue;
                }
                foreach ($statement->props as $property) {
                    $types[$property->name->toString()] = $type;
                }
            } elseif ($statement instanceof Stmt\ClassMethod && strtolower($statement->name->toString()) === '__construct') {
                foreach ($statement->params as $param) {
                    $type = $this->singleType($param->type);
                    if ($param->flags !== 0 && $type !== null && $param->var instanceof Expr\Variable && \is_string($param->var->name)) {
                        $types[$param->var->name] = $type;
                    }
                }
            }
        }

        return $types;
    }

    private function documentedPropertyType(Stmt\Property $property): ?string
    {
        $types = $property->getAttribute(DocTypeResolver::VAR_TYPES);
        $type = \is_array($types) ? ($types[''] ?? reset($types)) : null;
        if (!\is_string($type)) {
            return null;
        }

        return $this->documentedClass($type === TypeExpr::STATIC ? 'self' : $type);
    }

    private function singleType(?AstNode $type): ?string
    {
        $names = $this->typeNames($type);

        return \count($names) === 1 ? $names[0] : null;
    }

    /**
     * @return list<string>
     */
    private function typeNames(?AstNode $type): array
    {
        if ($type instanceof Name) {
            $name = $this->resolveName($type);

            return $name === null ? [] : [$name];
        }

        if ($type instanceof NullableType) {
            return $this->typeNames($type->type);
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            $names = [];
            foreach ($type->types as $inner) {
                array_push($names, ...$this->typeNames($inner));
            }

            return $names;
        }

        return [];
    }

    private function resolveName(Name $name): ?string
    {
        if ($name->isSpecialClassName()) {
            return match (strtolower($name->toString())) {
                'self', 'static' => $this->currentClass,
                'parent' => $this->currentParent,
                default => null,
            };
        }

        return $name->toString();
    }

    private function owner(): string
    {
        return $this->currentCallable ?? $this->currentClass ?? $this->fileId;
    }

    private function reference(string $source, ?string $target): void
    {
        if ($target === null || $target === $this->currentClass) {
            return;
        }

        $this->link($source, $target, Relation::References);
    }

    private function link(string $source, ?string $target, Relation $relation): void
    {
        if ($target === null || $source === $target) {
            return;
        }

        $this->edges[] = new Edge($source, $target, $relation, Confidence::Extracted);
    }

    private function shortName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');

        return $position === false ? $fqcn : substr($fqcn, $position + 1);
    }
}
