<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

final readonly class PendingCall
{
    public function __construct(
        public string $source,
        public ?TypeExpr $receiver,
        public string $method,
        public bool $referenceOnMiss,
    ) {
    }
}
