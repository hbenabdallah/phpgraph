<?php

namespace App\Validation;

/**
 * Runs the context rules until they add nothing more.
 */
final class ContextValidator
{
    private const MAX_CYCLES = 10;

    /**
     * @param iterable<ContextRuleInterface> $rules
     */
    public function __construct(private iterable $rules)
    {
    }

    public function validate(object $input, Notification $notification): void
    {
        $cycle = 0;
        while (true) {
            if ($cycle++ >= self::MAX_CYCLES) {
                throw new \LogicException('The rules exceeded the cycles: a rule never settles. Broken promise.');
            }
            $before = count($notification->all());
            foreach ($this->rules as $rule) {
                $rule->apply($input, $notification);
            }
            if (count($notification->all()) === $before) {
                return;
            }
        }
    }
}
