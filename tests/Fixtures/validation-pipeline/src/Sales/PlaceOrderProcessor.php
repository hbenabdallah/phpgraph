<?php

namespace App\Sales;

final class PlaceOrderProcessor
{
    public function __construct(private PlaceOrder $useCase)
    {
    }

    public function process(object $data): void
    {
        $this->useCase->handle($data);
    }
}
