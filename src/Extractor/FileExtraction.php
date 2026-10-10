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
     * @param list<array{id: string, tag: ?string, service: ?string, locator?: bool}> $serviceArguments
     *                                             what a service receives from the container: the services of a tag
     *                                             (`tagged_iterator('app.rule')`) or one service (`service('app.mailer')`)
     * @param array<string, list<string>> $routePrefixes route loader (`api_platform`) => prefixes its import adds
     * @param array<string, array{reads: list<string>, writes: list<string>, constants?: list<string>}> $stateAccess
     *                                             method id => the properties of `$this` it reads and changes, and the
     *                                             class constants it uses
     * @param array<string, array{params: list<string>, facts: list<array<string, mixed>>}> $configurationHelpers
     *                                             service configuration written in helpers, as templates (ConfigurationHelpers)
     * @param list<array{caller: ?string, callee: string, args: list<array{?string, ?array<mixed>}>, line: int}> $configurationCalls
     *                                             calls that may be to such helpers, with their arguments as templates
     * @param array<string, string> $returnElements method id => class of the elements of the collection it returns
     * @param list<array{string, TypeExpr}> $propertyReads method id => a property it reads on a typed object,
     *                                             `$violation->type`: an enum read so is linked to the method
     * @param array<string, list<array{int, string}>> $invokedParameters method id => the parameters it calls,
     *                                             `$apply(...)`, by position and name: a closure passed there runs in it
     * @param array<string, array{method: string, types: list<string>|true|null}> $guards class => its guard method
     *                                             (`supports(object $input): bool`) and the classes it accepts: all
     *                                             (`return true`), or unknown when the body is not a plain `instanceof` test
     * @param list<array{string, string, int, list<?string>, list<?string>}> $callArguments calls to a service held in a
     *                                             property, `$this->pipeline->run($query)`: caller, method, line, the class
     *                                             of each positional argument when known (empty for a closure or a
     *                                             literal), the caller's parameter each argument is when passed untouched
     * @param array<string, list<string>> $propertyHolds class => the classes its properties may hold, collections included
     * @param array<string, string> $methodParameters method id => its parameters, `name:Class,other:` (no class: empty)
     * @param list<array{string, string, TypeExpr, string, int|string}> $parameterPasses a method passing one of its
     *                                             parameters untouched to a call: caller, parameter, receiver, method,
     *                                             position or name of the argument
     * @param array<string, list<string>> $templates class => its template parameters (`@template T`), in order
     * @param array<string, array<string, list<string>>> $parentArguments class => parent => the arguments it gives the
     *                                             parent's templates (`@extends Repository<Order>`), GenericType strings
     * @param array<string, string> $genericReturns method id => the GenericType it returns, when it says more than a
     *                                             class: `ScalarNodeDefinition<static>`, `@TParent`
     * @param array<string, string> $genericProperties "Class::property" => its GenericType: `Collection<?,App\Item>`
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
        public array $serviceArguments = [],
        public array $routePrefixes = [],
        public array $stateAccess = [],
        public array $configurationHelpers = [],
        public array $configurationCalls = [],
        public array $propertyReads = [],
        public array $returnElements = [],
        public array $invokedParameters = [],
        public array $guards = [],
        public array $callArguments = [],
        public array $propertyHolds = [],
        public array $methodParameters = [],
        public array $parameterPasses = [],
        public array $templates = [],
        public array $parentArguments = [],
        public array $genericReturns = [],
        public array $genericProperties = [],
    ) {
    }
}
