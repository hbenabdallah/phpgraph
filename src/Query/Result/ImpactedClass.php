<?php

declare(strict_types=1);

namespace PhpGraph\Query\Result;

use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Edge;

/**
 * A class affected by a change, with the first edge that reached it.
 */
final readonly class ImpactedClass
{
    /**
     * @param int        $depth      relations away from the changed node, 1 for a direct dependent
     * @param Confidence $confidence the weakest confidence on the way from the changed node
     */
    public function __construct(
        public string $class,
        public int $depth,
        public Edge $edge,
        public Confidence $confidence,
        public bool $isTest,
    ) {
    }
}
