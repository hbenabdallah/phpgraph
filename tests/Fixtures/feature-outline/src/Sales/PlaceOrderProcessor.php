<?php

namespace App\Sales;

final class PlaceOrderProcessor
{
    public function __construct(private PlaceOrder $placeOrder)
    {
    }

    public function process(object $data): ?object
    {
        return $this->placeOrder->handle($data);
    }
}
