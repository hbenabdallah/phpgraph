<?php

declare(strict_types=1);

namespace PhpGraph;

/**
 * The memory limit of every phpgraph command, set once at startup. A large project needs several hundred MB: about
 * 500 MB to build Akeneo (8,400 files), and decoding a 45 MB graph.json takes more than PHP's default 128 MB. The
 * standalone executable takes no -d option: PHPGRAPH_MEMORY_LIMIT sets the limit (512M, 2G), unlimited by default.
 */
final class MemoryLimit
{
    public const VARIABLE = 'PHPGRAPH_MEMORY_LIMIT';

    public static function raise(): void
    {
        ini_set('memory_limit', self::value(getenv(self::VARIABLE)));
    }

    public static function value(string|false $configured): string
    {
        $configured = \is_string($configured) ? trim($configured) : '';

        return preg_match('/^(-1|\d+[KMG]?)$/i', $configured) === 1 ? $configured : '-1';
    }
}
