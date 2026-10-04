<?php

namespace App\Validation;

readonly class PipelineRunner
{
    public function __construct(private ContextValidator $context, private MutationValidators $validators)
    {
    }

    /**
     * @param \Closure(MutationValidators, Notification): ?object $apply
     */
    public function run(object $query, Notification $notification, \Closure $apply): Outcome
    {
        $this->context->validate($query, $notification);
        if ($notification->hasErrors()) {
            return Outcome::halted($notification);
        }

        return Outcome::completed($notification, $apply($this->validators, $notification));
    }
}
