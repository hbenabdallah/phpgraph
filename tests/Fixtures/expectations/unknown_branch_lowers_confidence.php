<?php

namespace App;

class Order { public function ship(): void {} }

class Orders
{
    /** @return mixed */
    public function find(int $id)
    {
        return null;
    }

    public function shipOrCreate(int $id): void
    {
        $order = $this->find($id); // @phpgraph-expect calls App\Orders::find INFERRED
        if ($order === null) {
            $order = new Order();
        }

        // An Order, or whatever find() returns: one of the receivers is unknown.
        $order->ship(); // @phpgraph-expect calls App\Order::ship AMBIGUOUS
    }
}
