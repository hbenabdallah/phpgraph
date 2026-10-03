<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

use PhpGraph\Extractor\HandlerFact;
use PhpGraph\Graph\Graph;

/**
 * Handlers the container configuration declares with a tag, recognised by its name whatever the framework or the
 * project: `messenger.message_handler`, `app.command_handler`, `codely.domain_event_subscriber` handle a message;
 * `kernel.event_listener` listens to an event, a class or a name. A tag set through `_instanceof` makes a handler of
 * every class implementing the type.
 *
 * Symfony's kernel.event_subscriber is read from getSubscribedEvents() in the code instead, and Doctrine's tags are
 * entity lifecycle events, not messages.
 */
final class TaggedHandlers
{
    private const MESSAGE_HANDLER = '/(^|[._])(message|command|query|event|domain_event)_?(handler|subscriber)s?$/i';

    private const EVENT_LISTENER = '/(^|[._])event_listener$/i';

    private const IGNORED = '/^(kernel|doctrine)\./i';

    /**
     * @param array<string, string> $parameterTypes method id => class of its first parameter
     */
    public function __construct(
        private readonly Graph $graph,
        private readonly NameCanonicalizer $names,
        private readonly TypeResolver $types,
        private readonly array $parameterTypes,
    ) {
    }

    /**
     * @return list<array{HandlerFact, bool}> with whether the handler is test code
     */
    public function facts(ContainerServices $container): array
    {
        $facts = [];
        $tagged = new TaggedClasses($this->graph, $this->names, $this->types, $container);
        foreach ($container->tags() as $tag) {
            $listener = preg_match(self::EVENT_LISTENER, $tag['name']) === 1 && !str_starts_with($tag['name'], 'doctrine.');
            $handler = preg_match(self::MESSAGE_HANDLER, $tag['name']) === 1 && preg_match(self::IGNORED, $tag['name']) !== 1;
            if (!$listener && !$handler) {
                continue;
            }

            foreach ($tagged->of($tag) as $class) {
                $fact = $listener ? $this->listener($class, $tag['attributes'], $tag['service']) : $this->handler($class, $tag['attributes'], $tag['service']);
                if ($fact !== null) {
                    $file = $this->graph->node($class)?->file;
                    $facts[] = [$fact, $file !== null && TestFiles::isTest($file)];
                }
            }
        }

        return $facts;
    }

    /**
     * @param array<string, string> $attributes
     */
    private function handler(string $class, array $attributes, string $service): ?HandlerFact
    {
        $method = $this->types->findMethod($class, strtolower($attributes['method'] ?? '__invoke'));
        if ($method === null) {
            return null;
        }

        $message = isset($attributes['handles']) ? $this->names->canonical($attributes['handles'], $service) : ($this->parameterTypes[$method] ?? null);

        return $message === null ? null : new HandlerFact($class, $method, $message, HandlerFact::CONFIG);
    }

    /**
     * A listener of an event class (`event: App\Event\OrderPlaced`) or of a named event (`event: order.placed`). Without
     * a method attribute, Symfony calls __invoke, or else on + the camel-cased event name.
     *
     * @param array<string, string> $attributes
     */
    private function listener(string $class, array $attributes, string $service): ?HandlerFact
    {
        $event = $attributes['event'] ?? null;
        if ($event === null || $event === '') {
            return null;
        }

        $name = $attributes['method'] ?? null;
        if ($name === null) {
            $name = $this->types->findMethod($class, '__invoke') !== null
                ? '__invoke'
                : 'on' . str_replace(' ', '', ucwords(str_replace(['.', '_', '\\'], ' ', $event)));
        }
        $method = $this->types->findMethod($class, strtolower($name));
        if ($method === null) {
            return null;
        }

        return str_contains($event, '\\')
            ? new HandlerFact($class, $method, $this->names->canonical($event, $service), HandlerFact::CONFIG)
            : new HandlerFact($class, $method, null, HandlerFact::CONFIG, $event);
    }
}
