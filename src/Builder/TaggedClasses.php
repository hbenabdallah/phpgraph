<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

use PhpGraph\Graph\Graph;
use PhpGraph\Graph\NodeKind;

/**
 * The project classes a container tag applies to: the class of a tagged service id, every class of a tagged namespace
 * resource (`App\Rule\`), or every class that is, extends or implements a type tagged through `_instanceof`,
 * `->instanceof()` or `#[AutoconfigureTag]`.
 */
final class TaggedClasses
{
    /** @var array<string, list<string>> tag name => classes */
    private array $byName = [];

    public function __construct(
        private readonly Graph $graph,
        private readonly NameCanonicalizer $names,
        private readonly TypeResolver $types,
        private readonly ContainerServices $container,
    ) {
    }

    /**
     * @param array{service: string, id: ?string, instanceof: ?string, name: string, attributes: array<string, string>} $tag
     *
     * @return list<string>
     */
    public function of(array $tag): array
    {
        if ($tag['id'] !== null && str_ends_with($tag['id'], '\\')) {
            $namespace = strtolower($this->names->canonical(rtrim($tag['id'], '\\'), $tag['service'])) . '\\';

            return $this->classes(static fn (string $class): bool => str_starts_with(strtolower($class), $namespace));
        }

        if ($tag['id'] !== null) {
            $class = $this->container->classOf($tag['id'], $tag['service']) ?? (str_contains($tag['id'], '\\') ? $tag['id'] : null);
            $class = $class === null ? null : $this->names->canonical($class, $tag['service']);

            return $class !== null && $this->graph->node($class)?->kind === NodeKind::PhpClass ? [$class] : [];
        }

        $type = $this->names->canonical((string) $tag['instanceof'], $tag['service']);

        return $this->classes(fn (string $class): bool => \in_array($type, $this->types->lineage($class), true));
    }

    /**
     * Every class carrying a tag, whatever declared it.
     *
     * @return list<string>
     */
    public function named(string $name): array
    {
        if (!isset($this->byName[$name])) {
            $classes = [];
            foreach ($this->container->tags() as $tag) {
                if ($tag['name'] === $name) {
                    foreach ($this->of($tag) as $class) {
                        $classes[$class] = $class;
                    }
                }
            }
            $this->byName[$name] = array_values($classes);
        }

        return $this->byName[$name];
    }

    /**
     * @param \Closure(string): bool $matches
     *
     * @return list<string>
     */
    private function classes(\Closure $matches): array
    {
        $classes = [];
        foreach ($this->graph->nodes() as $node) {
            if ($node->kind === NodeKind::PhpClass && $matches($node->id)) {
                $classes[] = $node->id;
            }
        }

        return $classes;
    }
}
