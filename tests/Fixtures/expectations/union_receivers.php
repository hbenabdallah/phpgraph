<?php

namespace App;

class Animal { public function eat(): void {} }
class Cat extends Animal { public function purr(): void {} public function sleep(): void {} }
class Dog extends Animal { public function bark(): void {} public function sleep(): void {} }

class Shelter
{
    public function native(Cat|Dog $pet): void
    {
        $pet->bark(); // @phpgraph-expect calls App\Dog::bark INFERRED
        $pet->eat(); // @phpgraph-expect calls App\Animal::eat INFERRED
        $pet->sleep(); // @phpgraph-expect calls App\Cat::sleep INFERRED @phpgraph-expect calls App\Dog::sleep INFERRED
    }

    /** @param Cat|Dog $pet */
    public function documented($pet): void
    {
        $pet->sleep(); // @phpgraph-expect calls App\Cat::sleep INFERRED @phpgraph-expect calls App\Dog::sleep INFERRED
    }

    /** @return Cat|Dog */
    public function adopt(): Animal
    {
        return new Cat();
    }

    /** @return Cat|Dog */
    public function pick()
    {
        return new Cat();
    }

    public function chained(): void
    {
        // The native return type wins over the documented union.
        $this->adopt()->eat(); // @phpgraph-expect calls App\Animal::eat INFERRED @phpgraph-expect calls App\Shelter::adopt INFERRED
        $this->pick()->sleep(); // @phpgraph-expect calls App\Cat::sleep INFERRED @phpgraph-expect calls App\Dog::sleep INFERRED @phpgraph-expect calls App\Shelter::pick INFERRED
    }
}
