<?php

declare(strict_types=1);

namespace App\Sales\Application;

use App\Sales\Domain\Order;
use App\Sales\Domain\OrderRepository;
use App\Stock\Domain\StockChecker;

final class PlaceOrderHandler
{
    public function __construct(
        private OrderRepository $orders,
        private StockChecker $stock,
    ) {
    }

    public function handle(): void
    {
        $order = new Order();
        $this->orders->save($order);
        $this->stock->check($order);
        $this->log();
    }

    private function log(): void
    {
    }
}
