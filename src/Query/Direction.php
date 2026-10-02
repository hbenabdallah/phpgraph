<?php

declare(strict_types=1);

namespace PhpGraph\Query;

enum Direction: string
{
    case In = 'in';
    case Out = 'out';
    case Both = 'both';
}
