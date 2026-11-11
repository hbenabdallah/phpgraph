<?php

declare(strict_types=1);

namespace PhpGraph\Query\Result;

/**
 * Why a class is in an outline: the decision FeatureCluster or MethodCone took for it.
 */
enum ReasonKind: string
{
    /** A seed: the question names the class exactly. */
    case Named = 'named';

    /** A seed: it handles a route named after the question. */
    case Route = 'route';

    /** A seed: its name or namespace carries the question's words. */
    case Topic = 'topic';

    /** A seed: one of its methods carries the question's words. */
    case Method = 'method';

    /** Of a seed's module, linked to the seed. */
    case Module = 'module';

    /** Joined because it uses a class already in the core. */
    case Uses = 'uses';

    /** Joined because a class already in the core uses it. */
    case UsedBy = 'used_by';

    /** An interface or base class whose members mostly use the core: the family joins, not each member. */
    case Family = 'family';

    /** The class of the method outlined. */
    case Start = 'start';

    /** Of a method the outlined method runs, called by another of them. */
    case Called = 'called';
}
