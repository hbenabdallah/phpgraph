<?php

declare(strict_types=1);

namespace PhpGraph;

/**
 * Values read at the edges, typed: console input, graph.json, MCP arguments. What is not of the expected type gives
 * the empty value, or null.
 */
final class Values
{
    public static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    public static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    public static function optionalText(mixed $value): ?string
    {
        return $value === null ? null : self::text($value);
    }

    public static function optionalNumber(mixed $value): ?int
    {
        return $value === null ? null : self::number($value);
    }

    /**
     * @return list<array<mixed>>
     */
    public static function records(mixed $value): array
    {
        $records = [];
        foreach (\is_array($value) ? $value : [] as $record) {
            if (\is_array($record)) {
                $records[] = $record;
            }
        }

        return $records;
    }
}
