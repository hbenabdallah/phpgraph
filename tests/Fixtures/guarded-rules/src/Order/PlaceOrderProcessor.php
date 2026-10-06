<?php

namespace App\Order;

final class PlaceOrderProcessor
{
    public function __construct(private PlaceOrder $useCase)
    {
    }

    public function process(OrderQuery $data): void
    {
        $this->useCase->handle($data);
    }
}
