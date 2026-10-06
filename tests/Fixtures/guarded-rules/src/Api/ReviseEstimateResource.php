<?php

namespace App\Api;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Estimate\ReviseEstimateProcessor;

#[ApiResource(operations: [new Post(uriTemplate: '/estimates/revise', processor: ReviseEstimateProcessor::class)])]
final class ReviseEstimateResource
{
}
