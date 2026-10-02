<?php

declare(strict_types=1);

namespace PhpGraph\Query\Result;

use PhpGraph\Graph\Edge;

/**
 * A class depending on a class of a layer its own layer must not depend on.
 */
final readonly class LayerViolation
{
    /**
     * @param Edge $edge one edge carrying the dependency, the most certain one
     */
    public function __construct(
        public string $from,
        public string $fromLayer,
        public string $to,
        public string $toLayer,
        public Edge $edge,
        public int $edges,
    ) {
    }

    public function key(): string
    {
        return $this->from . ' -> ' . $this->to;
    }
}
