<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

use PhpGraph\Extractor\GenericType;
use PhpGraph\Extractor\TypeExpr;
use PhpGraph\Graph\Graph;
use PhpGraph\Graph\Relation;
use PhpGraph\Vendor\ClassSignature;
use PhpGraph\Vendor\VendorSignatures;

/**
 * Resolves the types the extractor could not finish alone, once every file is known: the declared return type
 * of a method and the declared type of a property, looked up through inheritance.
 *
 * A class the project does not declare is looked up in the dependency signatures, when they are available: a
 * project class extending a vendor class inherits its methods, and a chain goes on through vendor return types.
 * Methods found there are not project methods: the graph gets no node and no edge for them.
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
    ) {
    }

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
     * The type with its arguments: `ScalarNodeDefinition<NodeBuilder<ArrayNodeDefinition<TreeBuilder>>>`, so that
     * the next call of a chain binds the templates of its class (`end()` returns `TParent`).
     */
    private function resolveGeneric(TypeExpr $type, bool &$leftProject): ?string
    {
        if ($type->receiver === null) {
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
        $receiver = GenericType::base($receiverType);
        if (!GenericType::isClass($receiver)) {
            return null;
        }

        if ($type->isProperty) {
            $generic = $this->genericProperty($receiverType, $type->member);
            if ($generic !== null) {
                return $generic;
            }
            $property = $this->findProperty($receiver, $type->member);
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

        if ($type->isElement) {
            $element = $this->returnElements[$method] ?? null;
            if ($element !== null) {
                return $this->names->canonical($element);
            }
            $returned = $this->genericReturn($method, $receiverType);

            return $returned === null ? null : $this->elementOf($returned);
        }

        $generic = $this->genericReturn($method, $receiverType);
        if ($generic !== null) {
            return $generic;
        }

        $returned = $this->returnTypes[$method] ?? $this->vendorReturnType($method);
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
     * What a method documented with a generic type returns to this receiver: its templates bound by the receiver's
     * arguments, through the parents that pass them on.
     */
    private function genericReturn(string $method, string $receiverType): ?string
    {
        $owner = substr($method, 0, (int) strrpos($method, '::'));
        $generic = $this->generics->returns[$method] ?? $this->vendorSignature($owner)?->genericReturns[$method] ?? null;
        if ($generic === null) {
            return null;
        }
        $returned = GenericType::substitute($generic, $this->bindings($receiverType, $owner), $receiverType);

        return $returned === null ? null : $this->canonicalType($returned);
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
    private function bindings(string $type, string $class): array
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
            $this->methods[$key] = $this->search(
                $class,
                fn (string $owner, bool $withVendor): ?string => $this->methodsByClass[$owner][$name]
                    ?? ($withVendor ? $this->vendorSignature($owner)?->methods[$name] ?? null : null),
                [Relation::Extends, Relation::Implements, Relation::UsesTrait],
            );
        }

        return $this->methods[$key];
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
