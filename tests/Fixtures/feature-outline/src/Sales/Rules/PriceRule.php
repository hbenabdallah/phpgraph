<?php

namespace App\Sales\Rules;

use App\Validation\ContextRuleInterface;
use App\Validation\Notification;

final class PriceRule implements ContextRuleInterface
{
    public function apply(object $input, Notification $notification): void
    {
        $notification->add('price');
    }
}
