<?php

namespace App\Validation;

final readonly class Outcome
{
    private function __construct(public Notification $notification, public ?object $result)
    {
    }

    public static function halted(Notification $notification): self
    {
        return new self($notification, null);
    }

    public static function completed(Notification $notification, ?object $result): self
    {
        return new self($notification, $result);
    }
}
