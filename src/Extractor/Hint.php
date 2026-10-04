<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

/**
 * One line of behaviour read in a method body: a guarded throw, an early return, a branch on constants, a loop cap,
 * a comparison of the state before and after a call.
 */
final readonly class Hint
{
    public function __construct(
        public string $method,
        public int $line,
        public string $text,
    ) {
    }
}
