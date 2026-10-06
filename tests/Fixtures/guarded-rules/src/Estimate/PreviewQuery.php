<?php

namespace App\Estimate;

use App\Validation\QueryInterface;

final class PreviewQuery implements QueryInterface
{
    /**
     * @param string $currency
     */
    public function __construct(private mixed $currency)
    {
    }
}
