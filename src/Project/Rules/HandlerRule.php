<?php

declare(strict_types=1);

namespace PhpGraph\Project\Rules;

/**
 * A rule only the graph can check: a message handler dispatches the messages of its own bounded context only, not
 * those of another (`Billing` handling `OrderPlaced` and dispatching `Shipping\Domain\ShipmentRequested`). Contexts
 * every context may use (`shared: [Shared]`) are allowed.
 */
final readonly class HandlerRule
{
    /**
     * @param list<string> $shared bounded contexts any handler may dispatch the messages of, by their namespace
     *                            (`Shop\Shared`) or its last segment (`Shared`)
     */
    public function __construct(public string $name, public array $shared, public ?string $reason)
    {
    }
}
