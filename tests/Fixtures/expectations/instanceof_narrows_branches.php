<?php

namespace App;

interface Shape { public function area(): float; }
class Circle implements Shape { public function area(): float { return 0.0; } public function radius(): float { return 0.0; } }
class Square implements Shape { public function area(): float { return 0.0; } public function side(): float { return 0.0; } }
class Polygon implements Shape { public function area(): float { return 0.0; } public function side(): float { return 0.0; } }

class Measures
{
    public function typed(Shape $shape): float
    {
        if ($shape instanceof Circle) {
            return $shape->radius(); // @phpgraph-expect calls App\Circle::radius INFERRED
        }

        return $shape->area(); // @phpgraph-expect calls App\Shape::area INFERRED
    }

    public function untyped($shape): float
    {
        if ($shape instanceof Circle) {
            return $shape->radius(); // @phpgraph-expect calls App\Circle::radius INFERRED
        }

        return 0.0;
    }

    public function elseIf(Shape $shape): float
    {
        if ($shape instanceof Circle) {
            return $shape->radius(); // @phpgraph-expect calls App\Circle::radius INFERRED
        } elseif ($shape instanceof Square) {
            return $shape->side(); // @phpgraph-expect calls App\Square::side INFERRED
        }

        return $shape->area(); // @phpgraph-expect calls App\Shape::area INFERRED
    }

    public function elseOfAUnion(Circle|Square $shape): float
    {
        if ($shape instanceof Circle) {
            return $shape->area(); // @phpgraph-expect calls App\Circle::area INFERRED
        } else {
            return $shape->area(); // @phpgraph-expect calls App\Square::area INFERRED
        }
    }

    public function eitherOf(Shape $shape): float
    {
        if ($shape instanceof Square || $shape instanceof Polygon) {
            return $shape->side(); // @phpgraph-expect calls App\Polygon::side INFERRED @phpgraph-expect calls App\Square::side INFERRED
        }

        return 0.0;
    }

    public function inTheCondition(Shape $shape): bool
    {
        return $shape instanceof Circle && $shape->radius() > 1.0; // @phpgraph-expect calls App\Circle::radius INFERRED
    }

    public function inATernary(Shape $shape): float
    {
        return $shape instanceof Circle ? $shape->radius() : $shape->area(); // @phpgraph-expect calls App\Circle::radius INFERRED @phpgraph-expect calls App\Shape::area INFERRED
    }
}
