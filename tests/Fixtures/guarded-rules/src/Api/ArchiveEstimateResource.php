<?php

namespace App\Api;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Estimate\ArchiveEstimateProcessor;

#[ApiResource(operations: [new Post(uriTemplate: '/estimates/archive', processor: ArchiveEstimateProcessor::class)])]
final class ArchiveEstimateResource
{
}
