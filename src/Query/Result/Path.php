<?php

declare(strict_types=1);

namespace PhpGraph\Query\Result;

use PhpGraph\Graph\Node;

final readonly class Path
{
    /**
     * @param list<Connection> $hops     each hop's `other` is the node reached by that hop
     * @param bool             $reversed the path goes from `to` to `from`: no dependency path leads from `from` to
     *                                   `to`, but `to` depends on `from` (a use case on a rule it runs)
     */
    public function __construct(
        public Node $from,
        public Node $to,
        public array $hops,
        public PathMode $mode,
        public bool $reversed = false,
    ) {
    }
}
