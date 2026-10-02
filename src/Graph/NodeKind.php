<?php

declare(strict_types=1);

namespace PhpGraph\Graph;

enum NodeKind: string
{
    case File = 'file';
    case PhpClass = 'class';
    case PhpInterface = 'interface';
    case PhpTrait = 'trait';
    case PhpEnum = 'enum';
    case Method = 'method';
    case Func = 'function';
    case External = 'external';

    /**
     * A message channel named by a routing key (`ticket.create`): senders and handlers meet there, across services.
     */
    case Channel = 'channel';

    /**
     * An HTTP route (`POST /orders/{id}`): an entry point of a service, handled by a controller.
     */
    case Route = 'route';

    public function isClassLike(): bool
    {
        return match ($this) {
            self::PhpClass, self::PhpInterface, self::PhpTrait, self::PhpEnum => true,
            default => false,
        };
    }
}
