<?php

namespace App\Validation;

/**
 * A rule on the state of an aggregate: none written yet.
 */
interface PolicyRuleInterface
{
    public function check(object $input, Notification $notification): void;
}
