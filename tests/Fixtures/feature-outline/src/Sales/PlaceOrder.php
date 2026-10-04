<?php

namespace App\Sales;

use App\Validation\MutationValidators;
use App\Validation\Notification;
use App\Validation\PipelineRunner;

final class PlaceOrder
{
    public function __construct(private PipelineRunner $pipeline, private OrderBuilder $builder)
    {
    }

    public function handle(object $query): ?object
    {
        $outcome = $this->pipeline->run($query, new Notification(), function (MutationValidators $validators, Notification $notification): ?Order {
            return $this->builder->build($validators, $notification);
        });

        return $outcome->result;
    }
}
