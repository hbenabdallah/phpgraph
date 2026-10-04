<?php

namespace App\Billing;

use App\Validation\MutationValidators;
use App\Validation\Notification;
use App\Validation\PipelineRunner;

final class IssueInvoice
{
    public function __construct(private PipelineRunner $pipeline)
    {
    }

    public function handle(object $query): ?object
    {
        return $this->pipeline->run($query, new Notification(), static fn (MutationValidators $validators, Notification $notification): ?object => null)->result;
    }
}
