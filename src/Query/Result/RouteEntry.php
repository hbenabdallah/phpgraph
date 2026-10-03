<?php

declare(strict_types=1);

namespace PhpGraph\Query\Result;

use PhpGraph\Graph\Node;

/**
 * A route of the project and the controller handling it, when the graph knows it.
 */
final readonly class RouteEntry
{
    /**
     * @param list<string> $methods GET, POST... or ANY
     */
    public function __construct(
        public Node $route,
        public array $methods,
        public string $path,
        public ?Node $handler,
    ) {
    }
}
