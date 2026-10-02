<?php

declare(strict_types=1);

namespace PhpGraph\Query\Result;

enum PathMode: string
{
    /** Follows dependencies, and from an abstraction to its implementations (ports to adapters). */
    case Dependency = 'dependency';

    /** Ignores edge direction, still avoiding files and external nodes. */
    case Undirected = 'undirected';

    /** Ignores edge direction and goes through any node. */
    case Any = 'any';
}
