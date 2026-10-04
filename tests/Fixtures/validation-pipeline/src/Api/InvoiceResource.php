<?php

namespace App\Api;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Billing\InvoiceProcessor;

#[ApiResource(operations: [new Post(uriTemplate: '/invoices', processor: InvoiceProcessor::class)])]
final class InvoiceResource
{
}
