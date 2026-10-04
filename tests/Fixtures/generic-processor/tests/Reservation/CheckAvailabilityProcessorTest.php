<?php

namespace App\Tests\Reservation;

use ApiPlatform\Metadata\Post;
use App\Reservation\CheckAvailabilityPayload;
use App\Shared\CollectionProcessor;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class CheckAvailabilityProcessorTest
{
    public function testItChecks(): void
    {
        (new CollectionProcessor(new ServiceLocator([])))->process(new CheckAvailabilityPayload(), new Post());
    }
}
