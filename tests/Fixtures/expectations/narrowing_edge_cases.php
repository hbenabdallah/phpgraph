<?php

// What narrowing must not do: leak out of its branch, or lose a type more precise than the class tested.

namespace App;

interface Shape { public function area(): float; }
interface Named { public function name(): string; }
class Circle implements Shape { public function area(): float { return 0.0; } public function radius(): float { return 0.0; } }
class Square implements Shape { public function area(): float { return 0.0; } }

class EdgeCases
{
    public function afterTheBranch(Shape $shape): float
    {
        if ($shape instanceof Circle) {
            $shape->radius(); // @phpgraph-expect calls App\Circle::radius INFERRED
        }

        // Back to a Shape: Circle::area is not called here.
        return $shape->area(); // @phpgraph-expect calls App\Shape::area INFERRED
    }

    public function moreSpecific(Circle $circle): float
    {
        if ($circle instanceof Shape) {
            return $circle->radius(); // @phpgraph-expect calls App\Circle::radius INFERRED
        }

        return 0.0;
    }

    public function interfaceOfAnInterface(Shape $shape): string
    {
        if ($shape instanceof Named) {
            // A Shape and a Named: each method on the interface declaring it.
            return $shape->name() . $shape->area(); // @phpgraph-expect calls App\Named::name INFERRED @phpgraph-expect calls App\Shape::area INFERRED
        }

        return '';
    }

    /** @param list<string> $kinds */
    public function nullThenAssigned(array $kinds): void
    {
        $found = null;
        foreach ($kinds as $kind) {
            if ($kind === 'circle') {
                $found = new Circle();
                break;
            }
        }
        // Null adds no receiver: when the call runs, it is a Circle.
        $found->area(); // @phpgraph-expect calls App\Circle::area INFERRED
    }

    public function inAClosure(bool $round): \Closure
    {
        $shape = $round ? new Circle() : new Square();

        return function () use ($shape): float {
            return $shape->area(); // @phpgraph-expect calls App\Circle::area INFERRED @phpgraph-expect calls App\Square::area INFERRED
        };
    }

    public function reassignedInTheBranch(Shape $shape): float
    {
        if ($shape instanceof Circle) {
            $shape = new Square();
        }

        // A Square, or a Shape that is no Circle.
        return $shape->area(); // @phpgraph-expect calls App\Shape::area INFERRED
    }
}
