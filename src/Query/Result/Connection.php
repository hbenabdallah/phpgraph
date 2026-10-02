<?php

declare(strict_types=1);

namespace PhpGraph\Query\Result;

use PhpGraph\Graph\Edge;

/**
 * An edge seen from one of its ends: forward when that end is the source.
 */
final readonly class Connection
{
    public function __construct(
        public Edge $edge,
        public string $other,
        public bool $forward,
    ) {
    }
}
