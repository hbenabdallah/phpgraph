<?php

namespace App\Validation;

interface ContextRuleInterface
{
    public function apply(object $input, Notification $notification): void;
}
