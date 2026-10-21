<?php

// Docblock types phpdoc-parser reads: nullable, a single class beside null, iterables, shapes, generics over several lines.

namespace App;

class Item { public function price(): int { return 0; } }
class Box { public function open(): void {} }

class Docblocks
{
    /**
     * @param Item $item
     */
    public function plain($item): int
    {
        return $item->price(); // @phpgraph-expect calls App\Item::price INFERRED
    }

    /**
     * @param ?Item $item nullable
     */
    public function nullable($item): int
    {
        return $item->price(); // @phpgraph-expect calls App\Item::price INFERRED
    }

    /**
     * @return array<
     *     int,
     *     Item
     * >
     */
    public function multiLine(): array
    {
        return [];
    }

    /**
     * @param array{item: Item, count: int} $line
     */
    public function shape(array $line): int
    {
        return $line['item']->price(); // @phpgraph-expect calls App\Item::price AMBIGUOUS
    }

    /**
     * @return list<array{Item, Box}>
     */
    public function pairs(): array
    {
        return [];
    }

    /**
     * @param iterable<int, Item> $items
     * @param Box|null $box
     */
    public function walk(iterable $items, $box): void
    {
        foreach ($this->multiLine() as $item) { // @phpgraph-expect calls App\Docblocks::multiLine INFERRED
            $item->price(); // @phpgraph-expect calls App\Item::price INFERRED
        }
        foreach ($items as $item) {
            $item->price(); // @phpgraph-expect calls App\Item::price INFERRED
        }
        $box->open(); // @phpgraph-expect calls App\Box::open INFERRED
    }

    public function inline(): void
    {
        /** @var Item $item */
        $item = $this->find(); // @phpgraph-expect calls App\Docblocks::find INFERRED
        $item->price(); // @phpgraph-expect calls App\Item::price INFERRED
    }

    public function inlineAfter(): void
    {
        $box = $this->find(); // @phpgraph-expect calls App\Docblocks::find INFERRED
        /** @var Box $box */
        $box->open(); // @phpgraph-expect calls App\Box::open INFERRED
    }

    private function find()
    {
        return null;
    }
}
