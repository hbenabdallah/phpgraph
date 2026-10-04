<?php

namespace App\Sales;

use App\Validation\MutationValidators;
use App\Validation\Notification;

class OrderBuilder
{
    public function build(MutationValidators $validators, Notification $notification): ?Order
    {
        return Order::create($validators, $notification);
    }
}
