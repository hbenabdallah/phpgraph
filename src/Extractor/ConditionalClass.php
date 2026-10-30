<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

/**
 * A branch of a conditional `@return` naming its type: `ResponseFactory` in `($content is null ? ResponseFactory : Response)`.
 */
final readonly class ConditionalClass
{
    /**
     * @param ?string $class the class, null when the type names none (`mixed`)
     */
    public function __construct(public ?string $class)
    {
    }
}
