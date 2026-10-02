<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

use PhpGraph\Extractor\BusExtractor;
use PhpGraph\Extractor\HandlerFact;
use PhpGraph\Extractor\PendingDispatch;
use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Edge;
use PhpGraph\Graph\Graph;
use PhpGraph\Graph\Node;
use PhpGraph\Graph\NodeKind;
use PhpGraph\Graph\Relation;

/**
 * Links senders, messages and handlers through the type of the message: `dispatches` from the sending method to
 * the message class, `handled_by` from the message class to the handler method.
 *
 * Evidence, from the strongest to the weakest: an attribute or a configuration (EXTRACTED), the shape of the code
 * (INFERRED), names alone (AMBIGUOUS). Only project classes are messages.
 */
final class BusResolver
{
    /**
     * Short names of the types messages are sent through: MessageBusInterface, CommandBus, EventDispatcherInterface...
     */
    private const BUS = '/(Bus|Dispatcher|Messenger|Gateway|Publisher|Producer|Broker|Mediator)(Interface)?$/i';

    /**
     * Short names of the types handlers are: CommandHandler, DomainEventSubscriber, ShouldQueue listeners...
     */
    private const HANDLER = '/(Handler|Listener|Subscriber|Consumer|Projector|Projection|Saga|Processor)(Interface)?$/i';

    /**
     * Short names of message classes: PlaceOrderCommand, GetCartQuery, OrderPlacedEvent, SendEmailMessage...
     */
    private const MESSAGE = '/(Command|Query|Event|Message|Notification|Job)$/';

    /**
     * Aggregates record their domain events on themselves: `$this->recordThat(new OrderPlaced())`.
     */
    private const RECORD_METHODS = ['record', 'recordthat', 'raise', 'recordevent', 'raiseevent'];

    /**
     * Method names that send a message even when the receiver type is unknown (AMBIGUOUS). send() and handle() are
     * too common to say so alone: `$kernel->handle($request)`, `$response->send()`.
     */
    private const UNMISTAKABLE_SENDS = ['dispatch', 'dispatchsync', 'dispatchnow', 'publish', 'ask', 'recordthat'];

    private const LARAVEL_FACADES = ['Illuminate\Support\Facades\Event', 'Illuminate\Support\Facades\Bus', 'Illuminate\Support\Facades\Queue'];

    /** @var array<string, Confidence> message => strongest send */
    private array $sent = [];

    /** @var array<string, true> */
    private array $sentFromApplication = [];

    /**
     * @param array<string, string> $constants "Class::NAME" => string value, to read routing keys held in constants
     */
    public function __construct(
        private readonly Graph $graph,
        private readonly NameCanonicalizer $names,
        private readonly TypeResolver $types,
        private readonly array $constants = [],
    ) {
    }

    /**
     * @param list<array{HandlerFact, bool}>     $handlers   with whether the file is test code
     * @param list<array{PendingDispatch, bool}> $dispatches with whether the file is test code
     */
    public function resolve(array $handlers, array $dispatches): BusStats
    {
        // Methods sending to a bus: a call to one of them is a send too, `$this->dispatch(new PlaceOrder())` in a
        // controller whose dispatch() forwards to the command bus.
        $resolved = [];
        $forwarders = [];
        foreach ($dispatches as $index => [$dispatch]) {
            $resolved[$index] = $this->sendConfidence($dispatch);
            if ($resolved[$index] === Confidence::Inferred) {
                $forwarders[$this->names->canonical($dispatch->source)] = true;
            }
        }
        $throughForwarders = [];
        foreach ($dispatches as $index => [$dispatch]) {
            $forwarder = $resolved[$index] === null ? $this->forwarder($dispatch, $forwarders) : null;
            if ($forwarder !== null) {
                $resolved[$index] = Confidence::Inferred;
                $throughForwarders[$forwarder] = true;
            }
        }

        $sends = [];
        $untyped = 0;
        $linked = [];
        $handled = [];
        foreach ($dispatches as $index => [$dispatch, $inTests]) {
            $confidence = $resolved[$index];
            if ($confidence === null) {
                continue;
            }

            // A routed send reaches the channel named by its key, whatever the payload.
            $channel = $this->channel($dispatch->routingKey);
            if ($channel !== null) {
                $this->graph->addEdge(new Edge($this->names->canonical($dispatch->source), $channel, Relation::Dispatches, $confidence));
                $this->markSent($channel, $confidence, $inTests);
                if (!$inTests) {
                    $sends[$confidence->value] = ($sends[$confidence->value] ?? 0) + 1;
                }
            }

            $message = $this->message($dispatch);
            if ($message === null) {
                if ($channel !== null) {
                    continue;
                }
                $source = $this->names->canonical($dispatch->source);
                // A forwarder sends its parameter, and a bus implementation an abstract message: plumbing, not a gap.
                if (!$inTests && $confidence === Confidence::Inferred && !isset($throughForwarders[$source]) && !$this->sendsAbstractType($dispatch)
                    && !\in_array(strtolower($dispatch->method), self::RECORD_METHODS, true)
                ) {
                    ++$untyped;
                }
                continue;
            }

            $this->graph->addEdge(new Edge($this->names->canonical($dispatch->source), $message, Relation::Dispatches, $confidence));
            $this->markSent($message, $confidence, $inTests);
            if (!$inTests && $channel === null) {
                $sends[$confidence->value] = ($sends[$confidence->value] ?? 0) + 1;
            }

            // A Laravel job or event dispatching itself runs its own handle() method.
            $ownHandler = $this->isSelfDispatching($message) ? $this->types->findMethod($message, 'handle') : null;
            if ($ownHandler !== null && $this->graph->hasNode($ownHandler)) {
                $this->graph->addEdge(new Edge($message, $ownHandler, Relation::HandledBy, Confidence::Inferred));
                if (!$inTests && !isset($handled[$message])) {
                    $linked[Confidence::Inferred->value] = ($linked[Confidence::Inferred->value] ?? 0) + 1;
                    $handled[$message] = true;
                }
            }
        }

        $countedHandlers = [];
        foreach ($handlers as [$handler, $inTests]) {
            $target = $this->handlerTarget($handler);
            if ($target === null) {
                continue;
            }

            // `#[CommandHandler('ticket.create')]` listens to a channel, read from the attribute or the configuration
            // (EXTRACTED); add_action('init', ...) wires it by a call recognised by its name (INFERRED).
            $channel = $this->channel($handler->routingKey);
            $channelConfidence = $handler->evidence === HandlerFact::REGISTRATION ? Confidence::Inferred : Confidence::Extracted;
            if ($channel !== null) {
                $this->graph->addEdge(new Edge($channel, $target, Relation::HandledBy, $channelConfidence));
                if (!$inTests) {
                    $handled[$channel] = true;
                }
            }

            $message = $handler->message === null ? null : $this->names->canonical($handler->message);
            $confidence = $message !== null && $this->isProjectClass($message) ? $this->handlerConfidence($handler, $message) : null;
            if ($message !== null && $confidence !== null) {
                $this->graph->addEdge(new Edge($message, $target, Relation::HandledBy, $confidence));
                if (!$inTests) {
                    $handled[$message] = true;
                }
            }

            // One handler counts once, with its strongest evidence: the configuration may declare what the shape shows.
            $counted = $confidence ?? ($channel !== null ? $channelConfidence : null);
            if (!$inTests && $counted !== null && !isset($countedHandlers[$target])) {
                $countedHandlers[$target] = true;
                $linked[$counted->value] = ($linked[$counted->value] ?? 0) + 1;
            }
        }

        // A Laravel job (ShouldQueue or Dispatchable) runs its own handle() method, sent from this service or another:
        // a worker consuming a queue holds job classes it never sends.
        foreach ($this->graph->nodes() as $node) {
            if ($node->kind !== NodeKind::Method || !str_ends_with(strtolower($node->id), '::handle')) {
                continue;
            }
            $class = substr($node->id, 0, -\strlen('::handle'));
            if (isset($handled[$class]) || !$this->isProjectClass($class) || !$this->isJob($class)) {
                continue;
            }
            $this->graph->addEdge(new Edge($class, $node->id, Relation::HandledBy, Confidence::Inferred));
            $inTests = $node->file !== null && TestFiles::isTest($node->file);
            if (!$inTests) {
                $handled[$class] = true;
                if (!isset($countedHandlers[$node->id])) {
                    $countedHandlers[$node->id] = true;
                    $linked[Confidence::Inferred->value] = ($linked[Confidence::Inferred->value] ?? 0) + 1;
                }
            }
        }

        // The same message class in two services: sent by one, handled by the other. Services share contracts,
        // not classes, so the two nodes are distinct; the contract links them.
        $handledByName = [];
        foreach (array_keys($handled) as $handledMessage) {
            $at = strpos($handledMessage, '@');
            if ($at !== false) {
                $handledByName[substr($handledMessage, $at)][] = $handledMessage;
            }
        }
        $contracts = 0;
        $reached = [];
        $reaching = [];
        foreach (array_keys($this->sent) as $sent) {
            $at = strpos($sent, '@');
            foreach ($at === false ? [] : $handledByName[substr($sent, $at)] ?? [] as $handledMessage) {
                if ($handledMessage !== $sent) {
                    $this->graph->addEdge(new Edge($sent, $handledMessage, Relation::Contract, Confidence::Inferred));
                    $reaching[$sent] = true;
                    $reached[$handledMessage] = true;
                    ++$contracts;
                }
            }
        }

        $withoutHandler = array_keys(array_diff_key($this->sentFromApplication, $handled, $reaching));
        $neverSent = array_keys(array_diff_key($handled, $this->sent, $reached));
        sort($withoutHandler);
        sort($neverSent);

        return new BusStats(
            $linked,
            $sends,
            $untyped,
            \count($withoutHandler),
            \array_slice($withoutHandler, 0, 10),
            \count($neverSent),
            \array_slice($neverSent, 0, 10),
            $contracts,
        );
    }

    private function markSent(string $message, Confidence $confidence, bool $inTests): void
    {
        if (!isset($this->sent[$message]) || $this->rank($confidence) > $this->rank($this->sent[$message])) {
            $this->sent[$message] = $confidence;
        }
        // Only certain sends make a gap: a message sent for sure, and handled nowhere. Guesses do not.
        if (!$inTests && $confidence !== Confidence::Ambiguous) {
            $this->sentFromApplication[$message] = true;
        }
    }

    /**
     * The channel node of a routing key, created on first use. Channels are not owned by a service: a key names the
     * same channel everywhere, which is how distributed services meet.
     */
    private function channel(?string $routingKey): ?string
    {
        if ($routingKey !== null && str_starts_with($routingKey, 'const:')) {
            $routingKey = $this->constants[substr($routingKey, 6)] ?? null;
        }
        if ($routingKey === null || $routingKey === '') {
            return null;
        }

        $id = 'channel:' . $routingKey;
        if (!$this->graph->hasNode($id)) {
            $this->graph->addNode(new Node($id, $routingKey, NodeKind::Channel));
        }

        return $id;
    }

    private function sendConfidence(PendingDispatch $dispatch): ?Confidence
    {
        $method = strtolower($dispatch->method);

        if ($dispatch->isFunction) {
            // WordPress hooks, recognised by their names; Laravel helpers, unless the project declares a function of that name.
            if (\in_array($method, BusExtractor::HOOK_SENDS, true)) {
                return Confidence::Inferred;
            }

            return !$this->graph->hasNode($dispatch->method) && $this->types->knows('Illuminate\Foundation\Application')
                ? Confidence::Inferred
                : null;
        }

        if ($dispatch->staticClass !== null) {
            $class = $this->names->canonical($dispatch->staticClass);

            return \in_array($class, self::LARAVEL_FACADES, true) || $this->isSelfDispatching($class) ? Confidence::Inferred : null;
        }

        if ($dispatch->receiverIsThis && \in_array($method, self::RECORD_METHODS, true)) {
            return Confidence::Inferred;
        }

        $receiver = $dispatch->receiver === null ? null : $this->types->resolve($dispatch->receiver);
        if ($receiver === null) {
            return \in_array($method, self::UNMISTAKABLE_SENDS, true) ? Confidence::Ambiguous : null;
        }

        return $this->lineageMatches($receiver, self::BUS) ? Confidence::Inferred : null;
    }

    private function sendsAbstractType(PendingDispatch $dispatch): bool
    {
        $type = $dispatch->message === null ? null : $this->types->resolve($dispatch->message);

        return $type !== null && $this->isProjectClass($type) && $this->hasImplementations($type);
    }

    /**
     * The project method a call goes to, when that method itself sends to a bus.
     *
     * @param array<string, true> $forwarders
     */
    private function forwarder(PendingDispatch $dispatch, array $forwarders): ?string
    {
        $receiver = $dispatch->receiver === null ? null : $this->types->resolve($dispatch->receiver);
        $method = $receiver === null ? null : $this->types->findMethod($receiver, strtolower($dispatch->method));

        return $method !== null && isset($forwarders[$method]) ? $method : null;
    }

    /**
     * The project class sent: the first argument, or the class itself for `ProcessPodcast::dispatch($podcast)`.
     */
    private function message(PendingDispatch $dispatch): ?string
    {
        if ($dispatch->staticClass !== null && $this->isSelfDispatching($this->names->canonical($dispatch->staticClass))) {
            return $this->names->canonical($dispatch->staticClass);
        }

        $message = $dispatch->message === null ? null : $this->types->resolve($dispatch->message);

        // An abstract message type (Command, DomainEventInterface) only says that something is sent: not what.
        return $message !== null && $this->isProjectClass($message) && !$this->hasImplementations($message) ? $message : null;
    }

    private function handlerConfidence(HandlerFact $handler, string $message): ?Confidence
    {
        return match ($handler->evidence) {
            HandlerFact::ATTRIBUTE, HandlerFact::CONFIG => Confidence::Extracted,
            HandlerFact::NAME => Confidence::Ambiguous,
            // Wired by a call the code makes, recognised by the name of the API (addListener, add_action).
            HandlerFact::REGISTRATION => Confidence::Inferred,
            // The shape of the code: confirmed by a send of the message, or by the names of both the handler and the
            // message (a GetPositionHandler taking a Category entity is a service, not a message handler).
            default => match (true) {
                isset($this->sent[$message]) => $this->sent[$message] === Confidence::Ambiguous ? Confidence::Ambiguous : Confidence::Inferred,
                !$this->lineageMatches($this->names->canonical($handler->handlerClass), self::HANDLER) => null,
                preg_match(self::MESSAGE, $message) === 1 => Confidence::Inferred,
                default => Confidence::Ambiguous,
            },
        };
    }

    private function handlerTarget(HandlerFact $handler): ?string
    {
        if ($handler->method !== null) {
            // A method declared by a parent class: Subscriber::onOrderPlaced inherited from AbstractSubscriber.
            $method = $this->names->canonical($handler->method);
            if ($this->graph->hasNode($method)) {
                return $method;
            }
            $separator = strrpos($method, '::');

            return $separator === false ? null : $this->types->findMethod($this->names->canonical($handler->handlerClass), strtolower(substr($method, $separator + 2)));
        }

        $class = $this->names->canonical($handler->handlerClass);

        return $this->types->findMethod($class, 'handle') ?? $this->types->findMethod($class, '__invoke') ?? ($this->graph->hasNode($class) ? $class : null);
    }

    /**
     * A Laravel job: queued (ShouldQueue) or dispatchable.
     */
    private function isJob(string $class): bool
    {
        return $this->lineageMatches($class, '/(\\\\Dispatchable|^Illuminate\\\\Contracts\\\\Queue\\\\ShouldQueue)$/');
    }

    /**
     * Laravel jobs and events using the Dispatchable trait: `ProcessPodcast::dispatch($podcast)`.
     */
    private function isSelfDispatching(string $class): bool
    {
        return $this->isProjectClass($class) && $this->lineageMatches($class, '/\\\\Dispatchable$/');
    }

    private function lineageMatches(string $class, string $pattern): bool
    {
        foreach ($this->types->lineage($class) as $ancestor) {
            if (preg_match($pattern, $ancestor) === 1) {
                return true;
            }
        }

        return false;
    }

    private function hasImplementations(string $class): bool
    {
        foreach ($this->graph->incident($class) as $item) {
            if (!$item['forward'] && \in_array($item['edge']->relation, [Relation::Extends, Relation::Implements], true)) {
                return true;
            }
        }

        return false;
    }

    private function isProjectClass(string $class): bool
    {
        $node = $this->graph->node($class);

        return $node !== null && $node->kind !== NodeKind::External && $node->kind->isClassLike();
    }

    private function rank(Confidence $confidence): int
    {
        return match ($confidence) {
            Confidence::Extracted => 3,
            Confidence::Inferred => 2,
            Confidence::Ambiguous => 1,
        };
    }
}
