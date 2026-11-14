<?php

namespace App;

interface Shape { public function area(): float; }
class Circle implements Shape { public function area(): float { return 0.0; } public function radius(): float { return 0.0; } }
class Square implements Shape { public function area(): float { return 0.0; } public function side(): float { return 0.0; } }

class Holder
{
    public Shape $shape;
}

class Drawing
{
    private Shape $shape;
    private $untyped;

    public function typed(): float
    {
        if ($this->shape instanceof Circle) {
            return $this->shape->radius(); // @phpgraph-expect calls App\Circle::radius INFERRED
        }

        return $this->shape->area(); // @phpgraph-expect calls App\Shape::area INFERRED
    }

    public function untyped(): float
    {
        if ($this->untyped instanceof Circle) {
            return $this->untyped->radius(); // @phpgraph-expect calls App\Circle::radius INFERRED
        }

        return 0.0;
    }

    public function earlyExit(): float
    {
        if (!$this->shape instanceof Square) {
            throw new \LogicException();
        }

        return $this->shape->side(); // @phpgraph-expect calls App\Square::side INFERRED
    }

    public function shortCircuit(): bool
    {
        return $this->shape instanceof Circle && $this->shape->radius() > 1.0; // @phpgraph-expect calls App\Circle::radius INFERRED
    }

    public function nullsafe(): ?float
    {
        return $this->untyped instanceof Circle ? $this->untyped?->radius() : null; // @phpgraph-expect calls App\Circle::radius INFERRED
    }

    public function oneBranchOnly(bool $flag): float
    {
        if ($flag) {
            if (!$this->shape instanceof Circle) {
                return 0.0;
            }
            $this->shape->radius(); // @phpgraph-expect calls App\Circle::radius INFERRED
        }

        return $this->shape->area(); // @phpgraph-expect calls App\Shape::area INFERRED
    }

    public function assigned(Shape $other): float
    {
        if ($this->shape instanceof Circle) {
            $this->shape = $other;

            return $this->shape->area(); // @phpgraph-expect calls App\Shape::area INFERRED
        }

        return 0.0;
    }

    public function afterACallOnThis(): float
    {
        if ($this->shape instanceof Circle) {
            $this->reset(); // @phpgraph-expect calls App\Drawing::reset INFERRED

            return $this->shape->area(); // @phpgraph-expect calls App\Shape::area INFERRED
        }

        return 0.0;
    }

    public function ofAnotherObject(Holder $holder, Holder $next): float
    {
        if ($holder->shape instanceof Square) {
            $holder->shape->side(); // @phpgraph-expect calls App\Square::side INFERRED
            $holder = $next;

            return $holder->shape->area(); // @phpgraph-expect calls App\Shape::area INFERRED
        }

        return 0.0;
    }

    public function changedByALaterOperand(): float
    {
        if ($this->shape instanceof Circle && $this->replaced()) { // @phpgraph-expect calls App\Drawing::replaced INFERRED
            return $this->shape->area(); // @phpgraph-expect calls App\Shape::area INFERRED
        }

        return 0.0;
    }

    public function closureOfALaterOperand(): float
    {
        if ($this->shape instanceof Circle && ($callback = fn () => $this->reset())) { // @phpgraph-expect calls App\Drawing::reset INFERRED
            return $this->shape->radius(); // @phpgraph-expect calls App\Circle::radius INFERRED
        }

        return 0.0;
    }

    public function anotherPropertyAssigned(Shape $other): float
    {
        if ($this->shape instanceof Circle && ($this->untyped = $other)) {
            return $this->shape->radius(); // @phpgraph-expect calls App\Circle::radius INFERRED
        }

        return 0.0;
    }

    public function writtenByName(Holder $holder, Shape $other, string $name): float
    {
        if ($holder->shape instanceof Square) {
            $holder->$name = $other;

            return $holder->shape->area(); // @phpgraph-expect calls App\Shape::area INFERRED
        }

        return 0.0;
    }

    private function reset(): void
    {
    }

    private function replaced(): bool
    {
        return true;
    }
}
