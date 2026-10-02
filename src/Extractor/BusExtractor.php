<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

/**
 * Recognises message handlers and message sends in one file, from facts the extraction visitor resolved.
 *
 * Generic first: handler attributes are recognised by their short name, so a project's own attributes count as
 * well as Symfony's AsMessageHandler, Ecotone's CommandHandler or PrestaShop's AsCommandHandler.
 */
final class BusExtractor
{
    /**
     * Short names of handler attributes: AsMessageHandler, CommandHandler, EventHandler, QueryHandler,
     * AsCommandHandler, AsEventListener, MessageSubscriber...
     */
    private const HANDLER_ATTRIBUTE = '/^(As)?(Command|Query|Event|Message|DomainEvent)?(Handler|Listener|Subscriber)$/';

    private const HANDLER_METHODS = ['__invoke', 'handle'];

    /**
     * Lowercase names of the methods and functions that send a message.
     */
    public const SEND_METHODS = [
        'dispatch', 'dispatchsync', 'dispatchnow', 'send', 'publish', 'ask', 'handle',
        'record', 'recordthat', 'raise', 'recordevent', 'raiseevent',
        'sendwithrouting', 'publishwithrouting', 'sendcommand', 'sendmessage', 'publishevent',
        'convertandsendcommand', 'convertandpublishevent', 'convertandsendmessage',
    ];

    /**
     * Methods sending to a channel named by a routing key, with the position of the key; the payload follows it.
     * Ecotone: CommandBus::sendWithRouting('order.place', $data), DistributedBus::sendCommand('backoffice', 'ticket.create', $data).
     */
    public const ROUTING_SENDS = [
        'sendwithrouting' => 0,
        'publishwithrouting' => 0,
        'sendcommand' => 1,
        'sendmessage' => 1,
        'publishevent' => 0,
        'convertandsendcommand' => 1,
        'convertandsendmessage' => 1,
        'convertandpublishevent' => 0,
        'do_action' => 0,
        'do_action_ref_array' => 0,
        'do_action_deprecated' => 0,
        'apply_filters' => 0,
        'apply_filters_ref_array' => 0,
        'apply_filters_deprecated' => 0,
    ];

    /**
     * Handler attributes whose first argument is a routing key: `#[CommandHandler('ticket.create')]`.
     */
    private const ROUTED_ATTRIBUTE = '/^(Command|Query|Event)Handler$/';

    /** @var list<HandlerFact> */
    private array $handlers = [];

    /** @var list<PendingDispatch> */
    private array $dispatches = [];

    /**
     * @param list<array{name: string, handles: ?string, method: ?string, key: ?string, path: ?string, methods: list<string>}> $methodAttributes resolved attribute names
     * @param list<array{name: string, handles: ?string, method: ?string, key: ?string, path: ?string, methods: list<string>}> $classAttributes
     * @param list<?string>                                                 $parameterTypes   single class type of each parameter
     */
    public function onMethod(string $class, string $methodId, string $methodName, array $methodAttributes, array $classAttributes, array $parameterTypes): void
    {
        $lowerName = strtolower($methodName);

        foreach ($methodAttributes as $attribute) {
            if ($this->isHandlerAttribute($attribute['name'])) {
                $key = preg_match(self::ROUTED_ATTRIBUTE, $this->shortName($attribute['name'])) === 1 ? $attribute['key'] : null;
                $this->addHandler($class, $methodId, $attribute['handles'] ?? $parameterTypes[0] ?? null, HandlerFact::ATTRIBUTE, $key);

                return;
            }
        }

        foreach ($classAttributes as $attribute) {
            $handlerMethod = strtolower($attribute['method'] ?? '');
            $isHandlerMethod = $handlerMethod === '' ? \in_array($lowerName, self::HANDLER_METHODS, true) : $handlerMethod === $lowerName;
            if ($isHandlerMethod && $this->isHandlerAttribute($attribute['name'])) {
                $this->addHandler($class, $methodId, $attribute['handles'] ?? $parameterTypes[0] ?? null, HandlerFact::ATTRIBUTE);

                return;
            }
        }

        if (!\in_array($lowerName, self::HANDLER_METHODS, true) || \count($parameterTypes) > 1) {
            return;
        }

        if (($parameterTypes[0] ?? null) !== null) {
            $this->addHandler($class, $methodId, $parameterTypes[0], HandlerFact::SHAPE);

            return;
        }

        // CreateCourseCommandHandler::__invoke($command) handles CreateCourseCommand, if that class exists.
        if (str_ends_with($class, 'Handler') && \strlen($class) > \strlen('Handler')) {
            $this->addHandler($class, $methodId, substr($class, 0, -\strlen('Handler')), HandlerFact::NAME);
        }
    }

    /**
     * A handler the configuration declares: an event subscriber's getSubscribedEvents(), for a message class or a
     * named event.
     */
    public function onConfiguredHandler(string $class, string $methodId, ?string $message, ?string $routingKey): void
    {
        $this->handlers[] = new HandlerFact($class, $methodId, $message, HandlerFact::CONFIG, $routingKey);
    }

    /**
     * A listener wired by a call: addListener('order.paid', [$this, 'onPaid']), Event::listen(OrderShipped::class,
     * Notify::class), add_action('init', 'register_types').
     *
     * @param string  $handler  class holding the listener, or the function listening
     * @param ?string $methodId the listening method or function, null for a class whose handle()/__invoke() listens
     */
    public function onRegisteredListener(string $handler, ?string $methodId, ?string $message, ?string $routingKey): void
    {
        if ($message !== null || $routingKey !== null) {
            $this->handlers[] = new HandlerFact($handler, $methodId, $message, HandlerFact::REGISTRATION, $routingKey);
        }
    }

    /**
     * Laravel's EventServiceProvider::$listen: event class => listener classes.
     *
     * @param array<string, list<string>> $listeners
     */
    public function onListenMap(array $listeners): void
    {
        foreach ($listeners as $message => $classes) {
            foreach ($classes as $listener) {
                $this->handlers[] = new HandlerFact($listener, null, $message, HandlerFact::CONFIG);
            }
        }
    }

    /**
     * Lowercase names of the Laravel helper functions that send a message.
     */
    public const SEND_FUNCTIONS = ['event', 'dispatch', 'dispatch_sync', 'broadcast', ...self::HOOK_SENDS];

    /**
     * WordPress hooks: functions running the listeners of a named hook, the hook name first.
     */
    public const HOOK_SENDS = ['do_action', 'do_action_ref_array', 'do_action_deprecated', 'apply_filters', 'apply_filters_ref_array', 'apply_filters_deprecated'];

    public function onCall(PendingDispatch $call): void
    {
        if (\in_array(strtolower($call->method), $call->isFunction ? self::SEND_FUNCTIONS : self::SEND_METHODS, true)) {
            $this->dispatches[] = $call;
        }
    }

    /**
     * @return list<HandlerFact>
     */
    public function handlers(): array
    {
        return $this->handlers;
    }

    /**
     * @return list<PendingDispatch>
     */
    public function dispatches(): array
    {
        return $this->dispatches;
    }

    private function isHandlerAttribute(string $name): bool
    {
        return preg_match(self::HANDLER_ATTRIBUTE, $this->shortName($name)) === 1;
    }

    private function shortName(string $name): string
    {
        $position = strrpos($name, '\\');

        return $position === false ? $name : substr($name, $position + 1);
    }

    private function addHandler(string $class, string $methodId, ?string $message, string $evidence, ?string $routingKey = null): void
    {
        if ($message !== null || $routingKey !== null) {
            $this->handlers[] = new HandlerFact($class, $methodId, $message, $evidence, $routingKey);
        }
    }
}
