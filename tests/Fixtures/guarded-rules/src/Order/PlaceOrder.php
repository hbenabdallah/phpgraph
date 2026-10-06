<?php

namespace App\Order;

use App\Validation\Notification;
use App\Validation\PipelineRunner;
use App\Validation\QueryInterface;

final class PlaceOrder
{
    public function __construct(private PipelineRunner $pipeline)
    {
    }

    /**
     * @param OrderQuery $query
     */
    public function handle(QueryInterface $query): void
    {
        $this->pipeline->run($query, new Notification(), static function (Notification $notification): void {
        });
    }
}
