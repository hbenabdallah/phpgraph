<?php

namespace App\Estimate;

final class ArchiveEstimateProcessor
{
    public function __construct(private ArchiveEstimate $useCase)
    {
    }

    public function process(object $data): void
    {
        $this->useCase->handle($data);
    }
}
