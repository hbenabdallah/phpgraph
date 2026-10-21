<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

use PhpGraph\Builder\Libraries\Libraries;
use PhpGraph\Extractor\GenericType;
use PhpGraph\Extractor\TypeExpr;
use PhpGraph\Graph\Graph;
use PhpGraph\Graph\NodeKind;
use PhpGraph\Graph\Relation;
use PhpGraph\Values;
use PhpGraph\Vendor\ClassSignature;
use PhpGraph\Vendor\VendorSignatures;

/**
 * Resolves the types the extractor could not finish alone, once every file is known: the declared return type
 * of a method and the declared type of a property, looked up through inheritance.
 *
 * A class the project does not declare is looked up in the dependency signatures, when they are available: a
 * project class extending a vendor class inherits its methods, and a chain goes on through vendor return types.
 * Methods found there are not project methods: the graph gets no node and no edge for them. What a library makes
 * of a call its signature does not say (a container's `get(Mailer::class)`, an Eloquent attribute) comes from its
 * provider (Libraries).
 */
final class TypeResolver
{
    /** @var array<string, ?string> */
    private array $methods = [];

    /** @var array<string, ?string> */
    private array $properties = [];

    /** @var array<string, list<string>> */
    private array $lineages = [];

    /**
     * Parents by class, relations and vendor flag. Looking them up walks every edge of the class: thousands for a base
     * class many classes extend, so they are computed once.
     *
     * @var array<string, list<string>>
     */
    private array $parents = [];

    /**
     * @param array<string, array<string, string>> $methodsByClass class to lowercase method name to method id
     * @param array<string, string>                $returnTypes    method id to class, or TypeExpr::STATIC
     * @param array<string, string>                $propertyTypes  "Class::property" to class
     * @param array<string, string>                $returnElements method id to the class of the elements it returns
     */
    public function __construct(
        private readonly Graph $graph,
        private readonly NameCanonicalizer $names,
        private readonly array $methodsByClass,
        private readonly array $returnTypes,
        private readonly array $propertyTypes,
        private readonly ?VendorSignatures $vendor = null,
        private readonly array $returnElements = [],
        private readonly Generics $generics = new Generics(),
        ?Libraries $libraries = null,
    ) {
        $this->libraries = $libraries ?? Libraries::defaults($generics);
    }

    private readonly Libraries $libraries;

    /** @var array<string, list<string>> */
    private array $supertypes = [];

    /**
     * Resolved expressions, by key: each call of a chain resolves its receiver, the chain before it, again.
     *
     * @var array<string, array{?string, bool}>
     */
    private array $resolved = [];

    /**
     * @param bool $leftProject set when the chain stops at a member the project does not declare: one missing
     *                          from the project and its dependencies, or a dependency member without a usable type
     */
    public function resolve(TypeExpr $type, bool &$leftProject = false): ?string
    {
        $resolved = $this->resolveGeneric($type, $leftProject);
        $base = $resolved === null ? null : GenericType::base($resolved);

        return $base !== null && GenericType::isClass($base) ? $base : null;
    }

    /**
     * The classes a type may be: each member of a union, the class itself otherwise; and whether every member is
     * known (no branch left it unknown).
     *
     * @return array{list<string>, bool}
     */
    public function classesOf(TypeExpr $type): array
    {
        $left = false;
        $resolved = $this->resolveGeneric($type, $left);
        if ($resolved === null) {
            return [[], false];
        }
        $classes = [];
        $complete = true;
        foreach (GenericType::members($resolved) as $member) {
            $base = GenericType::base($member);
            if (GenericType::isClass($base)) {
                $classes[] = $base;
            }
            $complete = $complete && $member !== GenericType::UNKNOWN;
        }

        return [array_values(array_unique($classes)), $complete];
    }

    /**
     * The classes a method may be called on: the members of a union declaring it, each declaration once (members
     * inheriting the same method give one). Complete when every member of the receiver is known; a branch leaving it
     * of unknown type makes it partial.
     *
     * @return array{list<string>, bool}
     */
    public function receivers(TypeExpr $type, string $method, bool &$leftProject = false): array
    {
        $resolved = $this->resolveGeneric($type, $leftProject);
        if ($resolved === null) {
            return [[], false];
        }
        [$members, $complete] = $this->declaring($resolved, $method, false);
        $classes = [];
        foreach ($members as $member) {
            $base = GenericType::base($member);
            if (GenericType::isClass($base) && !\in_array($base, $classes, true)) {
                $classes[] = $base;
            }
        }

        return [$classes, $complete];
    }

    /**
     * The members of a union a method is called on (or a property read on): those declaring it, one per declaration.
     * A type that is no union is its own member. Members declaring nothing are left out: the code calling the method
     * holds one of the others. An unknown member (`?`, a branch of unknown type) makes the answer incomplete.
     *
     * @return array{list<string>, bool}
     */
    private function declaring(string $type, string $member, bool $isProperty): array
    {
        if (!GenericType::isUnion($type)) {
            return [[$type], true];
        }

        $chosen = [];
        $found = [];
        $complete = true;
        foreach (GenericType::members($type) as $candidate) {
            $class = GenericType::base($candidate);
            if (!GenericType::isClass($class)) {
                $complete = $complete && $candidate !== GenericType::UNKNOWN;
                continue;
            }
            $declared = $isProperty
                ? ($this->genericProperty($candidate, $member) ?? $this->findProperty($class, $member))
                : $this->findMethod($class, strtolower($member));
            if ($declared !== null && !isset($found[$declared])) {
                $found[$declared] = true;
                $chosen[] = $candidate;
            }
        }

        return [$chosen, $complete];
    }

    /**
     * Members of a union, as a GenericType: duplicates and members whose parent is a member too are left out
     * (`Circle|Shape` is a Shape), `?` stays for an unknown one. Null when nothing is known.
     *
     * @param list<string> $members
     */
    private function simplified(array $members): ?string
    {
        $members = array_values(array_unique($members));
        $classes = array_filter($members, static fn (string $member): bool => GenericType::isClass(GenericType::base($member)));
        $kept = [];
        foreach ($members as $member) {
            $base = GenericType::base($member);
            if (GenericType::isClass($base)) {
                foreach ($classes as $other) {
                    $parent = GenericType::base($other);
                    if (strtolower($parent) !== strtolower($base) && $this->isA($base, $parent)) {
                        continue 2;
                    }
                }
            }
            $kept[] = $member;
        }

        return $kept === [] || $kept === [GenericType::UNKNOWN] ? null : GenericType::union($kept);
    }

    /**
     * What an `instanceof` test leaves of a type. Narrowed to the classes tested: a member that is one of them stays
     * (with its arguments), a member one of them extends gives way to it, an unknown member becomes them; a single
     * type unrelated to the class may still be both when one of them is an interface (or not known): both are kept,
     * a call is linked to the one declaring its method. Without the classes: the members that are one of them go.
     *
     * A call asserting what an argument is (`@phpstan-assert-if-true WP_Error $thing`) narrows it the same way, to
     * the classes its callable asserts; the subject stays as it is when it asserts nothing.
     *
     * @param array{TypeExpr, list<string>, bool, 3?: array{TypeExpr, int, bool}} $test
     */
    private function narrowed(array $test, bool &$leftProject): ?string
    {
        [$tested, $classes, $excluding] = $test;
        $subject = $this->resolveGeneric($tested, $leftProject);
        if (isset($test[3])) {
            $classes = $this->asserted($test[3][0], $test[3][1], match (true) {
                $excluding => ['false'],
                $test[3][2] => ['always'],
                default => ['always', 'true'],
            });
            if ($classes === []) {
                return $subject;
            }
            $excluding = false;
        }
        $classes = array_map(fn (string $class): string => $this->names->canonical($class), $classes);
        if ($excluding) {
            if ($subject === null || !GenericType::isUnion($subject)) {
                return $subject;
            }
            $kept = [];
            foreach (GenericType::members($subject) as $member) {
                $base = GenericType::base($member);
                if (!GenericType::isClass($base) || array_filter($classes, fn (string $class): bool => $this->isA($base, $class)) === []) {
                    $kept[] = $member;
                }
            }

            return $kept === [] ? $subject : $this->simplified($kept);
        }

        $members = $subject === null ? [GenericType::UNKNOWN] : GenericType::members($subject);
        $kept = [];
        foreach ($members as $member) {
            $base = GenericType::base($member);
            if (!GenericType::isClass($base)) {
                array_push($kept, ...$classes);
                continue;
            }
            foreach ($classes as $class) {
                if ($this->isA($base, $class)) {
                    $kept[] = $member;
                } elseif ($this->isA($class, $base)) {
                    $kept[] = $class;
                } elseif (\count($members) === 1 && ($this->mayBeInterface($base) || $this->mayBeInterface($class))) {
                    array_push($kept, $member, $class);
                }
            }
        }

        return $this->simplified($kept === [] ? $classes : $kept);
    }

    /**
     * The classes a call asserts an argument is, by its callable's `@phpstan-assert` / `@psalm-assert` tags of the
     * kinds given; a template of the callable is bound by the call (`Assert::isInstanceOf($x, Order::class)` with
     * `@psalm-param class-string<ExpectedType> $class`). The callable is the project's function or method first, then
     * the dependencies'.
     *
     * @param list<string> $kinds `always`, `true`, `false`
     *
     * @return list<string>
     */
    private function asserted(TypeExpr $call, int $position, array $kinds): array
    {
        $found = [];
        if ($call->functions !== []) {
            foreach ($call->functions as $name) {
                $id = $this->names->canonical($name);
                $assertions = $this->generics->assertions[$id] ?? null;
                $binders = $this->generics->binders[$id] ?? [];
                if ($assertions === null && $this->vendor !== null) {
                    $assertions = $this->vendor->functionAssertions($name);
                    $binders = $this->vendor->function($name)[3] ?? [];
                }
                if ($assertions !== null && $assertions !== []) {
                    $found[] = [$assertions, $binders];
                    break;
                }
            }
        } elseif ($call->receiver !== null && $call->member !== null && !$call->isProperty) {
            $left = false;
            [$classes] = $this->receivers($call->receiver, $call->member, $left);
            foreach ($classes as $class) {
                $method = $this->findMethod($class, strtolower($call->member));
                if ($method === null) {
                    continue;
                }
                $owner = substr($method, 0, (int) strrpos($method, '::'));
                $assertions = $this->generics->assertions[$method] ?? $this->vendorSignature($owner)->assertions[$method] ?? [];
                $binders = $this->generics->binders[$method] ?? $this->vendorSignature($owner)->templateBinders[$method] ?? [];
                $found[] = [$assertions, $binders];
            }
        }

        $asserted = [];
        foreach ($found as [$assertions, $binders]) {
            foreach ($assertions as [$kind, $parameter, $type]) {
                if ($parameter !== $position || !\in_array($kind, $kinds, true)) {
                    continue;
                }
                $bound = GenericType::substitute($type, $binders === [] ? [] : $this->methodBindings($binders, $call), null);
                foreach ($bound === null ? [] : GenericType::members($this->canonicalType($bound)) as $member) {
                    $base = GenericType::base($member);
                    if (GenericType::isClass($base) && !\in_array($base, $asserted, true)) {
                        $asserted[] = $base;
                    }
                }
            }
        }

        return $asserted;
    }

    /**
     * Whether the class is the parent, extends it or implements it, in the project or its dependencies.
     */
    public function isA(string $class, string $parent): bool
    {
        $parent = strtolower($parent);
        foreach ($this->lineage($class) as $ancestor) {
            if (strtolower($ancestor) === $parent) {
                return true;
            }
        }

        return false;
    }

    private function mayBeInterface(string $class): bool
    {
        $node = $this->graph->node($class);

        return $node === null || $node->kind === NodeKind::PhpInterface;
    }

    /**
     * The type with its arguments: `ScalarNodeDefinition<NodeBuilder<ArrayNodeDefinition<TreeBuilder>>>`, so that
     * the next call of a chain binds the templates of its class (`end()` returns `TParent`).
     */
    private function resolveGeneric(TypeExpr $type, bool &$leftProject): ?string
    {
        if ($type->receiver === null && $type->members === [] && $type->test === null) {
            return $this->resolveUncached($type, $leftProject);
        }

        $key = $type->key();
        if (!isset($this->resolved[$key])) {
            $left = false;
            $this->resolved[$key] = [$this->resolveUncached($type, $left), $left];
        }
        [$resolved, $left] = $this->resolved[$key];
        $leftProject = $leftProject || $left;

        return $resolved;
    }

    private function resolveUncached(TypeExpr $type, bool &$leftProject): ?string
    {
        if ($type->functions !== []) {
            return $this->functionReturn($type);
        }
        if ($type->members !== []) {
            $members = [];
            foreach ($type->members as $member) {
                $resolved = $this->resolveGeneric($member, $leftProject);
                array_push($members, ...($resolved === null ? [GenericType::UNKNOWN] : GenericType::members($resolved)));
            }

            return $this->simplified($members);
        }
        if ($type->test !== null) {
            return $this->narrowed($type->test, $leftProject);
        }
        if ($type->receiver === null) {
            if ($type->generic !== null) {
                return $this->canonicalType($type->generic);
            }

            return $type->className === null ? null : $this->names->canonical($type->className);
        }

        $receiverType = $this->resolveGeneric($type->receiver, $leftProject);
        if ($receiverType === null) {
            return null;
        }
        if ($type->member === null) {
            return $this->elementOf($receiverType);
        }
        [$members, $complete] = $this->declaring($receiverType, $type->member, $type->isProperty);
        if (\count($members) === 1 && $complete) {
            return $this->memberType($members[0], $type, $leftProject);
        }
        // Several members declaring it, or an unknown one: what each gives, an unknown one staying unknown.
        $types = $complete ? [] : [GenericType::UNKNOWN];
        foreach ($members as $member) {
            $resolved = $this->memberType($member, $type, $leftProject);
            array_push($types, ...($resolved === null ? [GenericType::UNKNOWN] : GenericType::members($resolved)));
        }

        return $members === [] ? null : $this->simplified($types);
    }

    /**
     * The type of a member (property, method return, element, closure parameter) of a receiver that is no union.
     */
    private function memberType(string $receiverType, TypeExpr $type, bool &$leftProject): ?string
    {
        \assert($type->member !== null);
        $receiver = GenericType::base($receiverType);
        if (!GenericType::isClass($receiver)) {
            return null;
        }

        if ($type->isProperty) {
            $generic = $this->genericProperty($receiverType, $type->member);
            if ($generic !== null) {
                return $generic;
            }
            $property = $this->findProperty($receiver, $type->member) ?? $this->libraries->propertyType($receiver, $type, $this);
            if ($property === null) {
                $leftProject = true;
            }

            return $property;
        }

        $method = $this->findMethod($receiver, strtolower($type->member));
        if ($method === null) {
            $leftProject = true;

            return null;
        }
        if ($type->closure !== null) {
            return $this->closureParameter($method, $receiverType, ...$type->closure);
        }

        if ($type->isElement) {
            $element = $this->returnElements[$method] ?? null;
            if ($element !== null) {
                return $this->names->canonical($element);
            }
            $returned = $this->genericReturn($method, $receiverType, $type);

            return $returned === null ? null : $this->elementOf($returned);
        }

        $provided = $this->libraries->methodReturn($receiver, $method, $type, $this);
        if ($provided !== null) {
            return $this->canonicalType($provided);
        }
        $generic = $this->genericReturn($method, $receiverType, $type);
        if ($generic !== null) {
            return $generic;
        }

        $returned = $this->returnTypes[$method] ?? $this->vendorReturnType($method);
        if ($returned === null) {
            $inherited = $this->inheritedReturn($method, $receiverType, $type);
            if ($inherited !== null) {
                return $inherited;
            }
        }
        if ($returned === null && !$this->graph->hasNode($method)) {
            $leftProject = true;
        }

        return match ($returned) {
            null => null,
            TypeExpr::STATIC => $receiverType,
            default => $this->names->canonical($returned),
        };
    }

    /**
     * What a method declaring no return type returns, as the method it overrides declares it (`@inheritDoc`):
     * `BelongsTo::getResults()` returns the `TResult` of Relation, `?TRelatedModel` there.
     */
    private function inheritedReturn(string $method, string $receiverType, TypeExpr $call): ?string
    {
        $name = strtolower(substr($method, (int) strrpos($method, '::') + 2));
        $owner = strtolower(substr($method, 0, (int) strrpos($method, '::')));
        $below = true;
        foreach ($this->supertypes($receiverType) as $supertype) {
            $class = GenericType::base($supertype);
            if ($below) {
                $below = strtolower($class) !== $owner;
                continue;
            }
            $parent = $this->methodsByClass[$class][$name] ?? $this->vendorSignature($class)?->methods[$name] ?? null;
            if ($parent === null || $parent === $method) {
                continue;
            }
            $generic = $this->genericReturn($parent, $receiverType, $call);
            if ($generic !== null) {
                return $generic;
            }
            $returned = $this->returnTypes[$parent] ?? $this->vendorReturnType($parent);
            if ($returned !== null) {
                return $returned === TypeExpr::STATIC ? $receiverType : $this->names->canonical($returned);
            }
        }

        return null;
    }

    /**
     * What a function returns to this call: the project's function first, then a function of the packages; a
     * conditional `@return` is evaluated against the arguments (`response()` without content: the factory).
     *
     * A template of the function bound by the arguments (`@param T $value`, `@return T`) gives the argument's type.
     */
    private function functionReturn(TypeExpr $call): ?string
    {
        foreach ($call->functions as $name) {
            $id = $this->names->canonical($name);
            [$returned, $generic, $conditional, $binders] = isset($this->returnTypes[$id]) || isset($this->generics->conditionals[$id]) || isset($this->generics->returns[$id])
                ? [$this->returnTypes[$id] ?? null, $this->generics->returns[$id] ?? null, $this->generics->conditionals[$id] ?? null, $this->generics->binders[$id] ?? []]
                : ($this->vendor?->function($name) ?? [null, null, null, []]);
            if ($conditional !== null) {
                $decided = json_decode($conditional, true);

                return \is_array($decided) ? $this->decide($decided, $call->arguments) : null;
            }
            if ($generic !== null) {
                $bound = GenericType::substitute($generic, $this->methodBindings($binders, $call), null);
                if ($bound !== null || $returned === null) {
                    return $bound === null ? null : $this->canonicalType($bound);
                }
            }
            if ($returned !== null) {
                return $this->canonicalType($returned);
            }
        }

        return null;
    }

    /**
     * The templates of a method or a function a call binds by its arguments, as `#T` (DocTypeResolver::TEMPLATE_BINDERS):
     * the class an argument names (`class-string<T>`), the type of a value (`T $value`), what a closure returns
     * (`callable(): T`).
     *
     * @param array<string, array{string, int}> $binders
     *
     * @return array<string, string>
     */
    private function methodBindings(array $binders, TypeExpr $call): array
    {
        $bindings = [];
        foreach ($binders as $template => [$kind, $position]) {
            $argument = $call->arguments[$position] ?? null;
            $type = $call->argumentTypes[$position] ?? null;
            $left = false;
            $bound = match (true) {
                $kind === 'class' => $argument !== null && str_starts_with($argument, 'class:') ? $this->names->canonical(substr($argument, 6)) : null,
                $type === null || ($kind === 'closure') !== ($argument === 'fn') => null,
                default => $this->resolveGeneric($type, $left),
            };
            if ($bound !== null) {
                $bindings['#' . $template] = $bound;
            }
        }

        return $bindings;
    }

    /**
     * @param array<mixed> $tree      a conditional return type (DocTypeResolver::RETURN_CONDITIONAL)
     * @param list<string> $arguments
     */
    private function decide(array $tree, array $arguments, int $depth = 0): ?string
    {
        if ($depth > 5) {
            return null;
        }
        if (\array_key_exists('type', $tree)) {
            return \is_string($tree['type']) && $tree['type'] !== TypeExpr::STATIC ? $this->names->canonical($tree['type']) : null;
        }
        if (isset($tree['arg'])) {
            $argument = $arguments[Values::number($tree['arg'])] ?? '';

            return str_starts_with($argument, 'class:') ? $this->names->canonical(substr($argument, 6)) : null;
        }
        // Absent, the parameter takes its default: null for the functions written so.
        $argument = $arguments[Values::number($tree['param'] ?? 0)] ?? 'null';
        $holds = match ($tree['test'] ?? null) {
            'null' => $argument === 'null' ? true : ($argument === '?' ? null : false),
            'class' => str_starts_with($argument, 'class:') ? true : ($argument === '?' ? null : false),
            default => null,
        };
        $branch = $holds === null ? null : ($holds ? $tree['then'] ?? null : $tree['else'] ?? null);

        return \is_array($branch) ? $this->decide($branch, $arguments, $depth + 1) : null;
    }

    /**
     * What a method documented with a generic type returns to this receiver: its templates bound by the receiver's
     * arguments, through the parents that pass them on.
     */
    private function genericReturn(string $method, string $receiverType, TypeExpr $call): ?string
    {
        $owner = substr($method, 0, (int) strrpos($method, '::'));
        $generic = $this->generics->returns[$method] ?? $this->vendorSignature($owner)?->genericReturns[$method] ?? null;
        if ($generic === null) {
            return null;
        }
        $binders = $this->generics->binders[$method] ?? $this->vendorSignature($owner)->templateBinders[$method] ?? [];
        $bindings = $this->bindings($receiverType, $owner) + ($binders === [] ? [] : $this->methodBindings($binders, $call));
        $returned = GenericType::substitute($generic, $bindings, $receiverType);

        return $returned === null ? null : $this->canonicalType($returned);
    }

    /**
     * What a method documents it passes to a parameter of the closure it takes, seen from the receiver: `$this` is the
     * receiver, the templates of its class are bound by the receiver's arguments.
     */
    private function closureParameter(string $method, string $receiverType, int|string $argument, int $parameter): ?string
    {
        $owner = substr($method, 0, (int) strrpos($method, '::'));
        $parameters = $this->generics->callables[$method] ?? $this->vendorSignature($owner)->callableParameters[$method] ?? [];
        $documented = $parameters[$argument][$parameter] ?? null;
        if ($documented === null || $documented === GenericType::UNKNOWN) {
            return null;
        }
        $type = GenericType::substitute($documented, $this->bindings($receiverType, $owner), $receiverType);

        return $type === null ? null : $this->canonicalType($type);
    }

    private function genericProperty(string $receiverType, string $name): ?string
    {
        foreach ($this->supertypes($receiverType) as $supertype) {
            [$class, $arguments] = GenericType::parse($supertype);
            $generic = $this->generics->properties[$class . '::' . $name] ?? $this->vendorSignature($class)?->genericProperties[$name] ?? null;
            if ($generic !== null) {
                $type = GenericType::substitute($generic, $this->zip($this->templates($class), $arguments), $receiverType);

                return $type === null ? null : $this->canonicalType($type);
            }
        }

        return null;
    }

    /**
     * The elements of a collection type: its own arguments when it is an array or one of PHP's iterables, otherwise
     * those of the iterable it extends, `Collection<int, Item>` being an `IteratorAggregate<int, Item>`.
     */
    private function elementOf(string $type): ?string
    {
        foreach ($this->supertypes($type) as $supertype) {
            $element = GenericType::element($supertype);
            if ($element !== null) {
                return $element;
            }
        }

        return null;
    }

    /**
     * The bindings of a class's templates as seen from a type extending it: `ScalarNodeDefinition<NodeBuilder>`
     * binds the TParent of NodeDefinition, through `@extends VariableNodeDefinition<TParent>`.
     *
     * @return array<string, string>
     */
    public function bindings(string $type, string $class): array
    {
        $class = strtolower($class);
        foreach ($this->supertypes($type) as $supertype) {
            [$base, $arguments] = GenericType::parse($supertype);
            if (strtolower($base) === $class) {
                return $this->zip($this->templates($base), $arguments);
            }
        }

        return [];
    }

    /**
     * The type and everything it extends, implements or uses, each with the arguments it receives: nearest first.
     *
     * @return list<string>
     */
    private function supertypes(string $type): array
    {
        if (isset($this->supertypes[$type])) {
            return $this->supertypes[$type];
        }

        $queue = [$type];
        $seen = [strtolower(GenericType::base($type)) => true];
        $supertypes = [];
        while ($queue !== [] && \count($supertypes) < 64) {
            $current = array_shift($queue);
            $supertypes[] = $current;
            [$base, $arguments] = GenericType::parse($current);
            if (!GenericType::isClass($base)) {
                continue;
            }
            $bindings = $this->zip($this->templates($base), $arguments);
            $declared = [];
            foreach ($this->parentArguments($base) as $parent => $parentArguments) {
                $declared[strtolower($parent)] = [$parent, $parentArguments];
            }
            $parents = $this->parents($base, [Relation::Extends, Relation::Implements, Relation::UsesTrait], true);
            foreach ($declared as [$parent]) {
                $parents[] = $parent;
            }
            foreach ($parents as $parent) {
                $key = strtolower($parent);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $queue[] = GenericType::format($parent, array_map(
                    static fn (string $argument): string => GenericType::substitute($argument, $bindings, $current) ?? GenericType::UNKNOWN,
                    $declared[$key][1] ?? [],
                ));
            }
        }

        return $this->supertypes[$type] = $supertypes;
    }

    /**
     * @param list<string> $templates
     * @param list<string> $arguments
     *
     * @return array<string, string>
     */
    private function zip(array $templates, array $arguments): array
    {
        $bindings = [];
        foreach ($templates as $index => $template) {
            $argument = $arguments[$index] ?? GenericType::UNKNOWN;
            if ($argument !== GenericType::UNKNOWN) {
                $bindings[$template] = $argument;
            }
        }

        return $bindings;
    }

    /**
     * @return list<string>
     */
    private function templates(string $class): array
    {
        return $this->generics->templates[$class] ?? $this->vendorSignature($class)->templates ?? [];
    }

    /**
     * @return array<string, list<string>>
     */
    private function parentArguments(string $class): array
    {
        return $this->generics->parentArguments[$class] ?? $this->vendorSignature($class)->parentArguments ?? [];
    }

    private function canonicalType(string $type): string
    {
        return GenericType::mapNames($type, fn (string $name): string => $this->names->canonical($name));
    }

    /**
     * The class and everything it extends, implements or uses, in the project and in its dependencies.
     *
     * @return list<string>
     */
    public function lineage(string $class): array
    {
        if (isset($this->lineages[$class])) {
            return $this->lineages[$class];
        }

        $lineage = [$class];
        for ($index = 0; $index < \count($lineage); ++$index) {
            foreach ($this->parents($lineage[$index], [Relation::Extends, Relation::Implements, Relation::UsesTrait], true) as $parent) {
                if (!\in_array($parent, $lineage, true)) {
                    $lineage[] = $parent;
                }
            }
        }

        return $this->lineages[$class] = $lineage;
    }

    /**
     * Whether the class is declared in the project or found among the dependencies.
     */
    public function knows(string $class): bool
    {
        return $this->graph->hasNode($class) || $this->vendorSignature($class) !== null;
    }

    /**
     * The method a class declares or inherits from a parent, an interface or a trait.
     *
     * @param string $name lowercase method name
     */
    public function findMethod(string $class, string $name): ?string
    {
        $key = $class . '::' . $name;
        if (!\array_key_exists($key, $this->methods)) {
            // Set first: a mixin forwarding back to the class does not loop.
            $this->methods[$key] = null;
            $this->methods[$key] = $this->search(
                $class,
                fn (string $owner, bool $withVendor): ?string => $this->methodsByClass[$owner][$name]
                    ?? $this->generics->documentedMethods[$owner][$name]
                    ?? ($withVendor ? $this->vendorSignature($owner)?->methods[$name] ?? null : null),
                [Relation::Extends, Relation::Implements, Relation::UsesTrait],
            ) ?? $this->throughMixins($class, $name);
        }

        return $this->methods[$key];
    }

    /**
     * A method of a class a `@mixin` tag of the class or of its parents names: Eloquent's Builder forwards
     * `orderBy()` to the query builder. A method returning `$this` there returns the receiver, as forwarded calls do.
     */
    private function throughMixins(string $class, string $name): ?string
    {
        foreach ($this->lineage($class) as $owner) {
            foreach ($this->generics->mixins[$owner] ?? $this->vendorSignature($owner)->mixins ?? [] as $mixin) {
                $found = $this->findMethod($this->names->canonical($mixin), $name);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function findProperty(string $class, string $name): ?string
    {
        $key = $class . '::' . $name;
        if (!\array_key_exists($key, $this->properties)) {
            $found = $this->search(
                $class,
                fn (string $owner, bool $withVendor): ?string => $this->propertyTypes[$owner . '::' . $name]
                    ?? ($withVendor ? $this->vendorSignature($owner)?->propertyTypes[$name] ?? null : null),
                [Relation::Extends, Relation::UsesTrait],
            );
            $this->properties[$key] = $found === null ? null : $this->names->canonical($found);
        }

        return $this->properties[$key];
    }

    /**
     * Searches the project hierarchy first, then the dependencies: a member the project declares wins over one a
     * vendor parent declares, as in `class Orders extends VendorRepository implements ProjectOrders`.
     *
     * @param \Closure(string, bool): ?string $declared owner, whether to read vendor signatures
     * @param list<Relation>                  $relations
     */
    private function search(string $class, \Closure $declared, array $relations): ?string
    {
        $visited = [];
        $found = $this->lookUp($class, $visited, $declared, $relations, false);
        if ($found !== null || $this->vendor === null) {
            return $found;
        }

        $visited = [];

        return $this->lookUp($class, $visited, $declared, $relations, true);
    }

    /**
     * @param array<string, true>             $visited
     * @param \Closure(string, bool): ?string $declared
     * @param list<Relation>                  $relations
     */
    private function lookUp(string $class, array &$visited, \Closure $declared, array $relations, bool $withVendor): ?string
    {
        if (isset($visited[$class])) {
            return null;
        }

        $visited[$class] = true;

        $found = $declared($class, $withVendor);
        if ($found !== null) {
            return $found;
        }

        foreach ($this->parents($class, $relations, $withVendor) as $parent) {
            $found = $this->lookUp($parent, $visited, $declared, $relations, $withVendor);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param list<Relation> $relations
     *
     * @return list<string>
     */
    private function parents(string $class, array $relations, bool $withVendor): array
    {
        $key = $class . '|' . implode(',', array_map(static fn (Relation $relation): string => $relation->value, $relations)) . '|' . (int) $withVendor;

        return $this->parents[$key] ??= $this->findParents($class, $relations, $withVendor);
    }

    /**
     * @param list<Relation> $relations
     *
     * @return list<string>
     */
    private function findParents(string $class, array $relations, bool $withVendor): array
    {
        $parents = [];
        foreach ($this->graph->incident($class) as $item) {
            if ($item['forward'] && \in_array($item['edge']->relation, $relations, true)) {
                $parents[] = $item['other'];
            }
        }

        // A vendor signature does not tell which parent is a trait: interfaces bring no property, so this is harmless.
        return $parents === [] && $withVendor ? $this->vendorSignature($class)->parents ?? [] : $parents;
    }

    /**
     * Only for classes the project does not declare: a project class shadows a vendor class of the same name.
     */
    private function vendorSignature(string $class): ?ClassSignature
    {
        if ($this->vendor === null || $this->graph->hasNode($class)) {
            return null;
        }

        return $this->vendor->signature($class);
    }

    private function vendorReturnType(string $method): ?string
    {
        $separator = strrpos($method, '::');
        if ($separator === false) {
            return null;
        }

        return $this->vendorSignature(substr($method, 0, $separator))?->returnTypes[$method] ?? null;
    }
}
