<?php

declare(strict_types=1);

namespace PhpGraph\Builder\Libraries;

use PhpGraph\Builder\TypeResolver;
use PhpGraph\Extractor\TypeExpr;

/**
 * What a library's method returns when its signature does not say it: `ContainerInterface::get(Mailer::class)` is a
 * Mailer, though `get()` returns `mixed`. Asked before the declared return type.
 */
interface MethodReturnProvider
{
    /**
     * @return list<string> the lowercase names of the methods it may type: the others are never asked
     */
    public function methods(): array;

    /**
     * The GenericType the method returns to this call, null when the provider has nothing to say.
     *
     * @param string $class  the class the method is called on
     * @param string $method the method found, `Class::name`, maybe declared by a parent
     */
    public function returnType(string $class, string $method, TypeExpr $call, TypeResolver $types): ?string;
}
