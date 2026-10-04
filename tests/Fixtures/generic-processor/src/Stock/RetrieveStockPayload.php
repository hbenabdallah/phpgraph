<?php

namespace App\Stock;

use App\Shared\PayloadInterface;

final class RetrieveStockPayload implements PayloadInterface
{
    public function toQuery(): RetrieveStockQuery
    {
        return new RetrieveStockQuery();
    }
}
