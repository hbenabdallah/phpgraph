<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

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
     */
    public function __construct(
        private readonly Graph $graph,
        private readonly NameCanonicalizer $names,
        private readonly array $methodsByClass,
        private readonly array $returnTypes,
        private readonly array $propertyTypes,
        private readonly ?VendorSignatures $vendor = null,
    ) {
    }

    /**
     * @param bool $leftProject set when the chain stops at a member the project does not declare: one missing
     *                          from the project and its dependencies, or a dependency member without a usable type
     */
    public function resolve(TypeExpr $type, bool &$leftProject = false): ?string
    {
        if ($type->receiver === null) {
            return $type->className === null ? null : $this->names->canonical($type->className);
        }

        $receiver = $this->resolve($type->receiver, $leftProject);
        if ($receiver === null || $type->member === null) {
            return null;
        }

        if ($type->isProperty) {
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

        $returned = $this->returnTypes[$method] ?? $this->vendorReturnType($method);
        if ($returned === null && !$this->graph->hasNode($method)) {
            $leftProject = true;
        }

        return match ($returned) {
            null => null,
            TypeExpr::STATIC => $receiver,
            default => $this->names->canonical($returned),
        };
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
