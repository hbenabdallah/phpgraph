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
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

final class ExtractionVisitor extends NodeVisitorAbstract
{
    /**
     * Methods a strategy declares what it accepts with, lowercase: `supports(object $input): bool`.
     */
    private const GUARDS = ['supports', 'canhandle', 'accepts', 'canprocess', 'issupported'];

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

    /** @var list<array{id: string, tag: ?string, service: ?string, locator?: bool}> */
    private array $serviceArguments = [];

    /** @var array<string, array{reads: array<string, true>, writes: array<string, true>, constants?: array<string, true>}> method id => properties of $this, and the class constants it uses */
    private array $stateAccess = [];

    /** @var array<int, true> property fetches being written, not read: `$this->items[] = $item` */
    private array $writtenFetches = [];

    private readonly ConfigurationHelpers $configuration;

    /** @var list<array{string, TypeExpr}> */
    private array $propertyReads = [];

    /** @var array<string, string> */
    private array $returnElements = [];

    /** @var array<string, string> property of the current class => class of the elements it holds (`@var Rule[]`) */
    private array $propertyElements = [];

    /** @var array<string, list<string>> */
    private array $templates = [];

    /** @var array<string, array<string, list<string>>> */
    private array $parentArguments = [];

    /** @var array<string, string> */
    private array $genericReturns = [];

    /** @var array<string, string> */
    private array $genericProperties = [];

    /** @var array<string, string> property of the current class => its GenericType (`@var Collection<int, Item>`) */
    private array $propertyGenerics = [];

    /** @var array<string, string> parameter of the current callable => class of its elements (`@param Rule[] $rules`) */
    private array $parameterElements = [];

    private readonly bool $inConfigurationDirectory;

    /** @var array<int, array{int, int|string}> closure passed as an argument => the call's index, the parameter */
    private array $closureArguments = [];

    /** @var list<array{int, int|string}|null> the closures being read, innermost last */
    private array $closures = [];

    /** @var array<string, int> parameters of the current callable => their position */
    private array $parameters = [];

    /** @var array<string, list<array{int, string}>> */
    private array $invokedParameters = [];

    /** @var array<string, array{method: string, types: list<string>|true|null}> */
    private array $guards = [];

    /** @var list<array{string, string, int, list<?string>, list<?string>}> */
    private array $callArguments = [];

    /** @var array<string, list<string>> */
    private array $propertyHolds = [];

    /** @var array<string, string> parameters of the current callable => their class in `@param` */
    private array $documentedParameters = [];

    /** @var array<string, true> variables of the current callable assigned after its start */
    private array $reassigned = [];

    /** @var list<array<string, true>> the parameters of the method still visible in each closure being read, innermost last */
    private array $visibleParameters = [];

    /** @var array<string, string> */
    private array $methodParameters = [];

    /** @var int the first of the current callable's entries in $parameterPasses */
    private int $passesStart = 0;

    /** @var list<array{string, string, TypeExpr, string, int|string}> */
    private array $parameterPasses = [];

    public function __construct(private readonly string $path)
    {
        $this->fileId = 'file:' . $path;
        $this->bus = new BusExtractor();
        $this->configuration = new ConfigurationHelpers(fn (Name $name): ?string => $this->resolveName($name));
        $this->inConfigurationDirectory = preg_match('#(^|/)config/#', $path) === 1;
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
            $this->serviceArguments,
            $this->http->loaderPrefixes(),
            array_map(static fn (array $access): array => [
                'reads' => array_map('strval', array_keys($access['reads'])),
                'writes' => array_map('strval', array_keys($access['writes'])),
                'constants' => array_map('strval', array_keys($access['constants'] ?? [])),
            ], $this->stateAccess),
            $this->configuration->helpers(),
            $this->configuration->calls(),
            $this->propertyReads,
            $this->returnElements,
            $this->invokedParameters,
            $this->guards,
            $this->callArguments,
            $this->propertyHolds,
            $this->methodParameters,
            $this->parameterPasses,
            $this->templates,
            $this->parentArguments,
            $this->genericReturns,
            $this->genericProperties,
        );
    }

    /**
     * @return int|null
     */
    public function enterNode(AstNode $node)
    {
        if ($this->currentCallable !== null && $this->currentClass !== null && str_contains($this->currentCallable, '::')) {
            $this->onStateAccess($node, $this->currentCallable);
        }

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

        if ($node instanceof Stmt\Expression && $node->expr instanceof Expr\MethodCall) {
            $this->onPossibleRoutingConfigurator($node->expr);
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
            // `foreach ($this->rules as $rule)` over a collection typed in a docblock: `$rule` is a Rule.
            $element = $this->elementOf($node->expr);
            if ($element !== null && $node->valueVar instanceof Expr\Variable && \is_string($node->valueVar->name)) {
                $this->assignLocal($node->valueVar->name, \is_string($element) ? TypeExpr::named($element) : $element);
            }

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
                $this->reassigned[$node->var->name] = true;
                $this->assignLocal($node->var->name, $this->typeOf($node->expr));
            } else {
                $this->forgetAssigned($node->var);
            }
        } elseif ($node instanceof Expr\AssignOp) {
            $this->forgetAssigned($node->var);
        }

        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $this->localTypes = array_pop($this->outerScopes) ?? [];
            array_pop($this->closures);
            array_pop($this->visibleParameters);
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
            $this->configuration->leaveCallable();
            $this->keepUsefulPasses();
            $this->parameterElements = [];
            $this->documentedParameters = [];
            $this->reassigned = [];
            $this->visibleParameters = [];
            $this->parameters = [];
            $this->closures = [];
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
        foreach ($node->stmts as $statement) {
            if ($statement instanceof Stmt\ClassConst) {
                foreach ($statement->consts as $constant) {
                    if ($constant->value instanceof AstNode\Scalar\String_) {
                        $this->constants[$fqcn . '::' . $constant->name->toString()] = $constant->value->value;
                    }
                }
            }
        }
        // After the constants: #[Route(self::PATH)] reads one.
        $this->classAttributes = $this->attributes($node->attrGroups);
        if ($node instanceof Stmt\Class_) {
            $resources = new ApiPlatformResources($this->path, fn (Name $name): ?string => $this->resolveName($name), $this->pathValue(...));
            foreach ($resources->routes($fqcn, $node->attrGroups) as $route) {
                $this->http->addRoute($route);
            }
        }
        // #[AutoconfigureTag('app.rule')]: the class, or every class implementing the interface, gets the tag.
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $tag = $this->argumentNode($attribute->args, 0)?->value;
                if (str_ends_with((string) $this->resolveName($attribute->name), 'AutoconfigureTag') && $tag instanceof AstNode\Scalar\String_) {
                    $this->serviceTags[] = ['id' => null, 'instanceof' => $fqcn, 'name' => $tag->value, 'attributes' => []];
                }
            }
        }
        $this->propertyTypes = $this->collectPropertyTypes($node);
        $this->propertyElements = $this->collectPropertyElements($node);
        $this->propertyGenerics = $this->collectPropertyGenerics($node);
        foreach ($this->propertyGenerics as $property => $type) {
            $this->genericProperties[$fqcn . '::' . $property] = $type;
        }
        $templates = $node->getAttribute(DocTypeResolver::TEMPLATES);
        if (\is_array($templates) && $templates !== []) {
            $this->templates[$fqcn] = array_values(array_filter($templates, 'is_string'));
        }
        $parentArguments = $node->getAttribute(DocTypeResolver::PARENT_ARGUMENTS);
        if (\is_array($parentArguments) && $parentArguments !== []) {
            /** @var array<string, list<string>> $parentArguments */
            $this->parentArguments[$fqcn] = $parentArguments;
        }
        $holds = $this->collectPropertyHolds($node);
        if ($holds !== []) {
            $this->propertyHolds[$fqcn] = $holds;
        }
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
        $this->parameters = [];
        foreach ($node->params as $position => $param) {
            if ($param->var instanceof Expr\Variable && \is_string($param->var->name)) {
                $this->parameters[$param->var->name] = $position;
            }
        }
        $elements = $node->getAttribute(DocTypeResolver::ELEMENT_TYPES);
        $this->parameterElements = \is_array($elements) ? array_filter($elements, 'is_string') : [];
        if ($kind === NodeKind::Method) {
            // `name:Class,other:` kept as a string: one per method of the project, most of them never looked at.
            $types = array_map(fn (AstNode\Param $param): string => (string) $this->singleType($param->type), $node->params);
            if (array_filter($types) !== []) {
                $this->methodParameters[$id] = implode(',', array_map(
                    static fn (AstNode\Param $param, string $type): string => ($param->var instanceof Expr\Variable && \is_string($param->var->name) ? $param->var->name : '') . ':' . $type,
                    $node->params,
                    $types,
                ));
            }
            $this->passesStart = \count($this->parameterPasses);
        }
        $documented = $node->getAttribute(DocTypeResolver::PARAM_TYPES);
        $this->documentedParameters = \is_array($documented) ? array_map(fn (string $type): string => (string) $this->documentedClass($type), array_filter($documented, 'is_string')) : [];
        $this->reassigned = [];
        $this->configuration->enterCallable($id, $node->params, array_values(array_map(fn (AstNode\Param $param): ?string => $this->singleType($param->type), $node->params)));
        $this->localTypes = [];
        $this->pinned = [];
        $this->outerScopes = [];

        foreach ($node->params as $param) {
            foreach ($this->typeNames($param->type) as $type) {
                $this->reference($id, $type);
            }
        }
        $generics = $node->getAttribute(DocTypeResolver::GENERICS);
        $this->typeParameters($node->params, \is_array($generics) ? array_filter($generics, 'is_string') : []);

        foreach ($this->typeNames($node->returnType) as $type) {
            $this->reference($id, $type);
        }

        if ($kind === NodeKind::Method && $this->currentClass !== null && str_starts_with(strtolower($node->name->toString()), 'supports')) {
            $this->onSupports($this->currentClass, $node);
        }
        if ($node instanceof Stmt\ClassMethod && $this->currentClass !== null && \in_array(strtolower($node->name->toString()), self::GUARDS, true)) {
            $this->onGuard($this->currentClass, $node);
        }

        if ($kind === NodeKind::Method && $this->currentClass !== null && strtolower($node->name->toString()) === '__construct') {
            $this->onInjectedParameters($this->currentClass, $node->params);
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
            $generic = $node->getAttribute(DocTypeResolver::RETURN_GENERIC);
            if (\is_string($generic)) {
                $this->genericReturns[$id] = $generic;
            }
            $element = $node->getAttribute(DocTypeResolver::RETURN_ELEMENT);
            $element = \is_string($element) ? $this->documentedClass($element) : null;
            if ($element !== null) {
                $this->returnElements[$id] = $element;
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
     * @param array<AstNode\Param>  $params
     * @param array<string, string> $generics parameter => its GenericType documented (`@param Collection<int, Item>`)
     */
    private function typeParameters(array $params, array $generics = []): void
    {
        foreach ($params as $param) {
            if ($param->var instanceof Expr\Variable && \is_string($param->var->name)) {
                $type = $this->singleType($param->type);
                $generic = $this->usableGeneric($generics[$param->var->name] ?? null);
                $this->assignLocal($param->var->name, match (true) {
                    $generic !== null => TypeExpr::generic($generic),
                    $type !== null => TypeExpr::named($type),
                    default => null,
                });
            }
        }
    }

    /**
     * A documented GenericType a variable can hold: a class or an array with arguments. A template parameter of the
     * class is left to the native type: its binding depends on the object, which the method does not see.
     */
    private function usableGeneric(?string $type): ?string
    {
        if ($type === null || str_starts_with($type, '@')) {
            return null;
        }
        [$base, $arguments] = GenericType::parse($type);

        return $arguments !== [] && ($base === GenericType::ARRAY || GenericType::isClass($base)) && !str_contains($type, TypeExpr::STATIC) ? $type : null;
    }

    private function enterClosure(Expr\Closure|Expr\ArrowFunction $node): void
    {
        $this->outerScopes[] = $this->localTypes;
        $this->closures[] = $this->closureArguments[spl_object_id($node)] ?? end($this->closures) ?: null;

        // The method's parameters a closure still sees: those it captures (all, for an arrow function), not shadowed.
        $visible = end($this->visibleParameters) ?: array_fill_keys(array_keys($this->parameters), true);
        if ($node instanceof Expr\Closure) {
            $captured = [];
            foreach ($node->uses as $use) {
                if (\is_string($use->var->name) && !$use->byRef && isset($visible[$use->var->name])) {
                    $captured[$use->var->name] = true;
                }
            }
            $visible = $captured;
        }
        foreach ($node->params as $param) {
            if ($param->var instanceof Expr\Variable && \is_string($param->var->name)) {
                unset($visible[$param->var->name]);
            }
        }
        $this->visibleParameters[] = $visible;

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
     * The closure literals passed to the call just recorded: the calls written in them may run in the callee.
     *
     * @param array<AstNode\Arg|AstNode\ArgPlaceholder|AstNode\VariadicPlaceholder> $args
     */
    private function onClosureArguments(array $args): void
    {
        foreach ($args as $position => $arg) {
            if ($arg instanceof AstNode\Arg && ($arg->value instanceof Expr\Closure || $arg->value instanceof Expr\ArrowFunction)) {
                $this->closureArguments[spl_object_id($arg->value)] = [\count($this->pending) - 1, $arg->name?->toString() ?? $position];
            }
        }
    }

    /**
     * @return array{int, int|string}|null the closure argument the current code is written in
     */
    private function closure(): ?array
    {
        return end($this->closures) ?: null;
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

        $generics = $node->getAttribute(DocTypeResolver::GENERICS);
        foreach (\is_array($generics) ? $generics : [] as $name => $type) {
            if ($name === '' && $node instanceof Stmt\Expression && $node->expr instanceof Expr\Assign
                && $node->expr->var instanceof Expr\Variable && \is_string($node->expr->var->name)
            ) {
                $name = $node->expr->var->name;
            }
            $type = \is_string($type) ? $this->usableGeneric($type) : null;
            if (\is_string($name) && $name !== '' && $type !== null) {
                $this->localTypes[$name] = TypeExpr::generic($type);
                $this->pinned[$name] = spl_object_id($node);
            }
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
            $this->reassigned[$target->name] = true;
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

        // `$violation->type` on a typed object: the builder links the method to the property's type when it is an enum.
        if (($node instanceof Expr\PropertyFetch || $node instanceof Expr\NullsafePropertyFetch) && $node->name instanceof Identifier
            && $this->currentCallable !== null && $this->thisProperty($node) === null) {
            $receiver = $this->typeOf($node->var);
            if ($receiver !== null) {
                $this->propertyReads[] = [$this->currentCallable, TypeExpr::propertyOf($receiver, $node->name->toString())];
            }
        }

        if ($node instanceof Expr\MethodCall && $node->name instanceof Identifier) {
            $this->onPossibleServiceDefinition($node);
            $this->configuration->onMethodCall($node);
        }
        // A configuration helper may be called: Wiring::wire($services, 'sales_order', ...).
        if ($node instanceof Expr\StaticCall && $node->class instanceof Name && $node->name instanceof Identifier) {
            $class = $this->resolveName($node->class);
            if ($class !== null) {
                $this->configuration->onCall($class . '::' . $node->name->toString(), $node->args, $this->inConfigurationDirectory, $node->getStartLine());
            }
        } elseif ($node instanceof Expr\FuncCall && $node->name instanceof Name) {
            $this->configuration->onCall(ltrim($node->name->toString(), '\\'), $node->args, $this->inConfigurationDirectory, $node->getStartLine());
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
            // Outside any method (a configuration file's closure, a script), the file calls it: Wiring::wire($services, ...).
            $caller = $this->currentCallable ?? ($this->currentClass === null ? $this->fileId : null);
            if ($node->name instanceof Identifier && $this->currentCallable !== null) {
                $this->onPassedParameters($this->currentCallable, TypeExpr::named($class), $node->name->toString(), $node->args);
            }
            if ($node->name instanceof Identifier && $caller !== null) {
                $this->pending[] = new PendingCall($caller, TypeExpr::named($class), $node->name->toString(), true, $node->getStartLine(), $this->namedArguments($node->args), $this->closure());
                $this->onClosureArguments($node->args);
            } else {
                $this->reference($this->owner(), $class);
            }

            return;
        }

        if (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) && $node->name instanceof Identifier) {
            if ($this->currentCallable !== null && $this->thisProperty($node->var) !== null) {
                $this->onServiceCallArguments($this->currentCallable, $node->name->toString(), $node->getStartLine(), $node->args);
            }
            $receiver = $this->currentCallable === null ? null : $this->typeOf($node->var);
            if ($receiver !== null) {
                $this->onPassedParameters((string) $this->currentCallable, $receiver, $node->name->toString(), $node->args);
            }
            if ($this->currentCallable !== null) {
                $this->pending[] = new PendingCall(
                    $this->currentCallable,
                    $this->typeOf($node->var),
                    $node->name->toString(),
                    false,
                    $node->getStartLine(),
                    $this->namedArguments($node->args),
                    $this->closure(),
                );
                $this->onClosureArguments($node->args);
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
            // $apply(...) on a parameter: a closure passed to this method runs here.
            if ($node->name instanceof Expr\Variable && \is_string($node->name->name) && isset($this->parameters[$node->name->name])) {
                $invoked = [$this->parameters[$node->name->name], $node->name->name];
                if (!\in_array($invoked, $this->invokedParameters[$this->currentCallable] ?? [], true)) {
                    $this->invokedParameters[$this->currentCallable][] = $invoked;
                }
            }
            $invoked = $this->typeOf($node->name);
            if ($invoked !== null) {
                $this->pending[] = new PendingCall($this->currentCallable, $invoked, '__invoke', false, $node->getStartLine());
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

        // ->args([tagged_iterator('app.rule')]), ->arg('$mailer', service('app.mailer')), ->call('setLogger', [service('logger')]).
        if (\in_array($name, ['args', 'arg', 'call'], true)) {
            [$id] = $this->definedService($node->var);
            $values = $name === 'args' ? [$first] : [$second];
            $decorated = $this->decoratedService($node->var);
            foreach ($id === null ? [] : $values as $value) {
                foreach ($value === null ? [] : $this->injectedValues($value->value) as $injected) {
                    // A decorator receives the service it decorates: `->decorate(Repository::class)->args([service('.inner')])`.
                    $target = $injected['service'];
                    if ($decorated !== null && ($target === '.inner' || $target === $id . '.inner')) {
                        $target = $decorated;
                    }
                    $this->serviceArguments[] = ['id' => (string) $id, 'tag' => $injected['tag'], 'service' => $target, 'locator' => $injected['locator'] ?? false];
                }
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
     * What a configuration value injects: `tagged_iterator('t')`, `tagged_locator('t')` (or `['tag' => 't']`),
     * `service('id')`, and those inside arrays (`iterator([...])`, `->args([...])`).
     *
     * @return list<array{tag: ?string, service: ?string, locator?: bool}>
     */
    private function injectedValues(Expr $value): array
    {
        if ($value instanceof Expr\Array_) {
            $injected = [];
            foreach ($value->items as $item) {
                array_push($injected, ...$this->injectedValues($item->value));
            }

            return $injected;
        }
        if (!$value instanceof Expr\FuncCall || !$value->name instanceof Name) {
            return [];
        }

        $function = strtolower($value->name->getLast());
        $argument = $this->argumentNode($value->args, 0)?->value;
        if ($function === 'iterator' && $argument !== null) {
            return $this->injectedValues($argument);
        }
        $string = $argument === null ? null : ($this->stringValue($argument) ?? $this->classConstant($argument));
        if ($argument instanceof Expr\Array_) {
            foreach ($argument->items as $item) {
                if ($item->key instanceof AstNode\Scalar\String_ && $item->key->value === 'tag') {
                    $string = $this->stringValue($item->value);
                }
            }
        }
        if ($string === null || str_starts_with($string, 'const:')) {
            return [];
        }

        return match ($function) {
            'tagged_iterator' => [['tag' => $string, 'service' => null]],
            'tagged_locator' => [['tag' => $string, 'service' => null, 'locator' => true]],
            'service' => [['tag' => null, 'service' => $string]],
            default => [],
        };
    }

    /**
     * Constructor parameters the container fills from attributes: `#[AutowireIterator('app.rule')]`,
     * `#[TaggedIterator('app.rule')]`, the locator variants, `#[Autowire(service: 'app.mailer')]`.
     *
     * @param array<AstNode\Param> $params
     */
    private function onInjectedParameters(string $class, array $params): void
    {
        foreach ($params as $param) {
            foreach ($param->attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    $name = (string) $this->resolveName($attribute->name);
                    $short = substr($name, (int) strrpos('\\' . $name, '\\'));
                    foreach ($attribute->args as $position => $argument) {
                        $key = $argument->name?->toString();
                        $value = $argument->value instanceof AstNode\Scalar\String_ ? $argument->value->value : $this->classConstant($argument->value);
                        if ($value === null) {
                            continue;
                        }
                        if (\in_array($short, ['AutowireIterator', 'TaggedIterator', 'AutowireLocator', 'TaggedLocator'], true) && ($key === 'tag' || ($key === null && $position === 0))) {
                            $this->serviceArguments[] = ['id' => $class, 'tag' => $value, 'service' => null, 'locator' => str_ends_with($short, 'Locator')];
                        } elseif ($short === 'Autowire' && $key === 'service') {
                            $this->serviceArguments[] = ['id' => $class, 'tag' => null, 'service' => $value];
                        }
                    }
                }
            }
        }
    }

    /**
     * A guard method, `supports(object $input): bool`, and what it accepts. Only the plain forms are read: `return
     * $input instanceof A;`, a `||` of such tests, or `return true;`. Anything else (`&&`, a call, a property, several
     * statements) leaves the accepted classes unknown: whoever relies on them keeps every input.
     */
    private function onGuard(string $class, Stmt\ClassMethod $method): void
    {
        $parameter = $method->params[0] ?? null;
        $type = $parameter?->type;
        $accepts = $type === null || ($type instanceof Identifier && \in_array($type->toLowerString(), ['object', 'mixed'], true));
        $returnsBool = $method->returnType instanceof Identifier && $method->returnType->toLowerString() === 'bool';
        if ($parameter === null || $method->stmts === null || !$accepts || !$returnsBool || !$parameter->var instanceof Expr\Variable || !\is_string($parameter->var->name)) {
            return;
        }

        $types = null;
        $statement = \count($method->stmts) === 1 ? $method->stmts[0] : null;
        if ($statement instanceof Stmt\Return_ && $statement->expr instanceof Expr\ConstFetch && $statement->expr->name->toLowerString() === 'true') {
            $types = true;
        } elseif ($statement instanceof Stmt\Return_ && $statement->expr !== null) {
            $types = $this->instanceofTests($statement->expr, $parameter->var->name);
        }
        $this->guards[$class] ??= ['method' => $method->name->toString(), 'types' => $types];
    }

    /**
     * The classes of `$x instanceof A || $x instanceof B`, or null for any other expression.
     *
     * @return list<string>|null
     */
    private function instanceofTests(Expr $expr, string $variable): ?array
    {
        if ($expr instanceof Expr\BinaryOp\BooleanOr) {
            $left = $this->instanceofTests($expr->left, $variable);
            $right = $this->instanceofTests($expr->right, $variable);

            return $left === null || $right === null ? null : array_values(array_unique([...$left, ...$right]));
        }
        if ($expr instanceof Expr\Instanceof_ && $expr->expr instanceof Expr\Variable && $expr->expr->name === $variable && $expr->class instanceof Name) {
            $class = $this->resolveName($expr->class);

            return $class === null ? null : [$class];
        }

        return null;
    }

    /**
     * The classes of the positional arguments passed to a service held in a property: what a use case gives its
     * pipeline (`$this->pipeline->run($query)`), its `@param` class when the parameter is passed untouched; empty for
     * what cannot be an object to inspect, null when unknown.
     *
     * @param array<AstNode\Arg|AstNode\ArgPlaceholder|AstNode\VariadicPlaceholder> $args
     */
    private function onServiceCallArguments(string $caller, string $method, int $line, array $args): void
    {
        $types = $parameters = [];
        foreach ($args as $arg) {
            if (!$arg instanceof AstNode\Arg || $arg->name !== null || $arg->unpack) {
                break;
            }
            $value = $arg->value;
            $type = null;
            $parameter = $this->untouchedParameter($value);
            $parameters[] = $parameter;
            if ($parameter !== null) {
                $type = $this->documentedParameters[$parameter] ?? null;
            }
            $type ??= $this->typeOf($value)?->className;
            // Not an object to inspect: a closure, a literal.
            $notAnInput = $value instanceof Expr\Closure || $value instanceof Expr\ArrowFunction || $value instanceof AstNode\Scalar
                || $value instanceof Expr\Array_ || $value instanceof Expr\ConstFetch;
            $types[] = $type ?? ($notAnInput ? '' : null);
        }
        if (array_filter($types, static fn (?string $type): bool => $type !== null && $type !== '') !== [] || array_filter($parameters) !== []) {
            $this->callArguments[] = [$caller, $method, $line, $types, $parameters];
        }
    }

    /**
     * A parameter passed on untouched, `$this->builder->setUp(query: $query)`: the parameter of the callee says more
     * about it (`CreateEstimateQueryInterface` for a `QueryInterface $query`).
     *
     * @param array<AstNode\Arg|AstNode\ArgPlaceholder|AstNode\VariadicPlaceholder> $args
     */
    private function onPassedParameters(string $caller, TypeExpr $receiver, string $method, array $args): void
    {
        foreach ($args as $position => $arg) {
            if (!$arg instanceof AstNode\Arg || $arg->unpack) {
                continue;
            }
            $parameter = $this->untouchedParameter($arg->value);
            if ($parameter !== null) {
                $this->parameterPasses[] = [$caller, $parameter, $receiver, $method, $arg->name?->toString() ?? $position];
            }
        }
    }

    /**
     * Only the parameters passed on that the method also gives a held service: the input of a pipeline.
     */
    private function keepUsefulPasses(): void
    {
        $given = [];
        foreach ($this->callArguments as [$caller, , , , $parameters]) {
            if ($caller === $this->currentCallable) {
                $given += array_flip(array_filter($parameters, 'is_string'));
            }
        }
        $passes = \array_slice($this->parameterPasses, $this->passesStart);
        $this->parameterPasses = [
            ...\array_slice($this->parameterPasses, 0, $this->passesStart),
            ...array_filter($passes, static fn (array $pass): bool => isset($given[$pass[1]])),
        ];
    }

    /**
     * The name of the method's parameter an expression is, when never assigned since and seen from here.
     */
    private function untouchedParameter(Expr $value): ?string
    {
        if (!$value instanceof Expr\Variable || !\is_string($value->name) || !isset($this->parameters[$value->name]) || isset($this->reassigned[$value->name])) {
            return null;
        }
        $visible = end($this->visibleParameters);

        return $visible === false || isset($visible[$value->name]) ? $value->name : null;
    }

    /**
     * A strategy declaring what it handles: `supports(object $input): bool { return $input instanceof LineQuery; }`,
     * as rules, voters and normalizers do. The class tested is handled by this one, INFERRED: read from the shape of
     * the code, and chosen at runtime.
     */
    private function onSupports(string $class, Stmt\ClassMethod|Stmt\Function_ $method): void
    {
        $parameters = [];
        foreach ($method->params as $param) {
            if ($param->var instanceof Expr\Variable && \is_string($param->var->name)) {
                $parameters[$param->var->name] = true;
            }
        }

        foreach ((new NodeFinder())->findInstanceOf($method->stmts ?? [], Expr\Instanceof_::class) as $test) {
            if ($test->expr instanceof Expr\Variable && \is_string($test->expr->name) && isset($parameters[$test->expr->name]) && $test->class instanceof Name) {
                $handled = $this->resolveName($test->class);
                if ($handled !== null && $handled !== $class) {
                    $this->edges[] = new Edge($handled, $class, Relation::HandledBy, Confidence::Inferred);
                }
            }
        }
    }

    /**
     * A statement of a Symfony PHP routing file: a call chain on a RoutingConfigurator.
     */
    private function onPossibleRoutingConfigurator(Expr\MethodCall $chain): void
    {
        $calls = [];
        $root = $chain->var;
        for ($call = $chain; $call instanceof Expr\MethodCall; $call = $call->var) {
            if (!$call->name instanceof Identifier) {
                return;
            }
            $arguments = array_values(array_map(
                static fn (AstNode\Arg $argument): Expr => $argument->value,
                array_filter($call->args, static fn ($argument): bool => $argument instanceof AstNode\Arg && !$argument->unpack),
            ));
            array_unshift($calls, [strtolower($call->name->toString()), $arguments]);
            $root = $call->var;
        }
        $type = $this->typeOf($root);
        if ($type?->className === null || preg_match('/(Routing|Collection)Configurator$/', $type->className) !== 1) {
            return;
        }

        $this->http->onRoutingConfigurator($calls, $chain->getStartLine(), fn (Name $name): ?string => $this->resolveName($name), $this->pathValue(...));
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
     * The service a configurator chain defines: [id, null] for ->set('id', ...), [null, type] for ->instanceof(Type::class),
     * [namespace, null] for ->load('App\\Rule\\', '../src/Rule/'): an id ending with a backslash, as in YAML.
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
            if ($name === 'set' || ($name === 'load' && $value !== null && str_ends_with($value, '\\'))) {
                return [$value, null];
            }
            if ($name === 'instanceof') {
                return [null, $value];
            }
        }

        return [null, null];
    }

    /**
     * @param array<AstNode\Arg|AstNode\ArgPlaceholder|AstNode\VariadicPlaceholder> $args
     *
     * @return list<string>
     */
    private function namedArguments(array $args): array
    {
        $names = [];
        foreach ($args as $argument) {
            if ($argument instanceof AstNode\Arg && $argument->name !== null) {
                $names[] = $argument->name->toString();
            }
        }

        return $names;
    }

    /**
     * The properties of `$this` a method reads and changes, for the methods that depend on the state another one
     * writes: `$this->violations[] = $v` in add(), `count($this->violations)` in hasErrors().
     */
    private function onStateAccess(AstNode $node, string $method): void
    {
        $written = [];
        if ($node instanceof Expr\Assign || $node instanceof Expr\AssignRef || $node instanceof Expr\AssignOp) {
            $written[] = $node->var;
        } elseif ($node instanceof Stmt\Unset_) {
            $written = $node->vars;
        } elseif ($node instanceof Expr\FuncCall && $node->name instanceof Name
            && \in_array(strtolower($node->name->toString()), ['array_push', 'array_unshift', 'array_splice', 'array_pop', 'array_shift', 'sort', 'usort', 'ksort', 'uasort', 'uksort', 'asort', 'arsort', 'krsort', 'rsort', 'shuffle'], true)) {
            $written[] = $this->argumentNode($node->args, 0)?->value;
        } elseif ($node instanceof Expr\MethodCall && $node->name instanceof Identifier
            && \in_array(strtolower($node->name->toString()), ['add', 'set', 'remove', 'removeelement', 'clear', 'push', 'append', 'attach', 'detach', 'offsetset', 'offsetunset'], true)) {
            // A collection held in a property: `$this->lines->add($line)`.
            $written[] = $node->var;
        }

        foreach ($written as $target) {
            while ($target instanceof Expr\ArrayDimFetch) {
                $target = $target->var;
            }
            $property = $target instanceof Expr ? $this->thisProperty($target) : null;
            if ($property !== null) {
                $this->stateAccess[$method]['writes'][$property] = true;
                $this->stateAccess[$method]['reads'] ??= [];
                $this->writtenFetches[spl_object_id($target)] = true;
            }
        }

        // The class constants and enum cases it uses: `ViolationType::CONTEXT` tells which records a reader filters on.
        if ($node instanceof Expr\ClassConstFetch && $node->class instanceof Name && $node->name instanceof Identifier && strtolower($node->name->toString()) !== 'class') {
            $class = $this->resolveName($node->class);
            if ($class !== null) {
                $this->stateAccess[$method] ??= ['reads' => [], 'writes' => []];
                $this->stateAccess[$method]['constants'][$class . '::' . $node->name->toString()] = true;
            }
        }

        $property = $node instanceof Expr ? $this->thisProperty($node) : null;
        if ($property !== null && !isset($this->writtenFetches[spl_object_id($node)])) {
            $this->stateAccess[$method]['reads'][$property] = true;
            $this->stateAccess[$method]['writes'] ??= [];
        }
    }

    private function thisProperty(Expr $expr): ?string
    {
        return $expr instanceof Expr\PropertyFetch && $expr->var instanceof Expr\Variable && $expr->var->name === 'this' && $expr->name instanceof Identifier
            ? $expr->name->toString()
            : null;
    }

    /**
     * The service a configurator chain decorates: `->decorate(Repository::class)`.
     */
    private function decoratedService(Expr $chain): ?string
    {
        for (; $chain instanceof Expr\MethodCall; $chain = $chain->var) {
            $argument = $this->argumentNode($chain->args, 0)?->value;
            if ($chain->name instanceof Identifier && strtolower($chain->name->toString()) === 'decorate' && $argument !== null) {
                return $argument instanceof AstNode\Scalar\String_ ? $argument->value : $this->classConstant($argument);
            }
        }

        return null;
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
     * A route path: a string, a class constant (`self::ROUTE`), or a concatenation of both. A constant of another file
     * is written `{const:Class::NAME}`, for the builder to replace.
     */
    private function pathValue(Expr $expr): ?string
    {
        if ($expr instanceof Expr\BinaryOp\Concat) {
            $left = $this->pathValue($expr->left);
            $right = $this->pathValue($expr->right);

            return $left === null || $right === null ? null : $left . $right;
        }

        $value = $this->stringValue($expr);
        if ($value === null || !str_starts_with($value, 'const:')) {
            return $value;
        }

        return $this->constants[substr($value, 6)] ?? '{' . $value . '}';
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
                        $path = $this->pathValue($argument->value) ?? $path;
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
            if ($expr->var instanceof Expr\Variable && $expr->var->name === 'this' && $this->usableGeneric($this->propertyGenerics[$property] ?? null) !== null) {
                return TypeExpr::generic($this->propertyGenerics[$property]);
            }
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

    /**
     * @return array<string, string> property => class of the elements it holds, from `@var Rule[]` on the property or
     *                               `@param iterable<Rule> $rules` on a promoted constructor parameter
     */
    private function collectPropertyElements(Stmt\ClassLike $node): array
    {
        $elements = [];
        foreach ($node->stmts as $statement) {
            if ($statement instanceof Stmt\Property) {
                $types = $statement->getAttribute(DocTypeResolver::ELEMENT_TYPES);
                $class = \is_array($types) && \is_string($types[''] ?? null) ? $this->documentedClass($types['']) : null;
                foreach ($class === null ? [] : $statement->props as $property) {
                    $elements[$property->name->toString()] = (string) $class;
                }
            } elseif ($statement instanceof Stmt\ClassMethod && strtolower($statement->name->toString()) === '__construct') {
                $types = $statement->getAttribute(DocTypeResolver::ELEMENT_TYPES);
                foreach ($statement->params as $param) {
                    $name = $param->var instanceof Expr\Variable && \is_string($param->var->name) ? $param->var->name : null;
                    $class = $name !== null && \is_array($types) && \is_string($types[$name] ?? null) ? $this->documentedClass($types[$name]) : null;
                    if ($param->flags !== 0 && $name !== null && $class !== null) {
                        $elements[$name] = $class;
                    }
                }
            }
        }

        return $elements;
    }

    /**
     * @return array<string, string> property => its GenericType, from `@var` on the property or `@param` on a promoted
     *                               constructor parameter
     */
    private function collectPropertyGenerics(Stmt\ClassLike $node): array
    {
        $generics = [];
        foreach ($node->stmts as $statement) {
            if ($statement instanceof Stmt\Property) {
                $types = $statement->getAttribute(DocTypeResolver::GENERICS);
                $type = \is_array($types) && $types !== [] ? ($types[''] ?? reset($types)) : null;
                foreach (\is_string($type) ? $statement->props : [] as $property) {
                    $generics[$property->name->toString()] = (string) $type;
                }
            } elseif ($statement instanceof Stmt\ClassMethod && strtolower($statement->name->toString()) === '__construct') {
                $types = $statement->getAttribute(DocTypeResolver::GENERICS);
                foreach ($statement->params as $param) {
                    $name = $param->var instanceof Expr\Variable && \is_string($param->var->name) ? $param->var->name : null;
                    if ($param->flags !== 0 && $name !== null && \is_array($types) && \is_string($types[$name] ?? null)) {
                        $generics[$name] = $types[$name];
                    }
                }
            }
        }

        return $generics;
    }

    /**
     * The classes the properties of a class may hold: their native types (each of a union), the class or the elements
     * documented (`@var Line[]`, `@param MaterialLines $lines` on a promoted `mixed` parameter).
     *
     * @return list<string>
     */
    private function collectPropertyHolds(Stmt\ClassLike $node): array
    {
        $holds = [];
        $documented = function (mixed $types, string $name) use (&$holds): void {
            $type = \is_array($types) ? $types[$name] ?? null : null;
            if (\is_string($type) && $type !== TypeExpr::STATIC) {
                $holds[] = (string) $this->documentedClass($type);
            }
        };
        foreach ($node->stmts as $statement) {
            if ($statement instanceof Stmt\Property) {
                array_push($holds, ...$this->typeNames($statement->type));
                $varTypes = $statement->getAttribute(DocTypeResolver::VAR_TYPES);
                $documented(\is_array($varTypes) && $varTypes !== [] ? ['' => $varTypes[''] ?? reset($varTypes)] : null, '');
                $documented($statement->getAttribute(DocTypeResolver::ELEMENT_TYPES), '');
            } elseif ($statement instanceof Stmt\ClassMethod && strtolower($statement->name->toString()) === '__construct') {
                foreach ($statement->params as $param) {
                    if ($param->flags === 0 || !$param->var instanceof Expr\Variable || !\is_string($param->var->name)) {
                        continue;
                    }
                    array_push($holds, ...$this->typeNames($param->type));
                    $documented($statement->getAttribute(DocTypeResolver::PARAM_TYPES), $param->var->name);
                    $documented($statement->getAttribute(DocTypeResolver::ELEMENT_TYPES), $param->var->name);
                }
            }
        }

        return array_values(array_unique($holds));
    }

    /**
     * The class of the elements a foreach walks: a parameter or a property typed as a collection in a docblock.
     */
    private function elementOf(Expr $expr): TypeExpr|string|null
    {
        // `foreach ($notification->all() as $violation)`: resolved by the builder from `@return list<Violation>`.
        if (($expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall) && $expr->name instanceof Identifier) {
            $receiver = $this->typeOf($expr->var);

            return $receiver === null ? null : TypeExpr::elementsOf($receiver, $expr->name->toString());
        }
        if ($expr instanceof Expr\Variable && \is_string($expr->name) && isset($this->parameterElements[$expr->name])) {
            return $this->documentedClass($this->parameterElements[$expr->name]);
        }
        $property = $this->thisProperty($expr);
        if ($property !== null && isset($this->propertyElements[$property])) {
            return $this->propertyElements[$property];
        }

        // Any other typed expression: its elements are found by the builder, through the type's arguments
        // (`Collection<int, Item>`) and its parents (`@extends IteratorAggregate<TKey, T>`).
        $collection = $this->typeOf($expr);

        return $collection === null ? null : TypeExpr::elementOf($collection);
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
