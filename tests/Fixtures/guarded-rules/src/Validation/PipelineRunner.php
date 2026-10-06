<?php

namespace App\Validation;

final class PipelineRunner
{
    public function __construct(private ContextValidator $context)
    {
    }

    public function run(QueryInterface $query, Notification $notification, \Closure $apply): void
    {
        $this->context->validate($query, $notification);
        $apply($notification);
    }
}
