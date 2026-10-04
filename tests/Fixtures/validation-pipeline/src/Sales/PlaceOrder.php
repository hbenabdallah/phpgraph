<?php

namespace App\Sales;

use App\Validation\Notification;
use App\Validation\PipelineRunner;

final class PlaceOrder
{
    public function __construct(private PipelineRunner $pipeline)
    {
    }

    public function handle(object $query): void
    {
        $this->pipeline->run($query, new Notification());
    }
}
