<?php

// Straight-line code: the last assignment read gives the type, an assignment of unknown type forgets it.

namespace App;

class Circle { public function area(): float { return 0.0; } }
class Square { public function area(): float { return 0.0; } public function side(): float { return 0.0; } }

class Drawing
{
    public function draw(): void
    {
        $shape = new Circle();
        $shape = new Square();
        $shape->side(); // @phpgraph-expect calls App\Square::side INFERRED
    }

    public function forgotten(): void
    {
        $shape = new Square();
        $shape = $this->unknown(); // @phpgraph-expect calls App\Drawing::unknown INFERRED
        $shape->side(); // @phpgraph-expect calls App\Square::side AMBIGUOUS
    }

    private function unknown()
    {
        return null;
    }
}
