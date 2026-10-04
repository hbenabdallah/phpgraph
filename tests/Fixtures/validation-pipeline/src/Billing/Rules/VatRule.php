<?php

namespace App\Billing\Rules;

use App\Validation\ContextRuleInterface;
use App\Validation\Notification;

final class VatRule implements ContextRuleInterface
{
    public function apply(object $input, Notification $notification): void
    {
        $notification->addContextViolation('VatRule');
    }
}
