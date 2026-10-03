<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\MemoryLimit;
use PHPUnit\Framework\TestCase;

final class MemoryLimitTest extends TestCase
{
    public function testUnlimitedUnlessAValidLimitIsConfigured(): void
    {
        self::assertSame('-1', MemoryLimit::value(false));
        self::assertSame('-1', MemoryLimit::value(''));
        self::assertSame('2G', MemoryLimit::value(' 2G '));
        self::assertSame('512m', MemoryLimit::value('512m'));
        self::assertSame('-1', MemoryLimit::value('lots'), 'an invalid value does not lower the limit');
    }
}
