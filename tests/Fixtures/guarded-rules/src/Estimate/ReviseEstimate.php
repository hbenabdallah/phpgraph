<?php

namespace App\Estimate;

use App\Validation\Notification;
use App\Validation\PipelineRunner;
use App\Validation\QueryInterface;

/**
 * No @param: the builder it passes the query to says it is an update.
 */
final class ReviseEstimate
{
    public function __construct(private PipelineRunner $pipeline, private EstimateBuilder $builder)
    {
    }

    public function handle(QueryInterface $query): void
    {
        $this->pipeline->run($query, new Notification(), function (Notification $notification) use ($query): void {
            $this->builder->revise(query: $query, number: 1);
        });
    }
}
