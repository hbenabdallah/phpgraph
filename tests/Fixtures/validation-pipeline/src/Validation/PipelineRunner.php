<?php

namespace App\Validation;

final class PipelineRunner
{
    public function __construct(private ContextValidator $context)
    {
    }

    public function run(object $query, Notification $notification): void
    {
        $this->context->validate($query, $notification);
    }
}
