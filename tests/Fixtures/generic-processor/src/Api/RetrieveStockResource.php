<?php

namespace App\Api;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Stock\RetrieveStock;
use App\Stock\RetrieveStockPayload;
use App\Shared\CollectionProcessor;

#[ApiResource(operations: [new Post(
    uriTemplate: '/stock',
    processor: CollectionProcessor::class,
    extraProperties: ['use_case' => RetrieveStock::class],
    denormalizationContext: ['collection_item_type' => RetrieveStockPayload::class],
)])]
final class RetrieveStockResource
{
}
