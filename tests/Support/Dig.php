<?php

declare(strict_types=1);

namespace PhpGraph\Tests\Support;

/**
 * Reads into decoded JSON and MCP responses, typed: `Dig::text($response, 'result', 'content', 0, 'text')`.
 */
final class Dig
{
    public static function at(mixed $value, int|string ...$path): mixed
    {
        foreach ($path as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    public static function text(mixed $value, int|string ...$path): string
    {
        $found = self::at($value, ...$path);

        return \is_scalar($found) ? (string) $found : '';
    }

    /**
     * @return array<mixed>
     */
    public static function list(mixed $value, int|string ...$path): array
    {
        $found = self::at($value, ...$path);

        return \is_array($found) ? $found : [];
    }
}
