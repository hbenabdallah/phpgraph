<?php

namespace App\Estimate\Rules;

use App\Estimate\CreateEstimateQuery;
use App\Estimate\UpdateEstimateQuery;
use App\Shared\MaterialResolver;
use App\Validation\ContextRuleInterface;
use App\Validation\Notification;

final class MaterialRule implements ContextRuleInterface
{
    public function __construct(private MaterialResolver $materials)
    {
    }

    public function supports(object $input): bool
    {
        return $input instanceof CreateEstimateQuery || $input instanceof UpdateEstimateQuery;
    }

    public function apply(object $input, Notification $notification): void
    {
        $this->materials->resolve('estimate');
    }
}
