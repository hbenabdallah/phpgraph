<?php

// PHP's array functions return the elements of their array argument: kept in an array (array_filter()), with those
// of every argument (array_merge()), or one of them (reset()).

namespace App;

class Item { public function ship(): void {} }
class Gift { public function ship(): void {} }

class Cart
{
    /** @var list<Item> */
    private array $items = [];

    /** @var list<Gift> */
    private array $gifts = [];

    public function filtered(): void
    {
        foreach (array_filter($this->items) as $item) {
            $item->ship(); // @phpgraph-expect calls App\Item::ship INFERRED
        }
        foreach (array_values(array_reverse($this->items)) as $item) {
            $item->ship(); // @phpgraph-expect calls App\Item::ship INFERRED
        }
    }

    public function first(): void
    {
        reset($this->items)->ship(); // @phpgraph-expect calls App\Item::ship INFERRED
        $last = end($this->items);
        $last->ship(); // @phpgraph-expect calls App\Item::ship INFERRED
        array_shift($this->items)->ship(); // @phpgraph-expect calls App\Item::ship INFERRED
    }

    public function merged(): void
    {
        foreach (array_merge($this->items, $this->gifts) as $thing) {
            $thing->ship(); // @phpgraph-expect calls App\Item::ship INFERRED @phpgraph-expect calls App\Gift::ship INFERRED
        }
    }

    // Elements of an untyped array may be anything: each known one is reached, AMBIGUOUS.
    public function mergedWithUntyped(array $other): void
    {
        foreach (array_merge($this->items, $other) as $thing) {
            $thing->ship(); // @phpgraph-expect calls App\Item::ship AMBIGUOUS
        }
    }

    // An untyped array says nothing of its elements: two classes declare ship(), nothing is guessed.
    public function untyped(array $things): void
    {
        reset($things)->ship();
    }
}
