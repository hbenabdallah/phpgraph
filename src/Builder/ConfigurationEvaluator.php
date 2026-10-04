<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

use PhpGraph\Extractor\ConfigurationHelpers;

/**
 * Evaluates the service configuration written in helpers (see ConfigurationHelpers): each call whose arguments are
 * known, a literal, `__DIR__`, a constant or a pure string function of them, runs the helper's templates with them,
 * and the helpers it calls in turn. The result joins the definitions, tags and injections read elsewhere.
 *
 * Only string expressions are evaluated, never the project's code; the helper's conditions are ignored, so a fact is
 * kept even when a branch would skip it (a tag on a namespace with no class tags nothing).
 */
final class ConfigurationEvaluator
{
    private const MAX_DEPTH = 5;

    /** @var array<string, array{params: list<string>, facts: list<array<string, mixed>>, file: string}> lowercase helper id => helper */
    private array $helpers = [];

    /** @var list<array{caller: ?string, callee: string, args: list<array{?string, ?array<mixed>}>, file: string, service: string}> */
    private array $calls = [];

    /** @var array{definitions: array<string, array<string, string>>, tags: array<string, list<array{id: ?string, instanceof: ?string, name: string, attributes: array<string, string>}>>, arguments: array<string, list<array{id: string, tag: ?string, service: ?string, locator?: bool}>>} */
    private array $result = ['definitions' => [], 'tags' => [], 'arguments' => []];

    /**
     * @param \Closure(string, string): ?string $constant value of `Class::NAME` written in a file
     */
    public function __construct(private readonly \Closure $constant)
    {
    }

    /**
     * @param array{params: list<string>, facts: list<array<string, mixed>>} $helper
     */
    public function addHelper(string $id, array $helper, string $file): void
    {
        $this->helpers[strtolower($id)] = $helper + ['file' => $file];
    }

    /**
     * @param array{caller: ?string, callee: string, args: list<array{?string, ?array<mixed>}>, line: int} $call
     */
    public function addCall(array $call, string $file, string $service): void
    {
        $this->calls[] = ['caller' => $call['caller'], 'callee' => $call['callee'], 'args' => $call['args'], 'file' => $file, 'service' => $service];
    }

    /**
     * @return array{definitions: array<string, array<string, string>>, tags: array<string, list<array{id: ?string, instanceof: ?string, name: string, attributes: array<string, string>}>>, arguments: array<string, list<array{id: string, tag: ?string, service: ?string, locator?: bool}>>}
     */
    public function evaluate(): array
    {
        foreach ($this->calls as $call) {
            // The calls whose arguments do not depend on the parameters of a caller: the others run with their caller.
            if (!isset($this->helpers[strtolower($call['callee'])]) || $this->usesParameters($call['args'])) {
                continue;
            }
            $this->run($call['callee'], $this->bind($call['callee'], $call['args'], [], $call['file']), $call['service'], 0);
        }

        return $this->result;
    }

    /**
     * @param array<string, ?string> $bindings parameter => value
     */
    private function run(string $callee, array $bindings, string $service, int $depth): void
    {
        $helper = $this->helpers[strtolower($callee)] ?? null;
        if ($helper === null || $depth > self::MAX_DEPTH) {
            return;
        }

        $value = fn (?array $template): ?string => $template === null ? null : $this->value($template, $bindings, $helper['file']);
        foreach ($helper['facts'] as $fact) {
            if ($fact['kind'] === 'set') {
                $id = $value(\is_array($fact['id']) ? $fact['id'] : null);
                $class = $value(\is_array($fact['class'] ?? null) ? $fact['class'] : null);
                if ($id !== null) {
                    $this->result['definitions'][$service][$id] ??= $class ?? $id;
                }
            } elseif ($fact['kind'] === 'tag' && \is_array($fact['definition'] ?? null)) {
                [$kind, $template] = $fact['definition'];
                $target = $value(\is_array($template) ? $template : null);
                $name = $value(\is_array($fact['name']) ? $fact['name'] : null);
                if ($target === null || $name === null) {
                    continue;
                }
                $this->result['tags'][$service][] = match ($kind) {
                    'instanceof' => ['id' => null, 'instanceof' => ltrim($target, '\\'), 'name' => $name, 'attributes' => []],
                    'load' => ['id' => rtrim(ltrim($target, '\\'), '\\') . '\\', 'instanceof' => null, 'name' => $name, 'attributes' => []],
                    default => ['id' => $target, 'instanceof' => null, 'name' => $name, 'attributes' => []],
                };
            } elseif ($fact['kind'] === 'argument') {
                $id = $value(\is_array($fact['id']) ? $fact['id'] : null);
                $tag = $value(\is_array($fact['tag'] ?? null) ? $fact['tag'] : null);
                $target = $value(\is_array($fact['service'] ?? null) ? $fact['service'] : null);
                if ($id !== null && ($tag !== null || $target !== null)) {
                    $this->result['arguments'][$service][] = ['id' => $id, 'tag' => $tag, 'service' => $target, 'locator' => ($fact['locator'] ?? false) === true];
                }
            }
        }

        foreach ($this->calls as $call) {
            if ($call['caller'] !== null && strtolower($call['caller']) === strtolower($callee) && $this->usesParameters($call['args'])) {
                $this->run($call['callee'], $this->bind($call['callee'], $call['args'], $bindings, $helper['file']), $service, $depth + 1);
            }
        }
    }

    /**
     * The values of a call's arguments, by the callee's parameter names: positional, then named.
     *
     * @param list<array{?string, ?array<mixed>}> $args
     * @param array<string, ?string>              $bindings the caller's
     *
     * @return array<string, ?string>
     */
    private function bind(string $callee, array $args, array $bindings, string $file): array
    {
        $params = $this->helpers[strtolower($callee)]['params'] ?? [];
        $bound = [];
        foreach ($args as $position => [$name, $template]) {
            $param = $name ?? ($params[$position] ?? null);
            if ($param !== null) {
                $bound[$param] = $template === null ? null : $this->value($template, $bindings, $file);
            }
        }

        return $bound;
    }

    /**
     * @param list<array{?string, ?array<mixed>}> $args
     */
    private function usesParameters(array $args): bool
    {
        foreach ($args as [, $template]) {
            if ($template !== null && str_contains(serialize($template), 's:5:"param"')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed>           $template
     * @param array<string, ?string> $bindings
     */
    private function value(array $template, array $bindings, string $file): ?string
    {
        $parts = $template[1] ?? null;

        return match ($template[0] ?? null) {
            'lit' => \is_string($parts) ? $parts : null,
            'param' => \is_string($parts) ? $bindings[$parts] ?? null : null,
            'dir' => \dirname($file),
            'const' => \is_string($parts) ? ($this->constant)($parts, $file) : null,
            'concat' => \is_array($parts) ? $this->concat($parts, $bindings, $file) : null,
            'call' => \is_string($parts) && \is_array($template[2] ?? null) ? $this->call($parts, $template[2], $bindings, $file) : null,
            default => null,
        };
    }

    /**
     * @param array<mixed>           $parts
     * @param array<string, ?string> $bindings
     */
    private function concat(array $parts, array $bindings, string $file): ?string
    {
        $text = '';
        foreach ($parts as $part) {
            $value = \is_array($part) ? $this->value($part, $bindings, $file) : null;
            if ($value === null) {
                return null;
            }
            $text .= $value;
        }

        return $text;
    }

    /**
     * @param array<mixed>           $arguments templates, or ['list', [...]] for an array
     * @param array<string, ?string> $bindings
     */
    private function call(string $function, array $arguments, array $bindings, string $file): ?string
    {
        if (!\in_array($function, ConfigurationHelpers::FUNCTIONS, true)) {
            return null;
        }

        $values = [];
        foreach ($arguments as $argument) {
            if (!\is_array($argument)) {
                return null;
            }
            if (($argument[0] ?? null) === 'list' && \is_array($argument[1] ?? null)) {
                $list = [];
                foreach ($argument[1] as $item) {
                    $list[] = \is_array($item) ? $this->value($item, $bindings, $file) : null;
                }
                if (\in_array(null, $list, true)) {
                    return null;
                }
                $values[] = $list;
                continue;
            }
            $value = $this->value($argument, $bindings, $file);
            if ($value === null) {
                return null;
            }
            $values[] = $value;
        }

        try {
            $result = match ($function) {
                'str_replace' => \count($values) === 3 ? str_replace($values[0], $values[1], \is_array($values[2]) ? implode('', $values[2]) : $values[2]) : null,
                'sprintf' => isset($values[0]) && array_filter($values, 'is_string') === $values ? \sprintf(...$values) : null,
                'implode' => \count($values) === 2 && \is_string($values[0]) && \is_array($values[1]) ? implode($values[0], $values[1]) : null,
                default => isset($values[0]) && \is_string($values[0]) && array_filter($values, 'is_string') === $values
                    ? match ($function) {
                        'trim' => trim($values[0], ...\array_slice($values, 1, 1)),
                        'ltrim' => ltrim($values[0], ...\array_slice($values, 1, 1)),
                        'rtrim' => rtrim($values[0], ...\array_slice($values, 1, 1)),
                        'strtolower' => strtolower($values[0]),
                        'strtoupper' => strtoupper($values[0]),
                        'ucfirst' => ucfirst($values[0]),
                        'lcfirst' => lcfirst($values[0]),
                        'dirname' => \dirname($values[0]),
                        default => basename($values[0]),
                    }
                : null,
            };
        } catch (\Throwable) {
            return null;
        }

        return \is_string($result) ? $result : null;
    }
}
