<?php

declare(strict_types=1);

namespace PhpGraph\Vendor;

/**
 * The signatures of PHP's own classes (DateTime, ArrayObject, PDO...), from a file generated once by
 * tools/internal-classes.php: the same with any PHP running phpgraph, the standalone binary included.
 */
final class InternalClasses
{
    /** @var array<string, array{name: string, parents?: list<string>, methods?: array<string, string>, returns?: array<string, string>, templates?: list<string>, parentArguments?: array<string, list<string>>, genericReturns?: array<string, string>}>|null */
    private static ?array $data = null;

    public static function signature(string $class): ?ClassSignature
    {
        if (self::$data === null) {
            /** @var array<string, array{name: string, parents?: list<string>, methods?: array<string, string>, returns?: array<string, string>, templates?: list<string>, parentArguments?: array<string, list<string>>, genericReturns?: array<string, string>}> $data */
            $data = require __DIR__ . '/internal-classes.php';
            self::$data = $data;
        }
        $class = self::$data[strtolower(ltrim($class, '\\'))] ?? null;
        if ($class === null) {
            return null;
        }

        $name = $class['name'];
        $id = static fn (string $method): string => $name . '::' . $method;
        $methods = array_map($id, $class['methods'] ?? []);
        $returns = [];
        foreach ($class['returns'] ?? [] as $method => $type) {
            $returns[$methods[$method] ?? $id($method)] = $type;
        }
        $genericReturns = [];
        foreach ($class['genericReturns'] ?? [] as $method => $type) {
            $genericReturns[$methods[$method] ?? $id($method)] = $type;
        }

        return new ClassSignature($name, $class['parents'] ?? [], $methods, $returns, [], $class['templates'] ?? [], $class['parentArguments'] ?? [], $genericReturns);
    }
}
