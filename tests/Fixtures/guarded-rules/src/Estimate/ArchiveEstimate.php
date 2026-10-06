<?php

namespace App\Estimate;

use App\Validation\Notification;
use App\Validation\PipelineRunner;
use App\Validation\QueryInterface;

/**
 * Passes a query of no known class: every rule of the pipeline may run, unresolved.
 */
final class ArchiveEstimate
{
    public function __construct(private PipelineRunner $pipeline)
    {
    }

    public function handle(QueryInterface $query): void
    {
        $this->pipeline->run($query, new Notification(), static function (Notification $notification): void {
        });
    }
}
