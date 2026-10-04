<?php

namespace App\Validation;

final class RuleExecution
{
    public function __construct(public ContextRuleInterface $rule)
    {
    }
}
