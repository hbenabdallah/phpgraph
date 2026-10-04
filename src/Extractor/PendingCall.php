<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

final readonly class PendingCall
{
    /**
     * @param list<string>          $named    the named arguments of the call, `apply(severity: ...)`: renaming a parameter breaks it
     * @param array{int, int|string}|null $closure written in a closure passed to another call of the file: that
     *                                             call's index among the file's calls, and the parameter (position or
     *                                             name) receiving the closure
     */
    public function __construct(
        public string $source,
        public ?TypeExpr $receiver,
        public string $method,
        public bool $referenceOnMiss,
        public ?int $line = null,
        public array $named = [],
        public ?array $closure = null,
    ) {
    }
}
