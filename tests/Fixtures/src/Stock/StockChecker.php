<?php

declare(strict_types=1);

namespace App\Stock\Domain;

use App\Sales\Domain\Order;

final class StockChecker
{
    public function check(Order $order): bool
    {
        return $order->total() > 0;
    }
}
