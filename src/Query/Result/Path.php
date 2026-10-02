<?php

declare(strict_types=1);

namespace PhpGraph\Query\Result;

use PhpGraph\Graph\Node;

final readonly class Path
{
    /**
     * @param list<Connection> $hops each hop's `other` is the node reached by that hop
     */
    public function __construct(
        public Node $from,
        public Node $to,
        public array $hops,
        public PathMode $mode,
    ) {
    }
}
