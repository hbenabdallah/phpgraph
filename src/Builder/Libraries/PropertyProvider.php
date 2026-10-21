<?php

declare(strict_types=1);

namespace PhpGraph\Builder\Libraries;

use PhpGraph\Builder\TypeResolver;
use PhpGraph\Extractor\TypeExpr;

/**
 * The type of a property no class declares, as a library makes it exist: an Eloquent model's attributes.
 */
interface PropertyProvider
{
    /**
     * @param string   $class the class the property is read on
     * @param TypeExpr $read  the property fetch, its receiver and the property's name
     */
    public function propertyType(string $class, TypeExpr $read, TypeResolver $types): ?string;
}
