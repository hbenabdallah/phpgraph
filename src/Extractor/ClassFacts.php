<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

/**
 * What a class says of itself in its source, for an outline: read on demand, never stored in the graph.
 */
final readonly class ClassFacts
{
    /**
     * @param list<string>          $modifiers  final, readonly, abstract
     * @param list<string>          $signatures public methods (and abstract protected ones, the hooks of a template)
     * @param array<string, string> $constants  name => value as written
     * @param array<string, string> $cases      enum case => value as written, or ''
     * @param list<Hint>            $hints      what its methods check, throw and return early
     * @param array<string, array{type: string, classes: list<string>}> $properties property => its type as written
     *                                          and the classes it names: typed, documented (`@property-read Log $log`)
     *                                          or cast (Eloquent `casts()`)
     */
    public function __construct(
        public string $id,
        public string $kind,
        public array $modifiers,
        public ?string $summary,
        public array $signatures,
        public array $constants,
        public array $cases,
        public array $hints,
        public array $properties = [],
    ) {
    }

    /**
     * @param array<string, array{type: string, classes: list<string>}> $properties
     */
    public function withProperties(array $properties): self
    {
        return new self($this->id, $this->kind, $this->modifiers, $this->summary, $this->signatures, $this->constants, $this->cases, $this->hints, $properties);
    }
}
