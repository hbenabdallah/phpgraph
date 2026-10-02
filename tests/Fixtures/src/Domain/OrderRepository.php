<?php

declare(strict_types=1);

namespace App\Sales\Domain;

interface OrderRepository
{
    public function save(Order $order): void;
}
