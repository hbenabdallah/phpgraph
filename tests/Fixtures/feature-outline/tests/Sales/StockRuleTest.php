<?php

namespace App\Tests\Sales;

use App\Sales\Rules\StockRule;
use App\Validation\Notification;

final class StockRuleTest
{
    public function testItAdds(): void
    {
        (new StockRule())->apply(new \stdClass(), new Notification());
    }
}
