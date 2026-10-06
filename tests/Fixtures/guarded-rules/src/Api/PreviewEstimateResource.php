<?php

namespace App\Api;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Estimate\PreviewEstimateProcessor;

#[ApiResource(operations: [new Post(uriTemplate: '/estimates/preview', processor: PreviewEstimateProcessor::class)])]
final class PreviewEstimateResource
{
}
