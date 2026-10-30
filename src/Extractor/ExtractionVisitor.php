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

    /**
     * The types of the variables at the point being read: branches each get their own copy, merged where they meet.
     * A variable assigned an expression of unknown type holds TypeExpr::unknown(); one holding no object (`null`, a
     * scalar) or never assigned is absent.
     *
     * @var array<string, TypeExpr>
     */
    private array $localTypes = [];

    /** @var list<list<array<string, TypeExpr>>> the variables at each `break` and `continue` of the loops and switches being read, innermost last */
    private array $jumps = [];

    /** @var array<int, ?TypeExpr> ternary, `??` or match => what its value may be, read in its branches; null for no object */
    private array $values = [];

    /**
     * Variables typed by an inline `@var`, which assignments in the same statement do not override.
     *
     * @var array<string, int>
     */
    private array $pinned = [];

    /** @var list<array<string, TypeExpr>> */
    private array $outerScopes = [];

    /** @var array<int, array{array<string, TypeExpr>, array<string, int>}> function or class declared in a script => the script's variables, back after it */
    private array $scripts = [];

    /** @var array<string, string> */
    private array $returnTypes = [];

    /** @var array<string, string> */
    private array $declaredPropertyTypes = [];

    private readonly BusExtractor $bus;

    private readonly RouteExtractor $http;

    /** @var list<int> object ids of the Laravel route groups pushing a path prefix */
    private array $routeGroups = [];

    /** @var array<int, string> object id of a Laravel route's call => the name chained around it, `->name('home')` */
    private array $routeNames = [];

    /** @var list<array{name: string, handles: ?string, method: ?string, key: ?string, path: ?string, methods: list<string>, routeName: ?string}> */
    private array $classAttributes = [];

    /** @var array<string, string> */
    private array $constants = [];

    /** @var array<string, string> container service id => class or `@alias` */
    private array $services = [];

    /** @var list<array{id: ?string, instanceof: ?string, name: string, attributes: array<string, string>}> */
    private array $serviceTags = [];

    /** @var array<string, string> */
    private array $parameterTypes = [];

    /** @var array<string, string> method id => the base URL of the client it returns, `Http::baseUrl(...)` */
    private array $baseUrls = [];

    /** @var list<TypeExpr> what the file returns, by its `return` statements outside any function */
    private array $returned = [];

    /** @var array<string, string> property of the current class => the string it holds by default */
    private array $stringProperties = [];

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

    /** @var array<string, array<string, string>> class => lowercase name => method id of its `@method` methods */
    private array $documentedMethods = [];

    /** @var array<string, list<string>> */
    private array $mixins = [];

    /** @var array<string, ConditionalReturn> callable id => its conditional return type (DocTypeResolver) */
    private array $conditionalReturns = [];

    /** @var array<string, array<int|string, list<string>>> method id => the parameters of the closures it takes */
    private array $callableParameters = [];

    /** @var array<string, array<string, array{string, int}>> callable id => how a call binds its templates */
    private array $templateBinders = [];

    /** @var array<string, list<array{string, int, string}>> callable id => what a call tells of its arguments */
    private array $assertions = [];

    /** @var array<string, string> "Class::attribute" => the class an Eloquent cast gives it (`'paid_at' => 'datetime'`) */
    private array $casts = [];

    /** @var array<int, ?TypeExpr> closure passed as an argument => what it returns, once read */
    private array $closureReturns = [];

    /** @var list<int> the closures being read, innermost last */
    private array $closureNodes = [];

    /** @var array<int, list<?TypeExpr>> closure being read => the types of its return statements */
    private array $closureReturned = [];

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

    /** @var array<string, string> global variables of the current callable => their class in `@global` */
    private array $documentedGlobals = [];

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

    /**
     * The classes Eloquent's built-in casts give an attribute, by cast name.
     */
    private const CASTS = [
        'date' => 'Illuminate\\Support\\Carbon',
        'datetime' => 'Illuminate\\Support\\Carbon',
        'custom_datetime' => 'Illuminate\\Support\\Carbon',
        'immutable_date' => 'Carbon\\CarbonImmutable',
        'immutable_datetime' => 'Carbon\\CarbonImmutable',
        'immutable_custom_datetime' => 'Carbon\\CarbonImmutable',
        'collection' => 'Illuminate\\Support\\Collection',
    ];

    /**
     * The longest key of an argument type a call keeps: the types of the arguments are part of the call's.
     */
    private const ARGUMENT_KEY = 200;

    /**
     * The longest key of a type merged from branches a variable keeps.
     */
    private const MERGED_KEY = 4000;

    /**
     * Names of the calls that may assert what an argument is, looked up in their callable's docblock by the builder.
     */
    private const ASSERTING = '/^(is|assert|ensure|check|expect|validate|verify)/';

    /**
     * PHP's own tests of a value, which assert no class: not worth a lookup.
     */
    private const PHP_TESTS = [
        'is_array', 'is_bool', 'is_callable', 'is_countable', 'is_dir', 'is_double', 'is_executable', 'is_file',
        'is_finite', 'is_float', 'is_infinite', 'is_int', 'is_integer', 'is_iterable', 'is_link', 'is_long', 'is_nan',
        'is_null', 'is_numeric', 'is_object', 'is_readable', 'is_resource', 'is_scalar', 'is_string', 'is_uploaded_file',
        'is_writable', 'is_writeable', 'isset', 'is_a', 'is_subclass_of',
    ];

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
            $this->documentedMethods,
            $this->mixins,
            $this->conditionalReturns,
            $this->callableParameters,
            $this->templateBinders,
            $this->casts,
            $this->baseUrls,
            $this->assertions,
            $this->returned === [] ? null : TypeExpr::union($this->returned),
        );
    }

    public function enterNode(AstNode $node): ?int
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

        if (($node instanceof Stmt\ClassLike || $node instanceof Stmt\Function_) && $this->currentCallable === null && $this->currentClass === null) {
            $this->scripts[spl_object_id($node)] = [$this->localTypes, $this->pinned];
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
            foreach ($node->props as $property) {
                if ($property->default instanceof AstNode\Scalar\String_) {
                    $this->stringProperties[$property->name->toString()] = $property->default->value;
                }
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
                $classes = array_values(array_filter(array_map(fn (Name $type): ?string => $this->resolveName($type), $node->types)));
                $this->assignLocal($node->var->name, $classes === [] ? null : TypeExpr::union(array_map(TypeExpr::named(...), $classes)));
            }

            return null;
        }

        if ($node instanceof AstNode\Attribute) {
            $this->reference($this->owner(), $this->resolveName($node->name));

            return null;
        }

        if ($node instanceof Stmt\Foreach_) {
            $before = $this->localTypes;
            $this->forgetAssigned($node->keyVar);
            $this->forgetAssigned($node->valueVar);
            // `foreach ($this->rules as $rule)` over a collection typed in a docblock: `$rule` is a Rule.
            $element = $this->elementOf($node->expr);
            if ($element !== null && $node->valueVar instanceof Expr\Variable && \is_string($node->valueVar->name)) {
                $this->assignLocal($node->valueVar->name, \is_string($element) ? TypeExpr::named($element) : $element);
            }
            $this->loop($node, $before, 'expr', 'keyVar', 'valueVar', 'stmts');

            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
        }

        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $this->enterClosure($node);

            return null;
        }

        if ($this->onFlow($node)) {
            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
        }

        $this->onExpression($node);

        return null;
    }

    public function leaveNode(AstNode $node): ?int
    {
        if ($this->routeGroups !== [] && end($this->routeGroups) === spl_object_id($node)) {
            array_pop($this->routeGroups);
            $this->http->popPrefix();
        }

        if ($node instanceof Expr\Assign || $node instanceof Expr\AssignRef) {
            if ($node->var instanceof Expr\Variable && \is_string($node->var->name)) {
                $this->reassigned[$node->var->name] = true;
                $this->assignObject($node->var->name, $this->objectType($node->expr));
            } else {
                $this->forgetAssigned($node->var);
            }
        } elseif ($node instanceof Expr\AssignOp\Coalesce && $node->var instanceof Expr\Variable && \is_string($node->var->name)) {
            // `$x ??= new Order()`: what it held, or the order.
            $this->reassigned[$node->var->name] = true;
            $this->assignObject($node->var->name, $this->objectUnion([$this->objectType($node->var), $this->objectType($node->expr)]));
        } elseif ($node instanceof Expr\AssignOp) {
            $this->forgetAssigned($node->var);
        }

        if ($node instanceof Expr\FuncCall && $node->name instanceof Name && strtolower($node->name->getLast()) === 'assert'
            && ($node->args[0] ?? null) instanceof AstNode\Arg) {
            $this->assume($node->args[0]->value, true);
        }
        // `Assert::isInstanceOf($order, Order::class);`, `$this->assertInstanceOf(Order::class, $order);`
        if ($node instanceof Stmt\Expression && ($node->expr instanceof Expr\FuncCall || $node->expr instanceof Expr\StaticCall
            || $node->expr instanceof Expr\MethodCall || $node->expr instanceof Expr\NullsafeMethodCall)) {
            $this->apply($this->calledAssertions($node->expr, false, true));
        }

        if ($node instanceof Stmt\Return_ && $node->expr !== null && $this->closureNodes !== []) {
            $this->closureReturned[end($this->closureNodes)][] = $this->typeOf($node->expr);
        }
        // A script returning a value to the one requiring it: `return $app;` in bootstrap/app.php.
        if ($node instanceof Stmt\Return_ && $this->closureNodes === [] && $this->currentCallable === null && $this->currentClass === null) {
            $this->returned[] = ($node->expr === null ? null : $this->typeOf($node->expr)) ?? TypeExpr::unknown();
        }
        // A method returning a client with its base URL set: the calls made on what it returns are relative to it.
        if ($node instanceof Stmt\Return_ && $node->expr !== null && $this->closureNodes === [] && $this->currentCallable !== null) {
            $base = $this->baseUrl($node->expr);
            if ($base !== null) {
                $this->baseUrls[$this->currentCallable] = $base;
            }
        }

        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $this->onClosureReturn($node);
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
            $this->stringProperties = [];
        }

        if ($node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_) {
            $this->configuration->leaveCallable();
            $this->keepUsefulPasses();
            $this->parameterElements = [];
            $this->documentedParameters = [];
            $this->documentedGlobals = [];
            $this->reassigned = [];
            $this->visibleParameters = [];
            $this->parameters = [];
            $this->closures = [];
            $this->currentCallable = null;
            $this->localTypes = [];
            $this->pinned = [];
            $this->outerScopes = [];
        }

        if (isset($this->scripts[spl_object_id($node)])) {
            [$this->localTypes, $this->pinned] = $this->scripts[spl_object_id($node)];
            unset($this->scripts[spl_object_id($node)]);
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

        $modifiers = $node instanceof Stmt\Class_ ? array_keys(array_filter(['final' => $node->isFinal(), 'abstract' => $node->isAbstract(), 'readonly' => $node->isReadonly()])) : [];
        $this->nodes[] = new Node($fqcn, $node->name->toString(), $kind, $this->path, $node->getStartLine(), null, $modifiers);
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
        // Magic methods the class documents (`@method static PendingRequest timeout(int $seconds)` on a facade): they
        // carry a call chain on as declared ones do, without being nodes of the graph.
        $declared = [];
        foreach ($node->stmts as $statement) {
            if ($statement instanceof Stmt\ClassMethod) {
                $declared[strtolower($statement->name->toString())] = true;
            }
        }
        $documented = $node->getAttribute(DocTypeResolver::DOC_METHODS);
        foreach (\is_array($documented) ? $documented : [] as $lower => $method) {
            if (!\is_array($method) || isset($declared[(string) $lower]) || !\is_string($method[0] ?? null)) {
                continue;
            }
            $id = $fqcn . '::' . $method[0];
            $this->documentedMethods[$fqcn][(string) $lower] = $id;
            if (\is_string($method[1] ?? null)) {
                $this->returnTypes[$id] = $method[1] === 'self' ? $fqcn : $method[1];
            }
            if (\is_string($method[2] ?? null)) {
                $this->genericReturns[$id] = $method[2];
            }
        }
        $mixins = $node->getAttribute(DocTypeResolver::MIXINS);
        if (\is_array($mixins) && $mixins !== []) {
            $this->mixins[$fqcn] = array_values(array_filter($mixins, 'is_string'));
        }
        $parentArguments = $node->getAttribute(DocTypeResolver::PARENT_ARGUMENTS);
        if (\is_array($parentArguments) && $parentArguments !== []) {
            /** @var array<string, list<string>> $parentArguments */
            $this->parentArguments[$fqcn] = $parentArguments;
        }
        foreach ($this->collectCasts($node) as $attribute => $class) {
            $this->casts[$fqcn . '::' . $attribute] = $class;
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
        $modifiers = $node instanceof Stmt\ClassMethod ? array_keys(array_filter([
            'protected' => $node->isProtected(),
            'private' => $node->isPrivate(),
            'static' => $node->isStatic(),
            'abstract' => $node->isAbstract(),
            'final' => $node->isFinal(),
        ])) : [];
        $this->nodes[] = new Node($id, $label, $kind, $this->path, $node->getStartLine(), null, $modifiers);

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
        $this->parameterElements = self::stringMap($elements);
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
        $this->documentedParameters = array_map(fn (string $type): string => (string) $this->documentedClass($type), self::stringMap($documented));
        $this->documentedGlobals = self::stringMap($node->getAttribute(DocTypeResolver::GLOBAL_TYPES));
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
        $this->typeParameters($node->params, self::stringMap($generics), $this->documentedParameters);

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

        $conditional = $node->getAttribute(DocTypeResolver::RETURN_CONDITIONAL);
        if ($conditional instanceof ConditionalReturn) {
            $this->conditionalReturns[$id] = $conditional;
        }
        $assertions = $node->getAttribute(DocTypeResolver::ASSERTIONS);
        if (\is_array($assertions)) {
            /** @var list<array{string, int, string}> $assertions */
            $this->assertions[$id] = $assertions;
        }
        $binders = $node->getAttribute(DocTypeResolver::TEMPLATE_BINDERS);
        if (\is_array($binders)) {
            /** @var array<string, array{string, int}> $binders */
            $this->templateBinders[$id] = $binders;
        }
        // A function's return type types the calls to it: `now()->addDay()`, `response()->json()`.
        if ($kind === NodeKind::Func) {
            $returnType = $this->declaredReturnType($node);
            if ($returnType !== null && $returnType !== TypeExpr::STATIC) {
                $this->returnTypes[$id] = $returnType;
            }
            $generic = $this->returnGeneric($node);
            if (\is_string($generic) && !str_contains($generic, TypeExpr::STATIC)) {
                $this->genericReturns[$id] = $generic;
            }
        }

        if ($kind === NodeKind::Method) {
            $firstParameter = $this->singleType($node->params[0]->type ?? null);
            if ($firstParameter !== null) {
                $this->parameterTypes[$id] = $firstParameter;
            }
            // Symfony's getSubscribedEvents(), Laravel's subscribe(Dispatcher $events) returning event => method.
            $subscriber = strtolower($node->name->toString()) === 'subscribe' && \count($node->params) === 1
                && preg_match('/(^|\\\\)Dispatcher$/', $firstParameter ?? 'Dispatcher') === 1;
            if ($node instanceof Stmt\ClassMethod && $this->currentClass !== null && (strtolower($node->name->toString()) === 'getsubscribedevents' || $subscriber)) {
                $this->onSubscribedEvents($node);
            }
            $returnType = $this->declaredReturnType($node);
            if ($returnType !== null) {
                $this->returnTypes[$id] = $returnType;
            }
            $generic = $this->returnGeneric($node);
            if (\is_string($generic)) {
                $this->genericReturns[$id] = $generic;
            }
            $callables = $node->getAttribute(DocTypeResolver::CALLABLE_PARAMETERS);
            if (\is_array($callables)) {
                /** @var array<int|string, list<string>> $callables */
                $this->callableParameters[$id] = $callables;
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
                    array_values(array_filter(explode('|', $this->nativeUnion($node->params[0]->type ?? null) ?? ''))),
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
     * @param array<string, string> $generics   parameter => its GenericType documented (`@param Collection<int, Item>`)
     * @param array<string, string> $documented parameter => its class documented (`@param WP_Error|null $error`), for
     *                                          a parameter without a native type
     */
    private function typeParameters(array $params, array $generics = [], array $documented = []): void
    {
        foreach ($params as $param) {
            if ($param->var instanceof Expr\Variable && \is_string($param->var->name)) {
                $type = $this->singleType($param->type) ?? ($param->type === null && ($documented[$param->var->name] ?? '') !== '' ? $documented[$param->var->name] : null);
                $generic = $this->usableGeneric($generics[$param->var->name] ?? $this->nativeUnion($param->type, true));
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
        if (GenericType::isUnion($type)) {
            return array_filter(GenericType::members($type), fn (string $member): bool => $this->usableGeneric($member) === null && !GenericType::isClass($member)) === [] ? $type : null;
        }
        [$base, $arguments] = GenericType::parse($type);

        return $arguments !== [] && ($base === GenericType::ARRAY || GenericType::isClass($base)) && !str_contains($type, TypeExpr::STATIC) ? $type : null;
    }

    private function enterClosure(Expr\Closure|Expr\ArrowFunction $node): void
    {
        $this->closureNodes[] = spl_object_id($node);
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

        // An untyped parameter of a closure passed to a method: what the method documents it passes, resolved later.
        [$call, $argument] = $this->closureArguments[spl_object_id($node)] ?? [null, null];
        $receiver = $call === null ? null : $this->pending[$call]->receiver ?? null;
        if ($receiver === null || $argument === null) {
            return;
        }
        foreach ($node->params as $position => $param) {
            if ($param->type === null && $param->var instanceof Expr\Variable && \is_string($param->var->name)) {
                $this->assignLocal($param->var->name, TypeExpr::closureParameter($receiver, $this->pending[$call]->method, $argument, $position));
            }
        }
    }

    /**
     * What a closure returns, when it is known and the same for every return statement: its native return type, its
     * expression for an arrow function. Read while its variables are still in scope.
     */
    private function onClosureReturn(Expr\Closure|Expr\ArrowFunction $node): void
    {
        $id = (int) array_pop($this->closureNodes);
        $returned = $this->closureReturned[$id] ?? [];
        unset($this->closureReturned[$id]);
        $native = $this->singleType($node->returnType);
        if ($native !== null) {
            $this->closureReturns[$id] = TypeExpr::named($native);

            return;
        }
        if ($node instanceof Expr\ArrowFunction) {
            $returned = [$this->typeOf($node->expr)];
        }
        $keys = array_unique(array_map(static fn (?TypeExpr $type): string => $type?->key() ?? '', $returned));
        $this->closureReturns[$id] = \count($keys) === 1 && $returned[0] !== null ? $returned[0] : null;
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
        if ($node instanceof Stmt\Property || $node instanceof Stmt\ClassMethod) {
            return;
        }

        // A union or an intersection (`@var OrderInterface&MockObject`) has no single class: only its GenericType.
        $types = $node->getAttribute(DocTypeResolver::VAR_TYPES);
        foreach (\is_array($types) ? $types : [] as $name => $type) {
            if ($name === '' && $node instanceof Stmt\Expression && $node->expr instanceof Expr\Assign
                && $node->expr->var instanceof Expr\Variable && \is_string($node->expr->var->name)
            ) {
                $name = $node->expr->var->name;
            }
            if ($type === TypeExpr::STATIC) {
                $type = 'self';
            }
            $class = \is_string($type) ? $this->documentedClass($type) : null;
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
     * The last assignment read wins on the path being read; branches are merged where they meet (onFlow). An assignment
     * of unknown type makes the variable unknown.
     */
    private function assignLocal(string $name, ?TypeExpr $type): void
    {
        if (isset($this->pinned[$name])) {
            return;
        }

        $this->localTypes[$name] = $type ?? TypeExpr::unknown();
    }

    /**
     * @param ?TypeExpr $type null for no object: the variable is then absent, a branch assigning it adds nothing
     */
    private function assignObject(string $name, ?TypeExpr $type): void
    {
        if ($type !== null) {
            $this->assignLocal($name, $type->isUnknown() ? null : $type);
        } elseif (!isset($this->pinned[$name])) {
            unset($this->localTypes[$name]);
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

    /**
     * Reads a node whose parts may not all run: each branch starts from the variables before it, narrowed by what its
     * condition tests (`instanceof`, `is_a()`), and the branches reaching the end are merged. A branch ending in
     * `return`, `throw`, `exit`, `break` or `continue` does not reach it (the last two reach the end of their loop or
     * switch). Loops are read once: their body may not run. The parts are read in the order of the traversal, so that
     * everything else the visitor records stays the same.
     *
     * @return bool whether the node was read, its children with it
     */
    private function onFlow(AstNode $node): bool
    {
        if ($node instanceof Stmt\Break_ || $node instanceof Stmt\Continue_) {
            $index = \count($this->jumps) - ($node->num instanceof AstNode\Scalar\Int_ ? max(1, $node->num->value) : 1);
            if ($index >= 0) {
                $this->jumps[$index][] = $this->localTypes;
            }

            return false;
        }
        // A global holds what the whole program assigns it: its `@global` class when the function documents one.
        if ($node instanceof Stmt\Global_) {
            foreach ($node->vars as $variable) {
                if ($variable instanceof Expr\Variable && \is_string($variable->name)) {
                    $class = $this->documentedGlobals[$variable->name] ?? null;
                    $this->assignLocal($variable->name, $class === null ? null : TypeExpr::named($class));
                }
            }

            return false;
        }
        // A static variable holds what the function assigns it, from one call to the next: with no object to start
        // with (`static $bean = null;`), no more than the assignments read.
        if ($node instanceof Stmt\Static_) {
            foreach ($node->vars as $static) {
                if (\is_string($static->var->name)) {
                    $default = $static->default;
                    $noObject = $default === null || $default instanceof AstNode\Scalar || $default instanceof Expr\Array_
                        || ($default instanceof Expr\ConstFetch && \in_array(strtolower($default->name->toString()), ['null', 'false', 'true'], true));
                    $noObject ? $this->assignObject($static->var->name, null) : $this->assignLocal($static->var->name, null);
                }
            }

            return false;
        }

        match (true) {
            $node instanceof Stmt\If_ => $this->onIf($node),
            $node instanceof Stmt\Switch_ => $this->onSwitch($node),
            $node instanceof Stmt\TryCatch => $this->onTry($node),
            $node instanceof Stmt\While_ => $this->loop($node, null, 'cond', 'stmts'),
            $node instanceof Stmt\For_ => $this->loop($node, null, 'init', 'cond', 'loop', 'stmts'),
            $node instanceof Stmt\Do_ => $this->loop($node, null, 'stmts', 'cond'),
            $node instanceof Expr\Ternary => $this->onTernary($node),
            $node instanceof Expr\BinaryOp\Coalesce => $this->onCoalesce($node),
            $node instanceof Expr\BinaryOp\BooleanAnd, $node instanceof Expr\BinaryOp\LogicalAnd => $this->onShortCircuit($node, true),
            $node instanceof Expr\BinaryOp\BooleanOr, $node instanceof Expr\BinaryOp\LogicalOr => $this->onShortCircuit($node, false),
            $node instanceof Expr\Match_ => $this->onMatch($node),
            default => null,
        };

        return $node instanceof Stmt\If_ || $node instanceof Stmt\Switch_ || $node instanceof Stmt\TryCatch
            || $node instanceof Stmt\While_ || $node instanceof Stmt\For_ || $node instanceof Stmt\Do_
            || $node instanceof Expr\Ternary || $node instanceof Expr\BinaryOp\Coalesce || $node instanceof Expr\Match_
            || $node instanceof Expr\BinaryOp\BooleanAnd || $node instanceof Expr\BinaryOp\LogicalAnd
            || $node instanceof Expr\BinaryOp\BooleanOr || $node instanceof Expr\BinaryOp\LogicalOr;
    }

    private function onIf(Stmt\If_ $node): void
    {
        $this->read($node, 'cond');
        $ends = [];
        $otherwise = $this->localTypes;
        $this->assume($node->cond, true);
        $this->read($node, 'stmts');
        if (!self::exits($node->stmts)) {
            $ends[] = $this->localTypes;
        }
        $this->localTypes = $otherwise;
        $this->assume($node->cond, false);
        foreach ($node->elseifs as $elseIf) {
            $this->within($elseIf, function () use ($elseIf, &$ends): void {
                $this->read($elseIf, 'cond');
                $otherwise = $this->localTypes;
                $this->assume($elseIf->cond, true);
                $this->read($elseIf, 'stmts');
                if (!self::exits($elseIf->stmts)) {
                    $ends[] = $this->localTypes;
                }
                $this->localTypes = $otherwise;
                $this->assume($elseIf->cond, false);
            });
        }
        $else = $node->else;
        if ($else === null) {
            $ends[] = $this->localTypes;
        } else {
            $this->within($else, function () use ($else, &$ends): void {
                $this->read($else, 'stmts');
                if (!self::exits($else->stmts)) {
                    $ends[] = $this->localTypes;
                }
            });
        }
        // Every branch exits: what follows does not run, the variables matter little.
        $this->localTypes = $ends === [] ? $this->localTypes : $this->merge($ends);
    }

    private function onSwitch(Stmt\Switch_ $node): void
    {
        $this->read($node, 'cond');
        $before = $this->localTypes;
        $this->jumps[] = [];
        $falling = null;
        $default = false;
        foreach ($node->cases as $case) {
            $this->localTypes = $falling === null ? $before : $this->merge([$before, $falling]);
            $this->within($case, function () use ($case): void {
                $this->read($case, 'cond', 'stmts');
            });
            $falling = self::exits($case->stmts) ? null : $this->localTypes;
            $default = $default || $case->cond === null;
        }
        $ends = array_pop($this->jumps) ?? [];
        if ($falling !== null) {
            $ends[] = $falling;
        }
        if (!$default) {
            $ends[] = $before;
        }
        $this->localTypes = $ends === [] ? $before : $this->merge($ends);
    }

    /**
     * A catch may start anywhere in the try: from the variables before it or after it.
     */
    private function onTry(Stmt\TryCatch $node): void
    {
        $before = $this->localTypes;
        $this->read($node, 'stmts');
        $ends = self::exits($node->stmts) ? [] : [$this->localTypes];
        $start = $this->merge([$before, $this->localTypes]);
        foreach ($node->catches as $catch) {
            $this->localTypes = $start;
            $this->within($catch, function () use ($catch, &$ends): void {
                $this->read($catch, 'types', 'var', 'stmts');
                if (!self::exits($catch->stmts)) {
                    $ends[] = $this->localTypes;
                }
            });
        }
        $this->localTypes = $ends === [] ? $start : $this->merge($ends);
        $finally = $node->finally;
        if ($finally !== null) {
            $this->within($finally, function () use ($finally): void {
                $this->read($finally, 'stmts');
            });
        }
    }

    /**
     * A loop, read once: after it, the variables before it, at its end, or at a `break` or `continue` in it. A `do`
     * body runs at least once.
     *
     * @param ?array<string, TypeExpr> $before the variables before the loop, when they are not those of now
     */
    private function loop(Stmt\Foreach_|Stmt\While_|Stmt\For_|Stmt\Do_ $node, ?array $before, string ...$parts): void
    {
        $before ??= $this->localTypes;
        $this->jumps[] = [];
        $this->read($node, ...$parts);
        $ends = [$this->localTypes, ...array_pop($this->jumps) ?? []];
        if (!$node instanceof Stmt\Do_) {
            $ends[] = $before;
        }
        $this->localTypes = $this->merge($ends);
    }

    private function onTernary(Expr\Ternary $node): void
    {
        $this->read($node, 'cond');
        $before = $this->localTypes;
        $this->assume($node->cond, true);
        if ($node->if !== null) {
            $this->read($node, 'if');
        }
        $then = $this->objectType($node->if ?? $node->cond);
        $ends = [$this->localTypes];
        $this->localTypes = $before;
        $this->assume($node->cond, false);
        $this->read($node, 'else');
        $ends[] = $this->localTypes;
        $this->values[spl_object_id($node)] = $this->objectUnion([$then, $this->objectType($node->else)]);
        $this->localTypes = $this->merge($ends);
    }

    private function onCoalesce(Expr\BinaryOp\Coalesce $node): void
    {
        $this->read($node, 'left');
        $before = $this->localTypes;
        $left = $this->objectType($node->left);
        $this->read($node, 'right');
        $this->values[spl_object_id($node)] = $this->objectUnion([$left, $this->objectType($node->right)]);
        $this->localTypes = $this->merge([$before, $this->localTypes]);
    }

    /**
     * `&&` reads its right side when the left one holds, `||` when it does not.
     */
    private function onShortCircuit(Expr\BinaryOp $node, bool $and): void
    {
        $this->read($node, 'left');
        $before = $this->localTypes;
        $this->assume($node->left, $and);
        $this->read($node, 'right');
        $after = $this->localTypes;
        $this->localTypes = $before;
        $this->assume($node->left, !$and);
        $this->localTypes = $this->merge([$this->localTypes, $after]);
    }

    /**
     * `match (true)` narrows each arm by its conditions, and the arms after it by their negation.
     */
    private function onMatch(Expr\Match_ $node): void
    {
        $this->read($node, 'cond');
        $tests = $node->cond instanceof Expr\ConstFetch && strtolower($node->cond->name->toString()) === 'true';
        $otherwise = $this->localTypes;
        $ends = [];
        $values = [];
        foreach ($node->arms as $arm) {
            $this->localTypes = $otherwise;
            $this->within($arm, function () use ($arm, $tests, &$ends, &$values, &$otherwise): void {
                $this->read($arm, 'conds');
                $conditions = $tests ? $arm->conds ?? [] : [];
                $otherwise = $this->localTypes;
                if ($conditions !== []) {
                    $this->apply(array_reduce(
                        \array_slice($conditions, 1),
                        fn (array $either, Expr $condition): array => self::either($either, $this->assertions($condition, true)),
                        $this->assertions($conditions[0], true),
                    ));
                }
                $this->read($arm, 'body');
                $values[] = $this->objectType($arm->body);
                $ends[] = $this->localTypes;
                $current = $this->localTypes;
                $this->localTypes = $otherwise;
                foreach ($conditions as $condition) {
                    $this->assume($condition, false);
                }
                $otherwise = $this->localTypes;
                $this->localTypes = $current;
            });
        }
        $this->values[spl_object_id($node)] = $this->objectUnion($values);
        $this->localTypes = $ends === [] ? $otherwise : $this->merge($ends);
    }

    /**
     * Reads parts of a node as the traversal would have: each child, then its children.
     */
    private function read(AstNode $node, string ...$parts): void
    {
        foreach ($parts as $part) {
            $child = $node->{$part};
            $children = array_values(array_filter(\is_array($child) ? $child : [$child], static fn (mixed $item): bool => $item instanceof AstNode));
            if ($children !== []) {
                (new NodeTraverser($this))->traverse($children);
            }
        }
    }

    /**
     * Reads a part of a flow node (an `else`, a `case`, a `catch`) as the traversal would have, entering and leaving it.
     *
     * @param \Closure(): void $read
     */
    private function within(AstNode $node, \Closure $read): void
    {
        $this->enterNode($node);
        $read();
        $this->leaveNode($node);
    }

    /**
     * @param array<AstNode> $statements
     */
    private static function exits(array $statements): bool
    {
        $last = end($statements);

        return match (true) {
            $last instanceof Stmt\Return_, $last instanceof Stmt\Break_, $last instanceof Stmt\Continue_, $last instanceof Stmt\Goto_ => true,
            $last instanceof Stmt\Expression => $last->expr instanceof Expr\Throw_ || $last->expr instanceof Expr\Exit_,
            $last instanceof Stmt\Block => self::exits($last->stmts),
            $last instanceof Stmt\If_ => $last->else !== null && self::exits($last->stmts) && self::exits($last->else->stmts)
                && array_filter($last->elseifs, static fn (Stmt\ElseIf_ $elseIf): bool => !self::exits($elseIf->stmts)) === [],
            default => false,
        };
    }

    /**
     * The variables of the branches reaching the same point: a variable some of them assign holds what any of them
     * assigns (those leaving it absent add nothing).
     *
     * @param non-empty-list<array<string, TypeExpr>> $states
     *
     * @return array<string, TypeExpr>
     */
    private function merge(array $states): array
    {
        if (\count($states) === 1) {
            return $states[0];
        }
        $merged = [];
        foreach (array_keys(array_merge(...$states)) as $name) {
            $types = [];
            foreach ($states as $state) {
                if (isset($state[$name])) {
                    $types[$state[$name]->key()] = $state[$name];
                }
            }
            $merged[$name] = \count($types) === 1 ? reset($types) : self::bounded(TypeExpr::union(array_values($types)));
        }

        return $merged;
    }

    /**
     * A merged type too long to keep (variables narrowed and merged again and again): unknown, as a type the
     * extractor does not follow.
     */
    private static function bounded(TypeExpr $type): TypeExpr
    {
        return \strlen($type->key()) > self::MERGED_KEY ? TypeExpr::unknown() : $type;
    }

    /**
     * What a value may be as an object: null for none (`null`, a scalar, an array, a throw), unknown when nothing is
     * known of it.
     */
    private function objectType(Expr $expr): ?TypeExpr
    {
        if ($expr instanceof Expr\Variable && \is_string($expr->name) && $expr->name !== 'this') {
            return $this->localTypes[$expr->name] ?? null;
        }
        if ($expr instanceof Expr\Ternary || $expr instanceof Expr\BinaryOp\Coalesce || $expr instanceof Expr\Match_) {
            return $this->valueOf($expr);
        }
        $none = ($expr instanceof Expr\ConstFetch && \in_array(strtolower($expr->name->toString()), ['null', 'true', 'false'], true))
            || $expr instanceof AstNode\Scalar || $expr instanceof Expr\Array_ || $expr instanceof Expr\Throw_
            || ($expr instanceof Expr\Cast && !$expr instanceof Expr\Cast\Object_)
            || $expr instanceof Expr\BinaryOp
            || $expr instanceof Expr\BooleanNot || $expr instanceof Expr\Isset_ || $expr instanceof Expr\Empty_ || $expr instanceof Expr\Instanceof_;

        return $none ? null : $this->typeOf($expr) ?? TypeExpr::unknown();
    }

    /**
     * The value of a ternary, a `??` or a match: read in its branches, or now, when it is asked for before.
     */
    private function valueOf(Expr\Ternary|Expr\BinaryOp\Coalesce|Expr\Match_ $expr): ?TypeExpr
    {
        $id = spl_object_id($expr);
        if (\array_key_exists($id, $this->values)) {
            return $this->values[$id];
        }

        return $this->objectUnion(match (true) {
            $expr instanceof Expr\Ternary => [$this->objectType($expr->if ?? $expr->cond), $this->objectType($expr->else)],
            $expr instanceof Expr\BinaryOp\Coalesce => [$this->objectType($expr->left), $this->objectType($expr->right)],
            default => array_values(array_map(fn (AstNode\MatchArm $arm): ?TypeExpr => $this->objectType($arm->body), $expr->arms)),
        });
    }

    /**
     * @param list<?TypeExpr> $types null for no object
     */
    private function objectUnion(array $types): ?TypeExpr
    {
        $types = array_values(array_filter($types, static fn (?TypeExpr $type): bool => $type !== null));

        return $types === [] ? null : self::bounded(TypeExpr::union($types));
    }

    private function assume(Expr $condition, bool $holds): void
    {
        $this->apply($this->assertions($condition, $holds));
    }

    /**
     * What a condition tells of the variables it tests when it holds, or when it does not: variable => the classes
     * it is an instance of, or is not (excluding); or, for a call that may assert it (`is_wp_error($result)`), no
     * class but the call, which the builder reads the callable's assertions for.
     *
     * @return array<string, array{list<string>, bool, 2?: array{TypeExpr, int, bool}}>
     */
    private function assertions(Expr $condition, bool $holds): array
    {
        if ($condition instanceof Expr\BooleanNot) {
            return $this->assertions($condition->expr, !$holds);
        }
        $tested = null;
        if ($condition instanceof Expr\Instanceof_ && $condition->class instanceof Name) {
            $tested = [$condition->expr, $this->resolveName($condition->class)];
        } elseif ($condition instanceof Expr\FuncCall && $condition->name instanceof Name
            && \in_array(strtolower($condition->name->getLast()), ['is_a', 'is_subclass_of'], true)
            && ($condition->args[0] ?? null) instanceof AstNode\Arg && ($condition->args[1] ?? null) instanceof AstNode\Arg) {
            $tested = [$condition->args[0]->value, $this->classConstant($condition->args[1]->value)];
        }
        if ($tested !== null) {
            [$subject, $class] = $tested;
            if ($subject instanceof Expr\Assign) {
                $subject = $subject->var;
            }

            return $class !== null && $subject instanceof Expr\Variable && \is_string($subject->name) && $subject->name !== 'this'
                ? [$subject->name => [[$class], !$holds]]
                : [];
        }

        if ($condition instanceof Expr\FuncCall || $condition instanceof Expr\StaticCall || $condition instanceof Expr\MethodCall || $condition instanceof Expr\NullsafeMethodCall) {
            return $this->calledAssertions($condition, !$holds, false);
        }

        $and = $condition instanceof Expr\BinaryOp\BooleanAnd || $condition instanceof Expr\BinaryOp\LogicalAnd;
        $or = $condition instanceof Expr\BinaryOp\BooleanOr || $condition instanceof Expr\BinaryOp\LogicalOr;
        if (!$and && !$or) {
            return [];
        }
        \assert($condition instanceof Expr\BinaryOp);
        $left = $this->assertions($condition->left, $holds);
        $right = $this->assertions($condition->right, $holds);
        if ($and === $holds) {
            // Both sides hold (or both fail): a variable excluded from classes on both sides is none of them.
            foreach ($right as $variable => $assertion) {
                $left[$variable] = $assertion[1] && ($left[$variable][1] ?? false) && !isset($assertion[2]) && !isset($left[$variable][2])
                    ? [array_values(array_unique([...$left[$variable][0], ...$assertion[0]])), true]
                    : $assertion;
            }

            return $left;
        }

        return self::either($left, $right);
    }

    /**
     * One of two sets of assertions holds: a variable both say is an instance of classes is an instance of one of them.
     *
     * @param array<string, array{list<string>, bool, 2?: array{TypeExpr, int, bool}}> $left
     * @param array<string, array{list<string>, bool, 2?: array{TypeExpr, int, bool}}> $right
     *
     * @return array<string, array{list<string>, bool, 2?: array{TypeExpr, int, bool}}>
     */
    private static function either(array $left, array $right): array
    {
        $either = [];
        foreach ($left as $variable => $assertion) {
            $other = $right[$variable] ?? null;
            if (!$assertion[1] && !isset($assertion[2]) && $other !== null && !$other[1] && !isset($other[2])) {
                $either[$variable] = [array_values(array_unique([...$assertion[0], ...$other[0]])), false];
            }
        }

        return $either;
    }

    /**
     * The variables a call passes that its callable may assert something of: the builder reads its assertions
     * (`@phpstan-assert-if-true WP_Error $thing`). Only for a call whose name says it may (`is…`, `assert…`, `ensure…`,
     * `check…`, a static call on an `…Assert` or `…Assertion` class), and not PHP's own `is_*()` tests: each such
     * variable is wrapped in what the call tells of it.
     *
     * @return array<string, array{list<string>, bool, 2?: array{TypeExpr, int, bool}}>
     */
    private function calledAssertions(Expr\FuncCall|Expr\StaticCall|Expr\MethodCall|Expr\NullsafeMethodCall $call, bool $failed, bool $statement): array
    {
        $name = match (true) {
            $call->name instanceof Name => $call->name->getLast(),
            $call->name instanceof Identifier => $call->name->toString(),
            default => null,
        };
        if ($name === null || $this->currentCallable === null && $this->currentClass !== null) {
            return [];
        }
        $name = strtolower($name);
        $asserting = $call instanceof Expr\StaticCall && $call->class instanceof Name
            && preg_match('/assert(ion)?$/i', $call->class->getLast()) === 1;
        if (!$asserting && (preg_match(self::ASSERTING, $name) !== 1 || $name === 'assert' || ($call instanceof Expr\FuncCall && \in_array($name, self::PHP_TESTS, true)))) {
            return [];
        }
        $callee = $this->typeOf($call);
        if ($callee === null) {
            return [];
        }
        $assertions = [];
        foreach ($call->args as $position => $arg) {
            if (!$arg instanceof AstNode\Arg || $arg->name !== null || $arg->unpack) {
                break;
            }
            if ($arg->value instanceof Expr\Variable && \is_string($arg->value->name) && $arg->value->name !== 'this') {
                $assertions[$arg->value->name] = [[], $failed, [$callee, $position, $statement]];
            }
        }

        return $assertions;
    }

    /**
     * @param array<string, array{list<string>, bool, 2?: array{TypeExpr, int, bool}}> $assertions
     */
    private function apply(array $assertions): void
    {
        foreach ($assertions as $variable => $assertion) {
            [$classes, $excluding] = $assertion;
            $current = $this->localTypes[$variable] ?? null;
            $known = $current !== null && !$current->isUnknown();
            if (isset($assertion[2])) {
                [$call, $position, $statement] = $assertion[2];
                $this->localTypes[$variable] = TypeExpr::asserted($current ?? TypeExpr::unknown(), $call, $position, $excluding, $statement);
                continue;
            }
            if ($excluding) {
                // Only a union, or a type the builder may find to be one, loses members.
                $single = $known && $current->receiver === null && $current->members === [] && $current->test === null
                    && $current->functions === [] && ($current->generic === null || !GenericType::isUnion($current->generic));
                if ($known && !$single) {
                    $this->localTypes[$variable] = TypeExpr::narrowed($current, $classes, true);
                }
                continue;
            }
            $same = $known && \count($classes) === 1 && $current->className !== null && $current->receiver === null
                && strtolower($current->className) === strtolower($classes[0]);
            $this->localTypes[$variable] = match (true) {
                !$known => TypeExpr::union(array_map(TypeExpr::named(...), $classes)),
                $same => $current,
                default => TypeExpr::narrowed($current, $classes),
            };
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
            // Outside any method, in a script, the file calls it: `$pdf = new Report(); $pdf->render();`.
            $caller = $this->currentCallable ?? ($this->currentClass === null ? $this->fileId : null);
            if ($caller !== null) {
                $this->pending[] = new PendingCall(
                    $caller,
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
                        $this->passesParameter($args),
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
            $this->passesParameter($args),
        ));
    }

    /**
     * One of the first arguments of a send (its message, its name, its routing key) is a parameter of the caller.
     *
     * @param array<AstNode> $args
     */
    private function passesParameter(array $args): bool
    {
        foreach ([0, 1, 2] as $position) {
            $argument = $this->argumentNode($args, $position);
            if ($argument !== null && $this->untouchedParameter($argument->value) !== null) {
                return true;
            }
        }

        return false;
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
                    $value = $this->literalOrClass($item->value);
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

        $id = $this->literalOrClass($first->value);
        $target = $this->literalOrClass($second?->value);
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
                        $value = $this->literalOrClass($argument->value);
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
                array_filter($call->args, static fn (AstNode\Arg|AstNode\ArgPlaceholder|AstNode\VariadicPlaceholder $argument): bool => $argument instanceof AstNode\Arg && !$argument->unpack),
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
            // Laravel's `$events->listen()` on the events dispatcher, in a subscriber's subscribe($events) when untyped.
            default => $name === 'addlistener' || ($name === 'listen' && $this->isEventsDispatcher($node->var)),
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

    private function isEventsDispatcher(Expr $receiver): bool
    {
        $class = $this->typeOf($receiver)?->className;
        if ($class !== null) {
            return preg_match('/(^|\\\\)Dispatcher$/', $class) === 1;
        }

        return $receiver instanceof Expr\Variable && $this->currentCallable !== null && str_ends_with(strtolower($this->currentCallable), '::subscribe');
    }

    /**
     * What a callable names: [$this, 'm'], [__CLASS__, 'm'], [Listener::class, 'm'], 'Listener::m', 'a_function',
     * Listener::class or new Listener() (its handle() or __invoke()), [$typed, 'm'], $this->m(...), Listener::m(...).
     * Closures name nothing.
     *
     * @return array{?string, ?string} the class (or function) and the method id, null for handle()/__invoke()
     */
    private function callable(Expr $callable): array
    {
        if (($callable instanceof Expr\MethodCall || $callable instanceof Expr\StaticCall) && $callable->isFirstClassCallable() && $callable->name instanceof Identifier) {
            $class = match (true) {
                $callable instanceof Expr\MethodCall => $this->typeOf($callable->var)?->className,
                $callable->class instanceof Name => $this->resolveName($callable->class),
                default => null,
            };

            return $class === null ? [null, null] : [$class, $class . '::' . $callable->name->toString()];
        }
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
            $value = $this->literalOrClass($argument);
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
                return $this->literalOrClass($argument);
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
        // ->name('home') around the route's call, Route::get(...)->middleware('auth')->name('home'): kept for it.
        if ($name === 'name' && $node instanceof Expr\MethodCall && ($this->argumentNode($node->args, 0)?->value) instanceof AstNode\Scalar\String_) {
            for ($call = $node->var; $call instanceof Expr\MethodCall || $call instanceof Expr\StaticCall; $call = $call instanceof Expr\MethodCall ? $call->var : null) {
                if ($call->name instanceof Identifier && RouteExtractor::isLaravelVerb($call->name->toString())) {
                    $this->routeNames[spl_object_id($call)] = $this->argumentNode($node->args, 0)->value->value;
                    break;
                }
            }

            return;
        }
        if ($name === 'group') {
            $prefix = '';
            $host = null;
            $namePrefix = '';
            $first = $this->argumentNode($node->args, 0);
            if ($first !== null && $first->value instanceof Expr\Array_) {
                foreach ($first->value->items as $item) {
                    if ($item->key instanceof AstNode\Scalar\String_ && $item->key->value === 'prefix' && $item->value instanceof AstNode\Scalar\String_) {
                        $prefix = $item->value->value;
                    }
                    if ($item->key instanceof AstNode\Scalar\String_ && $item->key->value === 'domain') {
                        $host = $item->value instanceof AstNode\Scalar\String_ ? strtolower($item->value->value) : '*';
                    }
                    if ($item->key instanceof AstNode\Scalar\String_ && $item->key->value === 'as' && $item->value instanceof AstNode\Scalar\String_) {
                        $namePrefix = $item->value->value;
                    }
                }
            }
            for ($call = $node; $call instanceof Expr\MethodCall || $call instanceof Expr\StaticCall; $call = $call instanceof Expr\MethodCall ? $call->var : null) {
                $argument = $this->argumentNode($call->args, 0);
                if ($call->name instanceof Identifier && strtolower($call->name->toString()) === 'prefix' && $argument?->value instanceof AstNode\Scalar\String_) {
                    $prefix = $argument->value->value . '/' . $prefix;
                }
                // Route::domain('admin.example.com'): a host computed at runtime (config) is unknown, `*`.
                if ($call->name instanceof Identifier && strtolower($call->name->toString()) === 'domain' && $argument !== null) {
                    $host = $argument->value instanceof AstNode\Scalar\String_ ? strtolower($argument->value->value) : '*';
                }
                if ($call->name instanceof Identifier && strtolower($call->name->toString()) === 'name' && $argument?->value instanceof AstNode\Scalar\String_) {
                    $namePrefix = $argument->value->value . $namePrefix;
                }
            }
            $this->routeGroups[] = spl_object_id($node);
            $this->http->pushPrefix(trim($prefix, '/'), $host, $namePrefix);

            return;
        }

        // Route::name('home')->get(...): the name written before the route's call.
        $routeName = $this->routeNames[spl_object_id($node)] ?? null;
        for ($call = $node instanceof Expr\MethodCall ? $node->var : null; $routeName === null && ($call instanceof Expr\MethodCall || $call instanceof Expr\StaticCall); $call = $call instanceof Expr\MethodCall ? $call->var : null) {
            $argument = $this->argumentNode($call->args, 0);
            if ($call->name instanceof Identifier && strtolower($call->name->toString()) === 'name' && $argument?->value instanceof AstNode\Scalar\String_) {
                $routeName = $argument->value->value;
            }
        }
        $this->http->onLaravelRoute($name, $node->args, $node->getStartLine(), fn (Name $class): ?string => $this->resolveName($class), $routeName);
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
            $url['host'],
            $node instanceof Expr\MethodCall ? $this->baseUrl($node->var) : null,
        ));
    }

    /**
     * The base URL a call chain sets, Laravel's `Http::baseUrl('https://api.example.com/v2')->...`: a string, a
     * property of `$this` holding one by default, a concatenation of both; `*` where computed (`config(...)`).
     */
    private function baseUrl(Expr $chain): ?string
    {
        for (; $chain instanceof Expr\MethodCall || $chain instanceof Expr\StaticCall; $chain = $chain instanceof Expr\MethodCall ? $chain->var : null) {
            $argument = $this->argumentNode($chain->args, 0);
            if ($chain->name instanceof Identifier && strtolower($chain->name->toString()) === 'baseurl' && $argument !== null) {
                return $this->urlValue($argument->value);
            }
        }

        return null;
    }

    private function urlValue(Expr $expr): string
    {
        return match (true) {
            $expr instanceof AstNode\Scalar\String_ => $expr->value,
            $expr instanceof Expr\BinaryOp\Concat => $this->urlValue($expr->left) . $this->urlValue($expr->right),
            $expr instanceof Expr\PropertyFetch && $expr->var instanceof Expr\Variable && $expr->var->name === 'this' && $expr->name instanceof Identifier
                => $this->stringProperties[$expr->name->toString()] ?? '*',
            default => '*',
        };
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
     * @return list<array{name: string, path: ?string, methods: list<string>, routeName: ?string}>
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
     * @return list<array{name: string, handles: ?string, method: ?string, key: ?string, path: ?string, methods: list<string>, routeName: ?string}>
     */
    private function attributes(array $groups): array
    {
        $attributes = [];
        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                $handles = $method = $key = $path = $routeName = null;
                $methods = [];
                foreach ($attribute->args as $position => $argument) {
                    $name = $argument->name?->toString();
                    if (($name === null && $position === 0) || $name === 'path') {
                        $path = $this->pathValue($argument->value) ?? $path;
                    }
                    if ((($name === null && $position === 1) || $name === 'name') && $argument->value instanceof AstNode\Scalar\String_) {
                        $routeName = $argument->value->value;
                    }
                    if ($name === 'methods') {
                        $values = $argument->value instanceof Expr\Array_ ? array_map(static fn (AstNode\ArrayItem $item): Expr => $item->value, $argument->value->items) : [$argument->value];
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
                    'routeName' => $routeName,
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

    /**
     * A string written as such or as a class name: `'app.mailer'`, `Mailer::class`.
     */
    private function literalOrClass(?Expr $expr): ?string
    {
        return $expr instanceof AstNode\Scalar\String_ ? $expr->value : $this->classConstant($expr);
    }

    private function classConstant(?Expr $expr): ?string
    {
        return $expr instanceof Expr\ClassConstFetch && $expr->class instanceof Name && $expr->name instanceof Identifier
            && strtolower($expr->name->toString()) === 'class'
            ? $this->resolveName($expr->class)
            : null;
    }

    /**
     * What a call passes, by position, up to the first unpacked or named argument: `null`, `class:Foo` (Foo::class),
     * `fn` (a closure) or `?`, and the types known: of a value, or what a closure returns.
     *
     * @param array<AstNode\Arg|AstNode\ArgPlaceholder|AstNode\VariadicPlaceholder> $args
     *
     * @return array{list<string>, array<int, TypeExpr>}
     */
    private function callArgumentsOf(array $args): array
    {
        $arguments = $types = [];
        foreach (array_values($args) as $position => $argument) {
            if (!$argument instanceof AstNode\Arg || $argument->unpack || $argument->name !== null) {
                break;
            }
            $value = $argument->value;
            // A class named by a string, as WordPress does: `_get_list_table('WP_Posts_List_Table')`.
            $class = $this->classConstant($value)
                ?? ($value instanceof AstNode\Scalar\String_ && preg_match('/^\\\\?[A-Z][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $value->value) === 1 ? ltrim($value->value, '\\') : null);
            $isClosure = $value instanceof Expr\Closure || $value instanceof Expr\ArrowFunction;
            $arguments[] = match (true) {
                $class !== null => 'class:' . $class,
                $value instanceof Expr\ConstFetch && strtolower($value->name->toString()) === 'null' => 'null',
                $isClosure => 'fn',
                default => '?',
            };
            $type = match (true) {
                $isClosure => $this->closureReturn($value),
                $class === null => $this->typeOf($value),
                default => null,
            };
            // Bounded: `$html = apply_filters('x', $html, $html)` repeated would nest the type in itself, doubling it.
            if ($type !== null && \strlen($type->key()) <= self::ARGUMENT_KEY) {
                $types[$position] = $type;
            }
        }

        return [$arguments, $types];
    }

    /**
     * What a closure argument returns: read with its body (onClosureReturn), or before it when it does not depend on
     * the body, as the chain `transaction(fn (): Order => ...)->ship()` needs: its native return type, the expression
     * of an arrow function without parameters (it sees the variables of the scope it is written in).
     */
    private function closureReturn(Expr\Closure|Expr\ArrowFunction $closure): ?TypeExpr
    {
        $id = spl_object_id($closure);
        if (\array_key_exists($id, $this->closureReturns)) {
            return $this->closureReturns[$id];
        }
        $native = $this->singleType($closure->returnType);
        if ($native !== null) {
            return TypeExpr::named($native);
        }

        return $closure instanceof Expr\ArrowFunction && $closure->params === [] ? $this->typeOf($closure->expr) : null;
    }

    /**
     * The arguments of a method call, when they may bind the templates of the method: none when nothing is known of
     * them, so that the call keeps the key it has without them.
     *
     * @param array<AstNode\Arg|AstNode\ArgPlaceholder|AstNode\VariadicPlaceholder> $args
     *
     * @return array{list<string>, array<int, TypeExpr>}
     */
    private function bindingArguments(array $args): array
    {
        [$arguments, $types] = $this->callArgumentsOf($args);

        return $types === [] && array_filter($arguments, static fn (string $argument): bool => str_starts_with($argument, 'class:')) === [] ? [[], []] : [$arguments, $types];
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

            $type = $this->localTypes[$expr->name] ?? null;

            return $type === null || $type->isUnknown() ? null : $type;
        }

        if ($expr instanceof Expr\Ternary || $expr instanceof Expr\BinaryOp\Coalesce || $expr instanceof Expr\Match_) {
            $type = $this->valueOf($expr);

            return $type === null || $type->isUnknown() ? null : $type;
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
            if ($receiver === null) {
                return null;
            }
            [$arguments, $types] = $this->bindingArguments($expr->args);

            return TypeExpr::returnOf($receiver, $expr->name->toString(), $arguments, $types);
        }

        if ($expr instanceof Expr\StaticCall && $expr->class instanceof Name && $expr->name instanceof Identifier) {
            $class = $this->resolveName($expr->class);
            if ($class === null) {
                return null;
            }
            [$arguments, $types] = $this->bindingArguments($expr->args);

            return TypeExpr::returnOf(TypeExpr::named($class), $expr->name->toString(), $arguments, $types);
        }

        if ($expr instanceof Expr\FuncCall && $expr->name instanceof Name) {
            // An unqualified name in a namespace: that namespace's function, else the global one.
            $namespaced = $expr->name->getAttribute('namespacedName');
            $names = $expr->name->isFullyQualified() || !$namespaced instanceof Name
                ? [$expr->name->toString()]
                : [$namespaced->toString(), $expr->name->toString()];
            [$arguments, $types] = $this->callArgumentsOf($expr->args);

            return TypeExpr::returnOfFunction($names, $arguments, $types);
        }

        if ($expr instanceof Expr\New_ && $expr->class instanceof Name) {
            $class = $this->resolveName($expr->class);

            return $class === null ? null : TypeExpr::named($class);
        }

        if ($expr instanceof Expr\Clone_) {
            return $this->typeOf($expr->expr);
        }

        if ($expr instanceof Expr\Include_) {
            $files = $this->includedFiles($expr->expr);

            return $files === [] ? null : TypeExpr::included($files);
        }

        if ($expr instanceof Expr\Assign) {
            return $this->typeOf($expr->expr);
        }

        return null;
    }

    /**
     * The attributes an Eloquent model casts, from its `$casts` property or its `casts()` method, and the class each
     * becomes: a date (Carbon), a collection, an enum or a value object (`X::class`, a cast class resolved later).
     *
     * @return array<string, string>
     */
    private function collectCasts(Stmt\ClassLike $node): array
    {
        if (!$node instanceof Stmt\Class_ || $node->extends === null) {
            return [];
        }
        $array = null;
        foreach ($node->stmts as $statement) {
            if ($statement instanceof Stmt\Property && $statement->props[0]->name->toString() === 'casts' && $statement->props[0]->default instanceof Expr\Array_) {
                $array = $statement->props[0]->default;
            } elseif ($statement instanceof Stmt\ClassMethod && strtolower($statement->name->toString()) === 'casts') {
                foreach ($statement->stmts ?? [] as $inner) {
                    if ($inner instanceof Stmt\Return_ && $inner->expr instanceof Expr\Array_) {
                        $array = $inner->expr;
                    }
                }
            }
        }
        $casts = [];
        foreach ($array === null ? [] : $array->items as $item) {
            if (!$item->key instanceof AstNode\Scalar\String_) {
                continue;
            }
            $value = $item->value;
            // `AsCollection::class . ':' . Item::class`, `AsCollection::of(Item::class)`: the cast class.
            while ($value instanceof Expr\BinaryOp\Concat) {
                $value = $value->left;
            }
            if ($value instanceof Expr\StaticCall && $value->class instanceof Name) {
                $class = $this->resolveName($value->class);
            } else {
                $class = $this->classConstant($value);
            }
            if ($class === null && $value instanceof AstNode\Scalar\String_) {
                $class = self::CASTS[strtolower(explode(':', $value->value)[0])] ?? null;
                if (str_starts_with(strtolower($value->value), 'encrypted:')) {
                    $class = self::CASTS[strtolower(explode(':', $value->value)[1])] ?? null;
                }
            }
            if ($class !== null) {
                $casts[$item->key->value] = $class;
            }
        }

        return $casts;
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
        // Magic properties the class documents: `@property-read Book $book`.
        $documented = $node->getAttribute(DocTypeResolver::DOC_PROPERTIES);
        foreach (\is_array($documented) ? $documented : [] as $name => $property) {
            $class = \is_array($property) && \is_string($property[0] ?? null) && $property[0] !== TypeExpr::STATIC ? $this->documentedClass($property[0]) : null;
            if ($class !== null) {
                $types[(string) $name] ??= $class;
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
                if (!\is_string($type)) {
                    continue;
                }
                foreach ($statement->props as $property) {
                    $generics[$property->name->toString()] = $type;
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
        $documented = $node->getAttribute(DocTypeResolver::DOC_PROPERTIES);
        foreach (\is_array($documented) ? $documented : [] as $name => $property) {
            if (\is_array($property) && \is_string($property[1] ?? null)) {
                $generics[(string) $name] ??= $property[1];
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
     * The GenericType a callable returns: documented, or a union declared in the code. A documented union gives way to
     * a single class declared in the code (`: ResponseInterface` with `@return Response|JsonResponse`): its members
     * may each declare the method a call needs, the declared class always does.
     */
    private function returnGeneric(Stmt\ClassMethod|Stmt\Function_ $node): ?string
    {
        $documented = $node->getAttribute(DocTypeResolver::RETURN_GENERIC);
        if (!\is_string($documented)) {
            return $this->nativeUnion($node->returnType, true);
        }

        return GenericType::isUnion($documented) && $this->singleType($node->returnType) !== null ? null : $documented;
    }

    /**
     * A union of classes declared in the code, `JsonResponse|AuthenticatedIdentity`, as a GenericType: a call on it
     * goes to the members declaring its method. Scalars are left out; null for one class or none. With
     * $intersections, an intersection (`Countable&Named`, `(A&B)|C`) is read as a union of its classes too: an object
     * of all of them has the method any of them declares.
     */
    private function nativeUnion(?AstNode $type, bool $intersections = false): ?string
    {
        if (!$type instanceof UnionType && !($intersections && $type instanceof IntersectionType)) {
            return null;
        }
        $names = [];
        foreach ($type->types as $inner) {
            if ($inner instanceof IntersectionType && !$intersections) {
                return null;
            }
            array_push($names, ...$this->typeNames($inner));
        }
        $names = array_values(array_unique($names));

        return \count($names) > 1 ? implode('|', $names) : null;
    }

    /**
     * A node attribute holding names to strings, as DocTypeResolver sets them.
     *
     * @return array<string, string>
     */
    private static function stringMap(mixed $attribute): array
    {
        $map = [];
        foreach (\is_array($attribute) ? $attribute : [] as $key => $value) {
            if (\is_string($value)) {
                $map[(string) $key] = $value;
            }
        }

        return $map;
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

    /**
     * The project files a `require` or an `include` may load, the first found wins: `__DIR__ . '/../bootstrap/app.php'`
     * is one file; a relative path, `'config.php'`, is looked for next to the script, then from the project root (the
     * include path is not known). None when the path is computed or leaves the project.
     *
     * @return list<string>
     */
    private function includedFiles(Expr $expr): array
    {
        $path = $this->includedPath($expr);
        if ($path === null || $path === '' || str_starts_with($path, '/')) {
            return [];
        }
        if ($path[0] === "\0") {
            $files = [self::normalizedPath(substr($path, 1))];
        } else {
            $directory = \dirname($this->path);
            $files = [self::normalizedPath(($directory === '.' ? '' : $directory . '/') . $path), self::normalizedPath($path)];
        }

        return array_values(array_unique(array_map(static fn (string $file): string => 'file:' . $file, array_filter($files, 'is_string'))));
    }

    /**
     * The path an expression gives, from string literals, `__DIR__`, `__FILE__` and `dirname()`; a path built from the
     * file's own location starts with "\0", as it is relative to the project root.
     */
    private function includedPath(Expr $expr, int $depth = 0): ?string
    {
        if ($depth > 10) {
            return null;
        }

        return match (true) {
            $expr instanceof AstNode\Scalar\String_ => $expr->value,
            $expr instanceof AstNode\Scalar\MagicConst\Dir => "\0" . \dirname($this->path),
            $expr instanceof AstNode\Scalar\MagicConst\File => "\0" . $this->path,
            $expr instanceof Expr\BinaryOp\Concat => $this->concatenatedPath($expr, $depth),
            $expr instanceof Expr\FuncCall && $expr->name instanceof Name && $expr->name->toLowerString() === 'dirname'
                && ($expr->args[0] ?? null) instanceof AstNode\Arg => $this->dirnameOf($expr, $depth),
            default => null,
        };
    }

    private function concatenatedPath(Expr\BinaryOp\Concat $concat, int $depth): ?string
    {
        $left = $this->includedPath($concat->left, $depth + 1);
        if ($left === null) {
            return null;
        }
        $right = $this->includedPath($concat->right, $depth + 1);
        // A location of its own on the right (`'x' . __DIR__`) is no path.
        if ($right === null || str_starts_with($right, "\0")) {
            return null;
        }

        return $left . $right;
    }

    private function dirnameOf(Expr\FuncCall $call, int $depth): ?string
    {
        \assert($call->args[0] instanceof AstNode\Arg);
        $path = $this->includedPath($call->args[0]->value, $depth + 1);
        $levels = ($call->args[1] ?? null) instanceof AstNode\Arg && $call->args[1]->value instanceof AstNode\Scalar\Int_ ? $call->args[1]->value->value : 1;

        // Its parent, the `..` resolved with the rest of the path.
        return $path === null || !str_starts_with($path, "\0") || $levels < 1 || $levels > 10 ? null : $path . str_repeat('/..', $levels);
    }

    /**
     * A path relative to the project root without `.` and `..`, null when it leaves the project.
     */
    private static function normalizedPath(string $path): ?string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }
                array_pop($segments);
            } else {
                $segments[] = $segment;
            }
        }

        return $segments === [] ? null : implode('/', $segments);
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
