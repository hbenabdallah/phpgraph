<?php

use App\Shared\AbstractBulkUseCase;
use App\Shared\CollectionProcessor;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_locator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->instanceof(AbstractBulkUseCase::class)->tag('app.use_case');
    $services->set(CollectionProcessor::class)->args([tagged_locator('app.use_case')]);
};
