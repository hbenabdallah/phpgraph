<?php

namespace App\Validation;

interface ContextRuleInterface
{
    public function supports(object $input): bool;

    public function apply(object $input, Notification $notification): void;
}
