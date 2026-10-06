<?php

namespace App\Validation;

final class Notification
{
    private array $violations = [];

    public function add(string $message): void
    {
        $this->violations[] = $message;
    }
}
