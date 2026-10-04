<?php

namespace App\Validation;

final class Notification
{
    private array $violations = [];

    public function addContextViolation(string $message): void
    {
        $this->violations[] = $message;
    }
}
