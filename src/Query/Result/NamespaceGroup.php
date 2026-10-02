<?php

declare(strict_types=1);

namespace PhpGraph\Query\Result;

/**
 * A namespace of application code with the layer-like namespace segments found below it.
 */
final readonly class NamespaceGroup
{
    /**
     * @param array<string, int>   $layers   layer-like segment (Domain, Infrastructure...) => classes below it
     * @param list<NamespaceGroup> $children largest sub-namespaces first
     */
    public function __construct(
        public string $name,
        public int $classes,
        public array $layers,
        public array $children = [],
    ) {
    }
}
