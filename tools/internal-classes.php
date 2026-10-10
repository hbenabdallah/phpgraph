<?php

declare(strict_types=1);

/*
 * Writes src/Vendor/internal-classes.php: the signatures of PHP's own classes (DateTime, ArrayObject, PDO...), read
 * by reflection once, so that call chains go through them the same way with any PHP, the standalone binary included.
 *
 *   bin/dev php tools/internal-classes.php
 *
 * Reflection has no generics: the templates of the iterables come from the table below, as PHPStan's stubs write them.
 */

const OUTPUT = __DIR__ . '/../src/Vendor/internal-classes.php';

/**
 * Extensions read: the language and the extensions most projects use. Others are left out, so that the file does not
 * depend on the PHP that generated it more than needed.
 */
const EXTENSIONS = ['Core', 'date', 'SPL', 'standard', 'json', 'pcre', 'Reflection', 'PDO', 'dom', 'SimpleXML', 'libxml', 'xmlreader', 'xmlwriter', 'random', 'fileinfo', 'zip', 'curl', 'mysqli', 'intl'];

/**
 * class => [templates, parent => arguments, method => returned GenericType].
 */
const GENERICS = [
    'Traversable' => [['TKey', 'TValue'], [], []],
    'Iterator' => [['TKey', 'TValue'], ['Traversable' => ['@TKey', '@TValue']], ['current' => '@TValue']],
    'IteratorAggregate' => [['TKey', 'TValue'], ['Traversable' => ['@TKey', '@TValue']], ['getIterator' => 'Traversable<@TKey,@TValue>']],
    'ArrayAccess' => [['TKey', 'TValue'], [], ['offsetGet' => '@TValue']],
    'ArrayIterator' => [['TKey', 'TValue'], ['Iterator' => ['@TKey', '@TValue'], 'ArrayAccess' => ['@TKey', '@TValue']], ['current' => '@TValue', 'offsetGet' => '@TValue']],
    'ArrayObject' => [['TKey', 'TValue'], ['IteratorAggregate' => ['@TKey', '@TValue'], 'ArrayAccess' => ['@TKey', '@TValue']], ['offsetGet' => '@TValue', 'getIterator' => 'ArrayIterator<@TKey,@TValue>']],
    'SplDoublyLinkedList' => [['TValue'], ['Iterator' => ['?', '@TValue'], 'ArrayAccess' => ['?', '@TValue']], ['current' => '@TValue', 'offsetGet' => '@TValue', 'top' => '@TValue', 'bottom' => '@TValue', 'pop' => '@TValue', 'shift' => '@TValue']],
    'SplQueue' => [['TValue'], ['SplDoublyLinkedList' => ['@TValue']], ['dequeue' => '@TValue']],
    'SplStack' => [['TValue'], ['SplDoublyLinkedList' => ['@TValue']], []],
    'SplObjectStorage' => [['TObject', 'TData'], ['Iterator' => ['?', '@TObject'], 'ArrayAccess' => ['@TObject', '@TData']], ['current' => '@TObject', 'offsetGet' => '@TData']],
    'SplFixedArray' => [['TValue'], ['IteratorAggregate' => ['?', '@TValue'], 'ArrayAccess' => ['?', '@TValue']], ['offsetGet' => '@TValue']],
    'SplPriorityQueue' => [['TPriority', 'TValue'], ['Iterator' => ['?', '@TValue']], ['current' => '@TValue', 'extract' => '@TValue', 'top' => '@TValue']],
    'SplHeap' => [['TValue'], ['Iterator' => ['?', '@TValue']], ['current' => '@TValue', 'extract' => '@TValue', 'top' => '@TValue']],
    'SplMinHeap' => [['TValue'], ['SplHeap' => ['@TValue']], []],
    'SplMaxHeap' => [['TValue'], ['SplHeap' => ['@TValue']], []],
    'Generator' => [['TKey', 'TValue', 'TSend', 'TReturn'], ['Iterator' => ['@TKey', '@TValue']], ['current' => '@TValue', 'getReturn' => '@TReturn']],
];

function returned(ReflectionMethod $method): ?string
{
    $type = $method->getReturnType() ?? $method->getTentativeReturnType();
    $names = [];
    foreach ($type instanceof ReflectionUnionType ? $type->getTypes() : ($type === null ? [] : [$type]) as $part) {
        if ($part instanceof ReflectionNamedType && !in_array(strtolower($part->getName()), ['null', 'false', 'void', 'never'], true)) {
            $names[] = $part->getName();
        }
    }
    if (count($names) !== 1 || ($type instanceof ReflectionNamedType && $type->isBuiltin() && !in_array($type->getName(), ['static', 'self'], true))) {
        return null;
    }
    $name = $names[0];

    return match (strtolower($name)) {
        'static' => 'static',
        'self' => $method->getDeclaringClass()->getName(),
        'array', 'string', 'int', 'float', 'bool', 'true', 'mixed', 'object', 'iterable', 'callable' => null,
        default => $name,
    };
}

$classes = [];
foreach (EXTENSIONS as $extension) {
    if (!extension_loaded($extension)) {
        fwrite(STDERR, "Extension not loaded, left out: {$extension}\n");
        continue;
    }
    foreach ((new ReflectionExtension($extension))->getClasses() as $class) {
        $parents = $class->getParentClass() === false ? [] : [$class->getParentClass()->getName()];
        foreach ($class->getInterfaceNames() as $interface) {
            // Only the interfaces it declares itself, as the project's classes are read.
            if ($class->getParentClass() === false || !$class->getParentClass()->implementsInterface($interface)) {
                $parents[] = $interface;
            }
        }
        $methods = $returns = [];
        foreach ($class->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }
            $methods[strtolower($method->getName())] = $method->getName();
            $returned = returned($method);
            if ($returned !== null) {
                $returns[strtolower($method->getName())] = $returned;
            }
        }
        [$templates, $parentArguments, $genericReturns] = GENERICS[$class->getName()] ?? [[], [], []];
        $genericReturns = array_change_key_case($genericReturns);
        $classes[strtolower($class->getName())] = array_filter([
            'name' => $class->getName(),
            'parents' => array_values(array_unique([...$parents, ...array_keys($parentArguments)])),
            'methods' => $methods,
            'returns' => $returns,
            'templates' => $templates,
            'parentArguments' => $parentArguments,
            'genericReturns' => $genericReturns,
        ], static fn (array|string $value): bool => $value !== []);
    }
}
ksort($classes);

$code = "<?php\n\n// Generated by tools/internal-classes.php from PHP " . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . ": do not edit.\n\nreturn " . var_export($classes, true) . ";\n";
file_put_contents(OUTPUT, $code);
printf("%d classes, %.0f KB: %s\n", count($classes), strlen($code) / 1024, realpath(OUTPUT));
