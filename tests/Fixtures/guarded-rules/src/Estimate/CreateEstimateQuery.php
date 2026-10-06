<?php

namespace App\Estimate;

use App\Validation\QueryInterface;

final class CreateEstimateQuery implements QueryInterface
{
    /** @var Line[] */
    private array $lines = [];

    public function isUrgent(): bool
    {
        return false;
    }
}
