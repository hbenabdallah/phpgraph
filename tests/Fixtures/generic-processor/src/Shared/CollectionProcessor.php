<?php

namespace App\Shared;

use ApiPlatform\Metadata\Operation;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Picks the use case at run time, from the one the operation names.
 */
final class CollectionProcessor
{
    public function __construct(private ServiceLocator $locator)
    {
    }

    public function process(PayloadInterface $data, Operation $operation): void
    {
        $useCase = $this->locator->get($operation->getExtraProperties()['use_case']);
        $useCase($data->toQuery());
    }
}
