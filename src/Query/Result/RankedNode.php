<?php

declare(strict_types=1);

namespace PhpGraph\Query\Result;

use PhpGraph\Graph\Node;

final readonly class RankedNode
{
    public function __construct(
        public Node $node,
        public int $degree,
    ) {
    }
}
