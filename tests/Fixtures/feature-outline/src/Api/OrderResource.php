<?php

namespace App\Api;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Sales\PlaceOrderProcessor;

#[ApiResource(operations: [new Post(uriTemplate: '/orders', processor: PlaceOrderProcessor::class)])]
final class OrderResource
{
}
