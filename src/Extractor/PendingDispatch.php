<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

/**
 * A call that may send a message to a bus: `$bus->dispatch(new PlaceOrder())`, `$this->recordThat($event)`,
 * `event(new OrderShipped())`, `ProcessPodcast::dispatch()`. Resolved by the builder once types are known.
 */
final readonly class PendingDispatch
{
    /**
     * @param string    $source         calling method or function id
     * @param string    $method         called method or function name
     * @param ?TypeExpr $receiver       type of the object the method is called on
     * @param bool      $receiverIsThis `$this->method(...)`
     * @param ?string   $staticClass    class of a static call
     * @param bool      $isFunction     call to a function: `event(...)`, `dispatch(...)`
     * @param ?TypeExpr $message        type of the first argument, null when there is none or it is not an object
     * @param bool      $hasArgument    whether the call has a first argument at all
     * @param ?string   $routingKey     channel named by the call (`sendWithRouting('ticket.create', ...)`), or a class
     *                                  constant holding it: `const:Class::NAME`
     */
    public function __construct(
        public string $source,
        public string $method,
        public ?TypeExpr $receiver,
        public bool $receiverIsThis,
        public ?string $staticClass,
        public bool $isFunction,
        public ?TypeExpr $message,
        public bool $hasArgument,
        public ?string $routingKey = null,
    ) {
    }
}
