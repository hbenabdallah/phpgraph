<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

/**
 * A call that may be to a configuration helper, in a helper or in a configuration file: evaluated by the builder when
 * its arguments are known.
 */
final readonly class PendingHelperCall
{
    /**
     * @param ?string              $caller the helper making it, null in a configuration file
     * @param list<HelperArgument> $arguments
     */
    public function __construct(
        public ?string $caller,
        public string $callee,
        public array $arguments,
        public int $line,
    ) {
    }

    public function withNames(?string $caller, string $callee): self
    {
        return new self($caller, $callee, $this->arguments, $this->line);
    }

    /**
     * Whether an argument depends on a parameter of the caller: the call runs when its caller does.
     */
    public function usesParameters(): bool
    {
        foreach ($this->arguments as $argument) {
            if ($argument->value?->usesParameters() === true) {
                return true;
            }
        }

        return false;
    }
}
