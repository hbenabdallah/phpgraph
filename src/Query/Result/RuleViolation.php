<?php

declare(strict_types=1);

namespace PhpGraph\Query\Result;

use PhpGraph\Graph\Edge;

/**
 * A class breaking one of the project's rules (`rules` in phpgraph.yaml).
 */
final readonly class RuleViolation
{
    /**
     * @param string $subject the class breaking it
     * @param string $detail  what breaks it: `depends on Doctrine\ORM\EntityManager`, `is not final`
     * @param ?Edge  $edge    for a dependency, one edge carrying it, the most certain one
     * @param int    $edges   for a dependency, the edges carrying it
     */
    public function __construct(
        public string $rule,
        public string $subject,
        public string $detail,
        public ?string $reason,
        public ?Edge $edge = null,
        public int $edges = 0,
    ) {
    }

    public function key(): string
    {
        return $this->rule . ': ' . $this->subject . ' ' . $this->detail;
    }
}
