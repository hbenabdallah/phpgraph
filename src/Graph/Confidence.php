<?php

declare(strict_types=1);

namespace PhpGraph\Graph;

enum Confidence: string
{
    case Extracted = 'EXTRACTED';
    case Inferred = 'INFERRED';
    case Ambiguous = 'AMBIGUOUS';
}
