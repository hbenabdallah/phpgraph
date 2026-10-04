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
     * @param list<string> $chain      the nodes from the one reaching this class (a route's handler) down to the change
     * @param bool       $comparesState it reads the state the change writes before and after a call, `count($n->all())`
     *                                  twice: it depends on what the change appends (INFERRED)
     * @param ?string    $node          the node of this class the walk reached first
     * @param list<list<string>> $otherChains for a route, the other ways down to the change (a few)
     * @param ?string    $uses          through the state: the enum the change writes that it uses, putting the written
     *                                  value in its output (`$violation->type`)
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
        public bool $comparesState = false,
        public ?string $node = null,
        public array $otherChains = [],
        public ?string $uses = null,
    ) {
    }
}
