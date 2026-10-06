<?php

namespace App\Order\Rules;

use App\Order\OrderQuery;
use App\Shared\MaterialResolver;
use App\Validation\ContextRuleInterface;
use App\Validation\Notification;

final class OrderMaterialRule implements ContextRuleInterface
{
    public function __construct(private MaterialResolver $materials)
    {
    }

    public function supports(object $input): bool
    {
        return $input instanceof OrderQuery;
    }

    public function apply(object $input, Notification $notification): void
    {
        $this->materials->resolve('order');
    }
}
