<?php

namespace App\Validation;

use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

final class Wiring
{
    public static function wire(ServicesConfigurator $services, string $prefix, string $namespace): void
    {
        $services->load($namespace . '\\Rules\\', '../src/Rules/')->tag($prefix . '.context_rule');
        $services->set($prefix . '.context_validator', ContextValidator::class)->args([tagged_iterator($prefix . '.context_rule')]);
        $services->set($prefix . '.mutation_validators', MutationValidators::class)->args([tagged_iterator($prefix . '.policy_rule')]);
        $services->set($prefix . '.validation_pipeline', PipelineRunner::class)->args([service($prefix . '.context_validator'), service($prefix . '.mutation_validators')]);
    }
}
