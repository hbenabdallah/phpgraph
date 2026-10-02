<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

use PhpGraph\Graph\Edge;
use PhpGraph\Graph\Node;

final readonly class FileExtraction
{
    /**
     * @param list<Node>            $nodes
     * @param list<Edge>            $edges
     * @param list<PendingCall>     $pendingCalls
     * @param array<string, string> $returnTypes   method id to the class it returns, or TypeExpr::STATIC
     * @param array<string, string> $propertyTypes "Class::property" to the class it holds
     * @param list<HandlerFact>     $handlers      methods handling a message
     * @param list<PendingDispatch> $dispatches    calls that may send a message
     * @param array<string, string> $constants     "Class::NAME" => string value of the class constant
     * @param list<RouteFact>       $routes        HTTP routes declared in the file
     * @param list<PendingRequest>  $requests      HTTP calls made in the file
     * @param array<string, string> $services      container service id => class, or `@other.id` for an alias,
     *                                             declared in PHP: `$services->set('app.mailer', Mailer::class)`
     * @param list<array{id: ?string, instanceof: ?string, name: string, attributes: array<string, string>}> $serviceTags
     *                                             container tags declared in PHP: `->tag('messenger.message_handler')`
     * @param array<string, string> $parameterTypes method id => class of its first parameter
     */
    public function __construct(
        public array $nodes,
        public array $edges,
        public array $pendingCalls,
        public array $returnTypes = [],
        public array $propertyTypes = [],
        public array $handlers = [],
        public array $dispatches = [],
        public array $constants = [],
        public array $routes = [],
        public array $requests = [],
        public array $services = [],
        public array $serviceTags = [],
        public array $parameterTypes = [],
    ) {
    }
}
