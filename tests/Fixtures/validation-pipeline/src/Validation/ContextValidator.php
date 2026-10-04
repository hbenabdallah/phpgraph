<?php

namespace App\Validation;

final class ContextValidator
{
    /** @var ContextRuleInterface[] */
    private array $rules;

    /**
     * @param iterable<ContextRuleInterface> $rules
     */
    public function __construct(iterable $rules)
    {
        $this->rules = iterator_to_array($rules);
    }

    public function validate(object $input, Notification $notification): void
    {
        $executions = array_map(static fn (ContextRuleInterface $rule): RuleExecution => new RuleExecution($rule), $this->rules);
        $this->executeRules($input, $notification, $executions);
    }

    /**
     * @param RuleExecution[] $executions
     */
    private function executeRules(object $input, Notification $notification, array $executions): void
    {
        foreach ($executions as $execution) {
            $execution->rule->apply($input, $notification);
        }
    }
}
