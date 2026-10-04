<?php

namespace App\Validation;

final class ViolationPrinter
{
    public function print(Notification $notification): string
    {
        return implode(',', array_map(static fn (Violation $violation): string => $violation->code, $notification->all()));
    }
}
