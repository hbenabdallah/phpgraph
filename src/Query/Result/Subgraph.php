<?php

declare(strict_types=1);

namespace PhpGraph\Query\Result;

use PhpGraph\Graph\Edge;
use PhpGraph\Graph\Node;

final readonly class Subgraph
{
    /**
     * @param list<string> $terms
     * @param list<Node>   $seeds
     * @param list<Node>   $nodes
     * @param list<Edge>   $edges
     */
    public function __construct(
        public array $terms,
        public array $seeds,
        public array $nodes,
        public array $edges,
    ) {
    }
}
