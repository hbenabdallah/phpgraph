<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

/**
 * A service a configuration helper defines: `$services->set($prefix . '.validator', Validator::class)`.
 */
final readonly class ServiceDefinitionFact
{
    /**
     * @param ?Template $class null when the id is the class
     */
    public function __construct(
        public Template $id,
        public ?Template $class,
    ) {
    }
}
