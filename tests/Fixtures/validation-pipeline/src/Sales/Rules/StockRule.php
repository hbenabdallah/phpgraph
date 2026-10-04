<?php

namespace App\Sales\Rules;

use App\Validation\ContextRuleInterface;
use App\Validation\Notification;

final class StockRule implements ContextRuleInterface
{
    public function apply(object $input, Notification $notification): void
    {
        $notification->addContextViolation('StockRule');
    }
}
