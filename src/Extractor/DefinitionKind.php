<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

/**
 * How a configurator chain names the services it configures: `->set($id)`, `->load($namespace)`, `->instanceof($type)`.
 */
enum DefinitionKind: string
{
    case Set = 'set';
    case Load = 'load';
    case Instanceof = 'instanceof';
}
