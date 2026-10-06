<?php

namespace App\Estimate\Rules;

use App\Estimate\Line;
use App\Shared\LineChecker;
use App\Validation\ContextRuleInterface;
use App\Validation\Notification;

/**
 * Runs on the lines of a query, met by the validator's walk.
 */
final class LineRule implements ContextRuleInterface
{
    public function __construct(private LineChecker $checker)
    {
    }

    public function supports(object $input): bool
    {
        return $input instanceof Line;
    }

    public function apply(object $input, Notification $notification): void
    {
        $this->checker->check($input);
    }
}
