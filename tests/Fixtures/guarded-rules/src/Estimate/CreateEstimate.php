<?php

namespace App\Estimate;

use App\Validation\Notification;
use App\Validation\PipelineRunner;
use App\Validation\QueryInterface;

final class CreateEstimate
{
    public function __construct(private PipelineRunner $pipeline)
    {
    }

    /**
     * @param CreateEstimateQuery $query
     */
    public function handle(QueryInterface $query): void
    {
        $this->pipeline->run($query, new Notification(), static function (Notification $notification): void {
        });
    }
}
