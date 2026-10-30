<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

use PhpGraph\Extractor\ConfigurationHelper;
use PhpGraph\Extractor\ConfigurationHelpers;
use PhpGraph\Extractor\DefinitionKind;
use PhpGraph\Extractor\PendingHelperCall;
use PhpGraph\Extractor\ServiceDefinitionFact;
use PhpGraph\Extractor\ServiceTagFact;
use PhpGraph\Extractor\Template;
use PhpGraph\Extractor\TemplateKind;

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

    /** @var array<string, array{helper: ConfigurationHelper, file: string}> lowercase helper id => helper */
    private array $helpers = [];

    /** @var list<array{call: PendingHelperCall, file: string, service: string}> */
    private array $calls = [];

    /** @var array{definitions: array<string, array<string, string>>, tags: array<string, list<array{id: ?string, instanceof: ?string, name: string, attributes: array<string, string>}>>, arguments: array<string, list<array{id: string, tag: ?string, service: ?string, locator?: bool}>>} */
    private array $result = ['definitions' => [], 'tags' => [], 'arguments' => []];

    /**
     * @param \Closure(string, string): ?string $constant value of `Class::NAME` written in a file
     */
    public function __construct(private readonly \Closure $constant)
    {
    }

    public function addHelper(string $id, ConfigurationHelper $helper, string $file): void
    {
        $this->helpers[strtolower($id)] = ['helper' => $helper, 'file' => $file];
    }

    public function addCall(PendingHelperCall $call, string $file, string $service): void
    {
        $this->calls[] = ['call' => $call, 'file' => $file, 'service' => $service];
    }

    /**
     * @return array{definitions: array<string, array<string, string>>, tags: array<string, list<array{id: ?string, instanceof: ?string, name: string, attributes: array<string, string>}>>, arguments: array<string, list<array{id: string, tag: ?string, service: ?string, locator?: bool}>>}
     */
    public function evaluate(): array
    {
        foreach ($this->calls as ['call' => $call, 'file' => $file, 'service' => $service]) {
            // The calls whose arguments do not depend on the parameters of a caller: the others run with their caller.
            if (!isset($this->helpers[strtolower($call->callee)]) || $call->usesParameters()) {
                continue;
            }
            $this->run($call->callee, $this->bind($call, [], $file), $service, 0);
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

        $value = fn (?Template $template): ?string => $template === null ? null : $this->value($template, $bindings, $helper['file']);
        foreach ($helper['helper']->facts as $fact) {
            if ($fact instanceof ServiceDefinitionFact) {
                $id = $value($fact->id);
                $class = $value($fact->class);
                if ($id !== null) {
                    $this->result['definitions'][$service][$id] ??= $class ?? $id;
                }
            } elseif ($fact instanceof ServiceTagFact) {
                $target = $value($fact->target);
                $name = $value($fact->name);
                if ($target === null || $name === null) {
                    continue;
                }
                $this->result['tags'][$service][] = match ($fact->kind) {
                    DefinitionKind::Instanceof => ['id' => null, 'instanceof' => ltrim($target, '\\'), 'name' => $name, 'attributes' => []],
                    DefinitionKind::Load => ['id' => rtrim(ltrim($target, '\\'), '\\') . '\\', 'instanceof' => null, 'name' => $name, 'attributes' => []],
                    DefinitionKind::Set => ['id' => $target, 'instanceof' => null, 'name' => $name, 'attributes' => []],
                };
            } else {
                $id = $value($fact->id);
                $tag = $value($fact->tag);
                $target = $value($fact->service);
                if ($id !== null && ($tag !== null || $target !== null)) {
                    $this->result['arguments'][$service][] = ['id' => $id, 'tag' => $tag, 'service' => $target, 'locator' => $fact->locator];
                }
            }
        }

        foreach ($this->calls as ['call' => $call]) {
            if ($call->caller !== null && strtolower($call->caller) === strtolower($callee) && $call->usesParameters()) {
                $this->run($call->callee, $this->bind($call, $bindings, $helper['file']), $service, $depth + 1);
            }
        }
    }

    /**
     * The values of a call's arguments, by the callee's parameter names: positional, then named.
     *
     * @param array<string, ?string> $bindings the caller's
     *
     * @return array<string, ?string>
     */
    private function bind(PendingHelperCall $call, array $bindings, string $file): array
    {
        $params = $this->helpers[strtolower($call->callee)]['helper']->params ?? [];
        $bound = [];
        foreach ($call->arguments as $position => $argument) {
            $param = $argument->name ?? ($params[$position] ?? null);
            if ($param !== null) {
                $bound[$param] = $argument->value === null ? null : $this->value($argument->value, $bindings, $file);
            }
        }

        return $bound;
    }

    /**
     * @param array<string, ?string> $bindings
     */
    private function value(Template $template, array $bindings, string $file): ?string
    {
        return match ($template->kind) {
            TemplateKind::Literal => $template->value,
            TemplateKind::Parameter => $bindings[$template->value] ?? null,
            TemplateKind::Directory => \dirname($file),
            TemplateKind::Constant => ($this->constant)($template->value, $file),
            TemplateKind::Concat => $this->concat($template->parts, $bindings, $file),
            TemplateKind::Call => $this->call($template->value, $template->parts, $bindings, $file),
            // An array is the argument of a function only.
            TemplateKind::List => null,
        };
    }

    /**
     * @param list<Template>         $parts
     * @param array<string, ?string> $bindings
     */
    private function concat(array $parts, array $bindings, string $file): ?string
    {
        $text = '';
        foreach ($parts as $part) {
            $value = $this->value($part, $bindings, $file);
            if ($value === null) {
                return null;
            }
            $text .= $value;
        }

        return $text;
    }

    /**
     * @param list<Template>         $arguments
     * @param array<string, ?string> $bindings
     */
    private function call(string $function, array $arguments, array $bindings, string $file): ?string
    {
        if (!\in_array($function, ConfigurationHelpers::FUNCTIONS, true)) {
            return null;
        }

        $values = [];
        foreach ($arguments as $argument) {
            if ($argument->kind === TemplateKind::List) {
                $list = [];
                foreach ($argument->parts as $item) {
                    $list[] = $this->value($item, $bindings, $file);
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
            return $this->apply($function, $values);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param list<string|list<string>> $values
     */
    private function apply(string $function, array $values): ?string
    {
        if ($function === 'str_replace') {
            if (\count($values) !== 3) {
                return null;
            }
            $subject = \is_array($values[2]) ? implode('', $values[2]) : $values[2];

            return str_replace($values[0], $values[1], $subject);
        }
        $allStrings = array_filter($values, 'is_string') === $values;
        if ($function === 'sprintf') {
            return isset($values[0]) && $allStrings ? \sprintf(...$values) : null;
        }
        if ($function === 'implode') {
            return \count($values) === 2 && \is_string($values[0]) && \is_array($values[1]) ? implode($values[0], $values[1]) : null;
        }
        if (!isset($values[0]) || !\is_string($values[0]) || !$allStrings) {
            return null;
        }

        return match ($function) {
            'trim' => trim($values[0], ...\array_slice($values, 1, 1)),
            'ltrim' => ltrim($values[0], ...\array_slice($values, 1, 1)),
            'rtrim' => rtrim($values[0], ...\array_slice($values, 1, 1)),
            'strtolower' => strtolower($values[0]),
            'strtoupper' => strtoupper($values[0]),
            'ucfirst' => ucfirst($values[0]),
            'lcfirst' => lcfirst($values[0]),
            'dirname' => \dirname($values[0]),
            default => basename($values[0]),
        };
    }
}
