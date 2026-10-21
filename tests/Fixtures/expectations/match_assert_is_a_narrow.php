<?php

namespace App;

interface Shape { public function area(): float; }
class Circle implements Shape { public function area(): float { return 0.0; } public function radius(): float { return 0.0; } }
class Square implements Shape { public function area(): float { return 0.0; } public function side(): float { return 0.0; } }

class Narrowing
{
    public function matchTrue(Shape $shape): float
    {
        return match (true) {
            $shape instanceof Circle => $shape->radius(), // @phpgraph-expect calls App\Circle::radius INFERRED
            $shape instanceof Square => $shape->side(), // @phpgraph-expect calls App\Square::side INFERRED
            default => $shape->area(), // @phpgraph-expect calls App\Shape::area INFERRED
        };
    }

    public function asserted(Shape $shape): float
    {
        assert($shape instanceof Circle);

        return $shape->radius(); // @phpgraph-expect calls App\Circle::radius INFERRED
    }

    public function isA(Shape $shape): float
    {
        if (is_a($shape, Square::class)) {
            return $shape->side(); // @phpgraph-expect calls App\Square::side INFERRED
        }

        return 0.0;
    }
}
