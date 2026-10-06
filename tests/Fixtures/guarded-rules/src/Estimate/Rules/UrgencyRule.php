<?php

namespace App\Estimate\Rules;

use App\Estimate\CreateEstimateQuery;
use App\Shared\UrgencyChecker;
use App\Validation\ContextRuleInterface;
use App\Validation\Notification;

/**
 * A guard phpgraph cannot read: kept for every use case of the pipeline.
 */
final class UrgencyRule implements ContextRuleInterface
{
    public function __construct(private UrgencyChecker $checker)
    {
    }

    public function supports(object $input): bool
    {
        return $input instanceof CreateEstimateQuery && $input->isUrgent();
    }

    public function apply(object $input, Notification $notification): void
    {
        $this->checker->check($input);
    }
}
