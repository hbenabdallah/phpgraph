<?php

namespace App\Sales;

use App\Validation\MutationValidators;
use App\Validation\Notification;

final class Order
{
    public static function create(MutationValidators $validators, Notification $notification): ?self
    {
        $validators->validate(new \stdClass(), $notification);
        if ($notification->hasErrors()) {
            return null;
        }

        return new self();
    }
}
