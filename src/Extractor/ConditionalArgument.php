<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

/**
 * A branch of a conditional `@return` returning the class an argument names: `T` in
 * `($abstract is class-string<T> ? T : mixed)`, `app(Mailer::class)`.
 */
final readonly class ConditionalArgument
{
    public function __construct(public int $position)
    {
    }
}
