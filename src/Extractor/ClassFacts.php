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
    ) {
    }
}
