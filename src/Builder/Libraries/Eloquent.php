<?php

declare(strict_types=1);

namespace PhpGraph\Builder\Libraries;

use PhpGraph\Builder\TypeResolver;
use PhpGraph\Extractor\GenericType;
use PhpGraph\Extractor\TypeExpr;

/**
 * Laravel's Eloquent: an attribute of a model no property declares is its cast (`'paid_at' => 'datetime'`), a
 * timestamp, or the relation of the same name (`$page->book`, what `book()->getResults()` returns).
 */
final class Eloquent implements PropertyProvider
{
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';

    private const CARBON = 'Illuminate\\Support\\Carbon';

    private const CASTS_ATTRIBUTES = 'Illuminate\\Contracts\\Database\\Eloquent\\CastsAttributes';

    private const CASTABLE = 'Illuminate\\Contracts\\Database\\Eloquent\\Castable';

    /**
     * Eloquent's castables, by the class they give an attribute.
     */
    private const CASTABLES = [
        'Illuminate\\Database\\Eloquent\\Casts\\AsCollection' => 'Illuminate\\Support\\Collection',
        'Illuminate\\Database\\Eloquent\\Casts\\AsEnumCollection' => 'Illuminate\\Support\\Collection',
        'Illuminate\\Database\\Eloquent\\Casts\\AsArrayObject' => 'Illuminate\\Database\\Eloquent\\Casts\\ArrayObject',
        'Illuminate\\Database\\Eloquent\\Casts\\AsEnumArrayObject' => 'Illuminate\\Database\\Eloquent\\Casts\\ArrayObject',
        'Illuminate\\Database\\Eloquent\\Casts\\AsStringable' => 'Illuminate\\Support\\Stringable',
    ];

    /**
     * @param array<string, string> $casts "Class::attribute" => the class its cast gives it, as the extractor read it
     */
    public function __construct(private readonly array $casts)
    {
    }

    public function propertyType(string $class, TypeExpr $read, TypeResolver $types): ?string
    {
        $lineage = $types->lineage($class);
        if (!\in_array(self::MODEL, $lineage, true) || $read->receiver === null || $read->member === null) {
            return null;
        }
        foreach ($lineage as $owner) {
            $cast = $this->casts[$owner . '::' . $read->member] ?? null;
            if ($cast !== null) {
                return $this->castClass($cast, $types);
            }
        }
        if (\in_array($read->member, ['created_at', 'updated_at', 'deleted_at'], true)) {
            return self::CARBON;
        }
        if ($types->findMethod($class, strtolower($read->member)) === null) {
            return null;
        }
        $left = false;

        return $types->resolve(TypeExpr::returnOf(TypeExpr::returnOf($read->receiver, $read->member), 'getResults'), $left);
    }

    /**
     * The class a cast gives an attribute: the `TGet` of a cast class, the class of a built-in castable, the class
     * itself otherwise (an enum, a value object).
     */
    private function castClass(string $cast, TypeResolver $types): ?string
    {
        $lineage = $types->lineage($cast);
        if (\in_array(self::CASTS_ATTRIBUTES, $lineage, true)) {
            $get = $types->bindings($cast, self::CASTS_ATTRIBUTES)['TGet'] ?? null;
            $base = $get === null ? null : GenericType::base($get);

            return $base !== null && GenericType::isClass($base) ? $base : null;
        }
        if (\in_array(self::CASTABLE, $lineage, true)) {
            return self::CASTABLES[$cast] ?? null;
        }

        return $cast;
    }
}
