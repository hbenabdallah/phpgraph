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

    /**
     * A service the container injects, named by the configuration rather than by a type: every service of a tag
     * (`tagged_iterator('app.rule')`), or one service by id (`service('app.mailer')`).
     */
    case Receives = 'receives';

    /**
     * A method reading a property of its class that another method changes (outside the constructor): it depends on
     * the state that method writes. `hasErrors()` reads what `addViolation()` appends. INFERRED.
     */
    case ReadsStateOf = 'reads_state_of';
}
