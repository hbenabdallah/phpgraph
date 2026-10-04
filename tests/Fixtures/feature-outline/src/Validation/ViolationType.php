<?php

namespace App\Validation;

enum ViolationType: string
{
    case Context = 'context';
    case Invariant = 'invariant';
}
