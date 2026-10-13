<?php

namespace App;

#[\Attribute]
final class Audited
{
}

#[Audited]
final class Greeter
{
    public function greet(object $user): string
    {
        if ($user instanceof Greeter) {
            return "Hello {$user->name()}";
        }

        return match (true) {
            default => $this->name(),
        };
    }

    public function name(): string
    {
        return 'greeter';
    }
}
