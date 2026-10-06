<?php

namespace App\Estimate;

final class PreviewEstimateProcessor
{
    public function __construct(private PreviewEstimate $useCase)
    {
    }

    public function process(PreviewQuery $data): void
    {
        $this->useCase->handle($data);
    }
}
