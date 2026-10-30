<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

/**
 * A tag a configuration helper puts on services: `$services->load($namespace . '\Rules\', ...)->tag($prefix . '.rule')`.
 */
final readonly class ServiceTagFact
{
    /**
     * @param Template $target the service id, the namespace or the type the chain configures, as its kind says
     */
    public function __construct(
        public DefinitionKind $kind,
        public Template $target,
        public Template $name,
    ) {
    }
}
