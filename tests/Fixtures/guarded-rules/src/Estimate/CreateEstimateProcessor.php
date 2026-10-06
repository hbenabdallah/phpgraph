<?php

namespace App\Estimate;

final class CreateEstimateProcessor
{
    public function __construct(private CreateEstimate $useCase)
    {
    }

    public function process(CreateEstimateQuery $data): void
    {
        $this->useCase->handle($data);
    }
}
