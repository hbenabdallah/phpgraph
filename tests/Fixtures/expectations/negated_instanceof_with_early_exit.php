<?php

namespace App;

interface Shape { public function area(): float; }
class Circle implements Shape { public function area(): float { return 0.0; } public function radius(): float { return 0.0; } }

class Guards
{
    public function returns(Shape $shape): float
    {
        if (!$shape instanceof Circle) {
            return 0.0;
        }

        return $shape->radius(); // @phpgraph-expect calls App\Circle::radius INFERRED
    }

    public function throws(Shape $shape): float
    {
        if (!($shape instanceof Circle)) {
            throw new \InvalidArgumentException('A circle');
        }

        return $shape->radius(); // @phpgraph-expect calls App\Circle::radius INFERRED
    }

    /** @param list<Shape> $shapes */
    public function continues(array $shapes): float
    {
        $total = 0.0;
        foreach ($shapes as $shape) {
            if (!$shape instanceof Circle) {
                continue;
            }
            $total += $shape->radius(); // @phpgraph-expect calls App\Circle::radius INFERRED
        }

        return $total;
    }

    public function withoutExit(Shape $shape): float
    {
        if (!$shape instanceof Circle) {
            $shape->area(); // @phpgraph-expect calls App\Shape::area INFERRED
        }

        // Both paths reach the call: a Shape.
        return $shape->area(); // @phpgraph-expect calls App\Shape::area INFERRED
    }
}
