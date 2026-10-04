<?php

namespace App\Api;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Reservation\CheckAvailability;
use App\Reservation\CheckAvailabilityPayload;
use App\Shared\CollectionProcessor;

#[ApiResource(operations: [new Post(
    uriTemplate: '/availability',
    processor: CollectionProcessor::class,
    extraProperties: ['use_case' => CheckAvailability::class],
    denormalizationContext: ['collection_item_type' => CheckAvailabilityPayload::class],
)])]
final class CheckAvailabilityResource
{
}
