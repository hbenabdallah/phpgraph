<?php

namespace App\Validation;

final class MutationValidators
{
    /**
     * @param iterable<PolicyRuleInterface> $policies
     */
    public function __construct(private iterable $policies)
    {
    }

    public function validate(object $input, Notification $notification): void
    {
        foreach ($this->policies as $policy) {
            $policy->check($input, $notification);
        }
    }
}
