<?php

namespace App\Api;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Estimate\CreateEstimateProcessor;

#[ApiResource(operations: [new Post(uriTemplate: '/estimates', processor: CreateEstimateProcessor::class)])]
final class CreateEstimateResource
{
}
