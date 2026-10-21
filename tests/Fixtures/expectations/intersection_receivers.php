<?php

namespace App;

interface Countable { public function count(): int; }
interface Named { public function name(): string; }

class Report
{
    public function native(Countable&Named $list): string
    {
        return $list->name() . $list->count(); // @phpgraph-expect calls App\Countable::count INFERRED @phpgraph-expect calls App\Named::name INFERRED
    }

    /** @param Countable&Named $list */
    public function documented($list): string
    {
        return $list->name() . $list->count(); // @phpgraph-expect calls App\Countable::count INFERRED @phpgraph-expect calls App\Named::name INFERRED
    }

    public function inline(): string
    {
        /** @var Countable&Named $list */
        $list = $this->make(); // @phpgraph-expect calls App\Report::make INFERRED

        return $list->name(); // @phpgraph-expect calls App\Named::name INFERRED
    }

    private function make(): object
    {
        return new \stdClass();
    }
}
