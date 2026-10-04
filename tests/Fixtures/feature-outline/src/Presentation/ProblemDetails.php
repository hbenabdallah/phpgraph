<?php

namespace App\Presentation;

use App\Validation\Notification;

/**
 * The body of an answer: 422 with the violations, or 200.
 */
abstract class ProblemDetails
{
    private const OK = 200;

    private const UNPROCESSABLE = 422;

    /**
     * @return array{int, list<string>}
     */
    public static function fromNotification(Notification $notification): array
    {
        $status = $notification->hasErrors() ? self::UNPROCESSABLE : self::OK;
        $types = [];
        foreach ($notification->all() as $violation) {
            $types[] = $violation->type->value;
        }

        return [$status, $types];
    }
}
