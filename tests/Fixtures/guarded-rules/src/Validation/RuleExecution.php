<?php

namespace App\Validation;

final class RuleExecution
{
    public function __construct(public ContextRuleInterface $rule)
    {
    }

    public function canRun(object $input): bool
    {
        return $this->rule->supports($input);
    }
}
