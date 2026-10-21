<?php

namespace App;

class Circle { public function area(): float { return 0.0; } }
class Square { public function area(): float { return 0.0; } }
class Triangle { public function area(): float { return 0.0; } }

class Drawing
{
    public function ifElse(bool $round): float
    {
        if ($round) {
            $shape = new Circle();
        } else {
            $shape = new Square();
        }

        return $shape->area(); // @phpgraph-expect calls App\Circle::area INFERRED @phpgraph-expect calls App\Square::area INFERRED
    }

    public function elseIf(int $sides): float
    {
        if ($sides === 0) {
            $shape = new Circle();
        } elseif ($sides === 3) {
            $shape = new Triangle();
        } else {
            $shape = new Square();
        }

        return $shape->area(); // @phpgraph-expect calls App\Circle::area INFERRED @phpgraph-expect calls App\Square::area INFERRED @phpgraph-expect calls App\Triangle::area INFERRED
    }

    public function oneBranch(bool $round): float
    {
        $shape = new Square();
        if ($round) {
            $shape = new Circle();
        }

        return $shape->area(); // @phpgraph-expect calls App\Circle::area INFERRED @phpgraph-expect calls App\Square::area INFERRED
    }

    public function onlyDefinedInABranch(bool $round): float
    {
        if ($round) {
            $shape = new Circle();
        }

        // Undefined otherwise: when the call runs, it is a Circle.
        return $shape->area(); // @phpgraph-expect calls App\Circle::area INFERRED
    }

    public function exitingBranch(bool $round): float
    {
        $shape = new Square();
        if ($round) {
            $shape = new Circle();

            return 0.0;
        }

        // The branch assigning a Circle returns: only the Square reaches the call.
        return $shape->area(); // @phpgraph-expect calls App\Square::area INFERRED
    }
}
