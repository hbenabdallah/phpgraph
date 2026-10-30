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

    /**
     * Its properties in order, without their names: unserialize() then fills the declared properties instead of
     * building a table of them for each object (5 times the memory, 3 times the time on 200 000 objects).
     *
     * @return array{string, ?TypeExpr, string, bool, ?int, list<string>, array{int, int|string}|null}
     */
    public function __serialize(): array
    {
        return [$this->source, $this->receiver, $this->method, $this->referenceOnMiss, $this->line, $this->named, $this->closure];
    }

    /**
     * @param array{string, ?TypeExpr, string, bool, ?int, list<string>, array{int, int|string}|null} $data
     */
    public function __unserialize(array $data): void
    {
        [$this->source, $this->receiver, $this->method, $this->referenceOnMiss, $this->line, $this->named, $this->closure] = $data;
    }
}
