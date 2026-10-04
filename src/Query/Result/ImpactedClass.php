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
     * @param bool       $followed   whether what depends on it was searched too: not for a service receiving the
     *                               changed one among others (a tagged collection), whose users the change does not reach
     * @param ?string    $through    the method whose callers reached it, when it calls the changed method through an
     *                               interface or a parent class (`OrderRepository::save` for `DbalOrderRepository::save`)
     * @param bool       $throughState reached through the state the change writes: it uses a method reading what the
     *                                 changed one writes (`hasErrors()` for `addError()`), not the change itself
     * @param list<Edge> $sites        every relation from this class to what the change reaches: its calling methods,
     *                                 with their source lines
     * @param list<string> $chain      for a route, the nodes from its handler down to the change
     */
    public function __construct(
        public string $class,
        public int $depth,
        public Edge $edge,
        public Confidence $confidence,
        public bool $isTest,
        public bool $followed = true,
        public ?string $through = null,
        public bool $throughState = false,
        public array $sites = [],
        public array $chain = [],
    ) {
    }
}
