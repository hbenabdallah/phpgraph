<?php

declare(strict_types=1);

namespace PhpGraph\Project\Rules;

/**
 * Who may depend on what: `dependency: Doctrine\` with `allow-from: [App\*\Infrastructure\]` lets only infrastructure
 * code use Doctrine; `deny-from` forbids it from the namespaces it names, and wins over `allow-from`.
 */
final readonly class DependencyRule
{
    /**
     * @param list<string> $allowFrom patterns of the classes that may depend on it; empty for any
     * @param list<string> $denyFrom  patterns of the classes that must not
     */
    public function __construct(
        public string $name,
        public string $dependency,
        public array $allowFrom,
        public array $denyFrom,
        public ?string $reason,
    ) {
    }

    public function forbids(string $from): bool
    {
        return Pattern::any($this->denyFrom, $from) || ($this->allowFrom !== [] && !Pattern::any($this->allowFrom, $from));
    }
}
