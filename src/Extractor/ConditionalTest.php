<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

/**
 * What a conditional `@return` tests of an argument: `$content is null`, `$abstract is class-string<T>`.
 */
enum ConditionalTest: string
{
    case IsNull = 'null';
    case IsClass = 'class';
}
