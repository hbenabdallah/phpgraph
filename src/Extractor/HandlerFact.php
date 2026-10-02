<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

/**
 * A method that handles a message, as seen in one file. The builder decides whether the message is a project class
 * and how confident the link is.
 */
final readonly class HandlerFact
{
    public const ATTRIBUTE = 'attribute';
    public const CONFIG = 'config';
    public const SHAPE = 'shape';
    public const NAME = 'name';

    /**
     * Wired by a call in the code: addListener(), Event::listen(), add_action().
     */
    public const REGISTRATION = 'registration';

    /**
     * @param string  $handlerClass class holding the handler
     * @param ?string $method       handler method id, null when only the class is known (Laravel $listen)
     * @param ?string $message      class name of the handled message
     * @param string  $evidence     ATTRIBUTE, CONFIG, REGISTRATION, SHAPE or NAME
     * @param ?string $routingKey   name of the channel it listens to (`#[CommandHandler('ticket.create')]`), or a
     *                              class constant holding it: `const:Class::NAME`
     */
    public function __construct(
        public string $handlerClass,
        public ?string $method,
        public ?string $message,
        public string $evidence,
        public ?string $routingKey = null,
    ) {
    }
}
