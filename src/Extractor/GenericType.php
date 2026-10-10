<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

/**
 * A type with its arguments, written as a string: `Collection<?,App\Item>`, `ScalarNodeDefinition<static>`.
 *
 * A name is a class, `array` (any array or iterable, its last argument the elements), `static` (the type of the
 * receiver), `@T` (a template parameter of the declaring class) or `?` (unknown: a scalar, a union, a template of the
 * method). Strings rather than objects: they are stored, hashed and compared like the plain class names they extend.
 */
final class GenericType
{
    public const UNKNOWN = '?';

    public const ARRAY = 'array';

    /**
     * PHP's own iterables, global whatever the namespace they are written in: their last argument is their elements,
     * except for Generator<TKey, TValue, TSend, TReturn>.
     */
    public const PHP_ITERABLES = ['Traversable', 'Iterator', 'IteratorAggregate', 'Generator', 'ArrayIterator', 'ArrayObject'];

    /**
     * @return array{string, list<string>} the base name and the arguments
     */
    public static function parse(string $type): array
    {
        $open = strpos($type, '<');
        if ($open === false || !str_ends_with($type, '>')) {
            return [$type, []];
        }

        $arguments = [];
        $depth = 0;
        $start = $open + 1;
        for ($i = $start, $end = \strlen($type) - 1; $i < $end; ++$i) {
            if ($type[$i] === '<') {
                ++$depth;
            } elseif ($type[$i] === '>') {
                --$depth;
            } elseif ($type[$i] === ',' && $depth === 0) {
                $arguments[] = substr($type, $start, $i - $start);
                $start = $i + 1;
            }
        }
        $arguments[] = substr($type, $start, \strlen($type) - 1 - $start);

        return [substr($type, 0, $open), $arguments];
    }

    public static function base(string $type): string
    {
        $open = strpos($type, '<');

        return $open === false ? $type : substr($type, 0, $open);
    }

    /**
     * @param list<string> $arguments
     */
    public static function format(string $base, array $arguments): string
    {
        // Arguments all unknown say nothing more than the base.
        return array_filter($arguments, static fn (string $argument): bool => $argument !== self::UNKNOWN) === []
            ? $base
            : $base . '<' . implode(',', $arguments) . '>';
    }

    public static function isClass(string $name): bool
    {
        return $name !== self::UNKNOWN && $name !== self::ARRAY && $name !== TypeExpr::STATIC && !str_starts_with($name, '@');
    }

    /**
     * Every class name passed through $map: names written in a file, made project ids.
     *
     * @param \Closure(string): string $map
     */
    public static function mapNames(string $type, \Closure $map): string
    {
        [$base, $arguments] = self::parse($type);

        return self::format(
            self::isClass($base) ? $map($base) : $base,
            array_map(static fn (string $argument): string => self::mapNames($argument, $map), $arguments),
        );
    }

    /**
     * The type with its template parameters replaced by their bindings and `static` by the receiver. An unbound
     * parameter becomes unknown; null when the type itself is unknown.
     *
     * @param array<string, string> $bindings template name => type
     */
    public static function substitute(string $type, array $bindings, ?string $static): ?string
    {
        [$base, $arguments] = self::parse($type);
        if (str_starts_with($base, '@')) {
            $bound = $bindings[substr($base, 1)] ?? null;

            return $bound === null || $bound === self::UNKNOWN ? null : $bound;
        }
        if ($base === TypeExpr::STATIC) {
            return $static;
        }
        if ($base === self::UNKNOWN) {
            return null;
        }

        return self::format($base, array_map(
            static fn (string $argument): string => self::substitute($argument, $bindings, $static) ?? self::UNKNOWN,
            $arguments,
        ));
    }

    public static function isGeneric(string $type): bool
    {
        return str_contains($type, '<') || str_starts_with($type, '@');
    }

    /**
     * The elements of an array or of one of PHP's iterables: `array<App\Item>` gives `App\Item`; null for another type.
     */
    public static function element(string $type): ?string
    {
        [$base, $arguments] = self::parse($type);
        if ($base !== self::ARRAY && !\in_array($base, self::PHP_ITERABLES, true)) {
            return null;
        }
        $element = $base === 'Generator' && \count($arguments) > 1 ? $arguments[1] : end($arguments);

        return $element === false || $element === self::UNKNOWN ? null : $element;
    }
}
