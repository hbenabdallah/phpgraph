<?php

namespace App\Billing;

use App\Validation\Notification;
use App\Validation\PipelineRunner;

final class Invoice
{
    public function __construct(private PipelineRunner $pipeline)
    {
    }

    public function handle(object $query): void
    {
        $this->pipeline->run($query, new Notification());
    }
}
