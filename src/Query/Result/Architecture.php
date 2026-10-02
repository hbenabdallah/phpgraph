<?php

declare(strict_types=1);

namespace PhpGraph\Query\Result;

/**
 * The layer rules a project breaks and how its bounded contexts depend on each other.
 */
final readonly class Architecture
{
    /**
     * @param list<LayerViolation> $violations         one per pair of classes, sorted by source class
     * @param array<string, int>   $contextDependencies "Sales -> Billing" => class dependencies, largest first
     * @param bool                 $layerFirst          contexts read after the layer (Core\Domain\Product)
     */
    public function __construct(
        public array $violations,
        public array $contextDependencies,
        public bool $layerFirst,
    ) {
    }
}
