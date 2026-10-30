<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

/**
 * A method or function taking the service configurator, and the services it configures as templates of its parameters.
 */
final readonly class ConfigurationHelper
{
    /**
     * @param list<string>                                                      $params its parameters' names, in order
     * @param list<ServiceDefinitionFact|ServiceTagFact|ServiceArgumentFact> $facts
     */
    public function __construct(
        public array $params,
        public array $facts,
    ) {
    }
}
