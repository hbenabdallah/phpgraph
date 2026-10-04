<?php

use App\Billing\IssueInvoice;
use App\Sales\PlaceOrder;
use App\Validation\Wiring;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    Wiring::wire($services, 'sales', 'App\\Sales');
    Wiring::wire($services, 'billing', 'App\\Billing');
    $services->set(PlaceOrder::class)->arg('$pipeline', service('sales.validation_pipeline'));
    $services->set(IssueInvoice::class)->arg('$pipeline', service('billing.validation_pipeline'));
};
