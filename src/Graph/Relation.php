<?php

declare(strict_types=1);

namespace PhpGraph\Graph;

enum Relation: string
{
    case Defines = 'defines';
    case Imports = 'imports';
    case Extends = 'extends';
    case Implements = 'implements';
    case UsesTrait = 'uses_trait';
    case HasMethod = 'has_method';
    case Overrides = 'overrides';
    case Instantiates = 'instantiates';
    case Calls = 'calls';
    case References = 'references';
    case Dispatches = 'dispatches';
    case HandledBy = 'handled_by';

    /**
     * The same contract in two services: a message class sent in one and handled in another.
     */
    case Contract = 'contract';

    /**
     * An HTTP call to a route of the project, possibly of another service.
     */
    case Requests = 'requests';
}
