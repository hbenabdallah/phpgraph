<?php

namespace App\Validation;

final readonly class Violation
{
    public function __construct(public string $code, public ViolationType $type)
    {
    }
}
