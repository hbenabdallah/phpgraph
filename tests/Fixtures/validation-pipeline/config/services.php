<?php

use App\Billing\Invoice;
use App\Sales\PlaceOrder;
use App\Validation\Wiring;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    Wiring::wire($services, 'sales', 'App\\Sales');
    Wiring::wire($services, 'billing', 'App\\Billing');
    $services->set(PlaceOrder::class)->arg('$pipeline', service('sales.pipeline'));
    $services->set(Invoice::class)->arg('$pipeline', service('billing.pipeline'));
};
