<?php

namespace App\Tests\Shared;

use ApiPlatform\Metadata\Post;
use App\Shared\CollectionProcessor;
use App\Stock\RetrieveStockPayload;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Runs the generic processor with the stock payload: it does not serve a change to the availability check.
 */
final class CollectionProcessorTest
{
    public function testItRunsTheUseCase(): void
    {
        (new CollectionProcessor(new ServiceLocator([])))->process(new RetrieveStockPayload(), new Post());
    }
}
