<?php

declare(strict_types=1);

namespace PhpGraph\Query\Result;

use PhpGraph\Graph\Node;

final readonly class Impact
{
    /**
     * @param list<ImpactedClass> $classes   nearest first
     * @param bool                $truncated the limit stopped the search before the maximum depth
     */
    public function __construct(
        public Node $changed,
        public int $maxDepth,
        public array $classes,
        public bool $truncated,
    ) {
    }
}
