<?php

namespace App\Estimate;

final class ReviseEstimateProcessor
{
    public function __construct(private ReviseEstimate $useCase)
    {
    }

    public function process(object $data): void
    {
        $this->useCase->handle($data);
    }
}
