<?php

declare(strict_types=1);

namespace PhpGraph\Project\Rules;

/**
 * What the classes a pattern selects must be: `on: App\**\Controller\*`, `target: class`, then the constraints
 * (`must-be-final`, `must-be-named: *Controller`, `must-implement`, `only-public-methods`...).
 */
final readonly class StructureRule
{
    /**
     * @param ?string      $target            `class`, `interface`, `trait` or `enum`; null for any
     * @param list<string> $mustImplement     interfaces it must implement, all of them
     * @param list<string> $mustUseTrait      traits it must use, all of them
     * @param list<string>|null $onlyPublicMethods the public methods it may declare, null for any
     */
    public function __construct(
        public string $name,
        public string $on,
        public ?string $notOn,
        public ?string $target,
        public ?bool $mustBeFinal,
        public ?bool $mustBeAbstract,
        public ?bool $mustBeReadonly,
        public ?string $mustBeNamed,
        public array $mustImplement,
        public ?string $mustExtend,
        public array $mustUseTrait,
        public ?array $onlyPublicMethods,
        public ?string $reason,
    ) {
    }
}
