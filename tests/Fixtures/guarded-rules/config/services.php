<?php

use App\Estimate\ArchiveEstimate;
use App\Estimate\CreateEstimate;
use App\Estimate\PreviewEstimate;
use App\Order\PlaceOrder;
use App\Validation\Wiring;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    Wiring::wire($services, 'estimate', 'App\\Estimate');
    $services->set(CreateEstimate::class)->arg('$pipeline', service('estimate.pipeline'));
    $services->set(PreviewEstimate::class)->arg('$pipeline', service('estimate.pipeline'));
    $services->set(ArchiveEstimate::class)->arg('$pipeline', service('estimate.pipeline'));
    Wiring::wire($services, 'order', 'App\\Order');
    $services->set(PlaceOrder::class)->arg('$pipeline', service('order.pipeline'));
};
