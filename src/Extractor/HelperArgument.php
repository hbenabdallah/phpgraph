<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

/**
 * An argument of a call that may be to a configuration helper.
 */
final readonly class HelperArgument
{
    /**
     * @param ?string   $name  the parameter it is passed to by name, null when passed by position
     * @param ?Template $value null for an expression a template cannot hold
     */
    public function __construct(
        public ?string $name,
        public ?Template $value,
    ) {
    }
}
