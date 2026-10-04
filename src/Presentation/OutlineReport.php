<?php

declare(strict_types=1);

namespace PhpGraph\Presentation;

use PhpGraph\Extractor\ClassFacts;
use PhpGraph\Graph\Relation;
use PhpGraph\Query\GraphQuery;
use PhpGraph\Query\Layers;
use PhpGraph\Query\Result\Outline;

/**
 * An outline as text, compact by default (`full` lifts the limits), or as JSON.
 */
final class OutlineReport
{
    private const METHODS = 5;

    private const LIST = 12;

    private const HINTS = 12;

    private const INSIDE = 7;

    private const ENTRIES = 6;

    private string $prefix = '';

    public function __construct(
        private readonly GraphQuery $query,
        private readonly Outline $outline,
    ) {
    }

    public function render(string $format): string
    {
        return $format === 'json' ? $this->json() : $this->text($format === 'full');
    }

    private function text(bool $full): string
    {
        $outline = $this->outline;
        $graph = $this->query->graph();
        $files = array_filter(array_map(fn (string $class): ?string => $graph->node($class)?->file, $outline->core));
        $this->prefix = self::commonDirectory(array_values($files));
        $limit = static fn (array $items, int $count): array => $full ? $items : \array_slice($items, 0, $count);
        $more = static fn (array $all, array $shown): string => \count($all) > \count($shown) ? \sprintf(' (+%d)', \count($all) - \count($shown)) : '';

        $lines = [
            \sprintf(
                'Outline of "%s": %d classes, from %s; paths under %s. From the graph%s: read the bodies for the rest.',
                $outline->topic,
                \count($outline->core),
                implode(', ', array_map(fn (string $seed): string => $this->query->label($seed), $outline->seeds)),
                $this->prefix === '' ? 'the project' : $this->prefix,
                $outline->sourcesRead ? ' and the sources' : ' (sources not found: no signatures nor hints)',
            ),
        ];

        // At a glance: what an agent explaining the feature must not miss, before the details.
        $hints = $outline->hints;
        $glance = [];
        foreach ($hints as $index => [$class, $hint]) {
            if (str_contains($hint->text, ', else ') && \count($glance) < 3) {
                $glance[] = \sprintf('  Outcome: %s::%s L%d: %s', $this->query->label($class), $hint->method, $hint->line, $hint->text);
                unset($hints[$index]);
            }
        }
        if ($outline->unused !== []) {
            $glance[] = '  Nothing in the application uses: ' . implode(', ', array_map(
                fn (string $class, bool $tested): string => $this->query->label($class) . ($tested ? ' (tests only)' : ''),
                array_keys($outline->unused),
                $outline->unused,
            )) . '.';
        }
        if ($outline->uncovered !== []) {
            $glance[] = '  No test touches: ' . implode(', ', array_map(fn (string $class): string => $this->query->label($class), $outline->uncovered)) . '.';
        }
        if ($glance !== []) {
            $lines[] = '';
            $lines[] = 'At a glance:';
            array_push($lines, ...$glance);
        }
        $hints = array_values($hints);

        // Core, by namespace.
        $byNamespace = [];
        foreach ($outline->core as $class) {
            $byNamespace[substr($class, 0, (int) strrpos($class, '\\'))][] = $class;
        }
        $common = array_reduce(array_map('strval', array_keys($byNamespace)), static function (?string $common, string $namespace): string {
            if ($common === null) {
                return $namespace . '\\';
            }
            while ($common !== '' && !str_starts_with($namespace . '\\', $common)) {
                $common = substr($common, 0, (int) strrpos(rtrim($common, '\\'), '\\') + 1);
            }

            return $common;
        }) ?? '';
        $lines[] = '';
        $lines[] = 'Core, by namespace' . ($common === '' ? '' : ' (under ' . rtrim($common, '\\') . ')') . ':';
        foreach ($byNamespace as $namespace => $classes) {
            $layer = Layers::of(explode('\\', (string) $namespace));
            $lines[] = '  ' . (substr((string) $namespace, \strlen($common)) ?: (string) $namespace) . ($layer === null ? '' : ' [' . $layer['segment'] . ']');
            foreach ($classes as $class) {
                array_push($lines, ...$this->classLines($class, $outline->facts[$class] ?? null, $full));
            }
        }

        // Families.
        if ($outline->families !== []) {
            $lines[] = '';
            $all = [];
            foreach ($outline->families as $family) {
                array_push($all, ...array_map('strval', array_keys($family['directories'])));
            }
            $base = self::commonDirectory(array_map(static fn (string $directory): string => $directory . '/', $all));
            $lines[] = 'Families (application classes implementing or extending them' . ($base === '' ? '' : ', by module under ' . $base) . '):';
            $single = [];
            foreach ($outline->families as $family) {
                if ($family['members'] === 1 && $family['receivers'] === []) {
                    $single[] = $this->query->label($family['head']);
                    continue;
                }
                // By module: the first two folders below the common one (`SalesOrder/CustomerOrder 9`).
                $modules = [];
                foreach ($family['directories'] as $directory => $count) {
                    $relative = str_starts_with($directory . '/', $base) ? substr($directory . '/', \strlen($base), -1) : (string) $directory;
                    $module = implode('/', \array_slice(explode('/', $relative), 0, 2));
                    $modules[$module] = ($modules[$module] ?? 0) + $count;
                }
                arsort($modules);
                $shown = $limit($modules, \count($modules) > 3 ? 2 : 3);
                $lines[] = \sprintf(
                    '  %s: %s%s%s',
                    $this->query->label($family['head']),
                    $family['members'] === 0 ? '0 implementations' : $family['members'],
                    $modules === [] ? '' : ' in ' . implode(', ', array_map(static fn (string $module, int $count): string => $module . ' ' . $count, array_keys($shown), $shown))
                        . (\count($modules) > \count($shown) ? \sprintf(' +%d modules', \count($modules) - \count($shown)) : ''),
                    $family['receivers'] === [] ? '' : '; received by ' . implode(', ', array_map(fn (string $receiver): string => $this->query->label(explode(' ', $receiver, 2)[0]) . ' ' . (explode(' ', $receiver, 2)[1] ?? ''), $family['receivers'])),
                );
            }
            if ($single !== []) {
                $lines[] = '  One implementation each: ' . implode(', ', $single);
            }
        }

        // Flow.
        $lines[] = '';
        $lines[] = 'Flow:';
        if ($outline->routes !== []) {
            $lines[] = \sprintf('  Routes (%d), from their handler into the core:', \count($outline->routes));
            foreach ($limit($outline->routes, self::LIST) as $route) {
                $lines[] = '    ' . $this->query->label($route['route']) . ' → ' . $this->chain($route['chain']);
            }
        }
        if ($outline->entries !== []) {
            $lines[] = '  Into the core, from outside (callers):';
            foreach ($limit($outline->entries, self::ENTRIES) as $method => $callers) {
                $classes = array_unique(array_map(static fn (string $caller): string => explode('::', $caller)[0], $callers));
                $lines[] = \sprintf(
                    '    %s ← %s',
                    $this->query->label($method),
                    \count($classes) > 8 && !$full ? \sprintf('%d classes', \count($classes)) : $this->methodList($callers, $full ? 0 : 4),
                );
            }
            if (!$full && \count($outline->entries) > self::ENTRIES) {
                $lines[] = \sprintf('    +%d more: format full', \count($outline->entries) - self::ENTRIES);
            }
        }
        foreach ($outline->closures as $method => $sources) {
            $parts = [];
            foreach ($sources as $source => $targets) {
                $parts[] = $this->query->label($source) . ($full ? ' (→ ' . $this->methodList($targets, 0) . ')' : '');
            }
            $lines[] = \sprintf('  %s runs the closures written in %s', $this->query->label((string) $method), $full ? implode(', ', $parts) : $this->methodList(array_keys($sources), 5));
        }
        if ($outline->inside !== []) {
            $lines[] = '  Inside the core:';
            foreach ($limit($outline->inside, self::INSIDE) as $method => $callees) {
                $lines[] = \sprintf('    %s → %s', $this->query->label($method), $this->methodList($callees, $full ? 0 : 4));
            }
        }

        // Behaviour.
        if ($hints !== []) {
            $lines[] = '';
            $lines[] = 'Behaviour (read in the method bodies)' . ($hints === $outline->hints ? '' : ', besides the outcomes above') . ':';
            foreach ($limit($hints, self::HINTS) as [$class, $hint]) {
                $lines[] = \sprintf('  %s::%s L%d: %s', $this->query->label($class), $hint->method, $hint->line, $hint->text);
            }
            if (!$full && \count($hints) > self::HINTS) {
                $lines[] = \sprintf('  +%d more: format full', \count($hints) - self::HINTS);
            }
        }

        // Wiring.
        if ($outline->wiring !== []) {
            $lines[] = '';
            $lines[] = 'Wiring (container configuration):';
            $file = null;
            foreach ($outline->wiring as $call) {
                if ($call['call'] !== '') {
                    if ($call['file'] !== $file) {
                        $lines[] = '  ' . $call['file'];
                        $file = $call['file'];
                    }
                    $lines[] = \sprintf('    L%d %s', $call['line'], $call['call']);
                } else {
                    $lines[] = '  Services by id:';
                }
                foreach ($call['services'] as $service) {
                    $lines[] = \sprintf(
                        '      %s (%s) → %s',
                        $service['id'],
                        $this->query->label($service['class']),
                        implode(', ', array_map(fn (string $consumer): string => $this->query->label($consumer), $service['consumers'])),
                    );
                }
            }
        }

        // Users.
        $lines[] = '';
        $consumers = $outline->consumers;
        $shownConsumers = $limit($consumers, 8);
        $lines[] = \sprintf(
            'Used by %d files outside the core. Application%s: %s%s.',
            $outline->consumerFiles,
            ' (links)',
            implode(', ', array_map(fn (string $class, int $links): string => $this->query->label($class) . ' ' . $links, array_keys($shownConsumers), $shownConsumers)),
            $more($consumers, $shownConsumers),
        );
        if ($outline->tests !== []) {
            $tests = $outline->tests;
            $shownTests = $limit($tests, 4);
            $lines[] = \sprintf(
                'Tests touching the core: %d files in %d modules (%s%s); touching the most: %s.',
                array_sum($tests),
                \count($tests),
                implode(', ', array_map(static fn (string $module, int $count): string => $module . ' ' . $count, array_keys($shownTests), $shownTests)),
                $more($tests, $shownTests),
                implode(', ', $outline->topTests),
            );
        }
        return implode("\n", $lines);
    }

    /**
     * Methods grouped by class, `ContextRuleInterface::{supports,provides}()`, the first classes only unless $classes
     * is 0.
     *
     * @param array<string> $methods
     */
    private function methodList(array $methods, int $classes): string
    {
        $byClass = [];
        foreach (array_unique($methods) as $method) {
            [$class, $name] = explode('::', (string) $method, 2) + [1 => ''];
            $byClass[$class][] = $name;
        }
        $parts = [];
        foreach ($byClass as $class => $names) {
            $label = $this->query->label((string) $class);
            $parts[] = \count($names) === 1 ? $label . '::' . $names[0] . '()' : $label . '::{' . implode(',', $names) . '}()';
        }
        $shown = $classes === 0 ? $parts : \array_slice($parts, 0, $classes);

        return implode(', ', $shown) . (\count($parts) > \count($shown) ? \sprintf(' (+%d)', \count($parts) - \count($shown)) : '');
    }

    /**
     * The signatures of the interfaces of the core a class implements: not repeated under it.
     *
     * @return array<string, true>
     */
    private function inherited(string $class): array
    {
        $signatures = [];
        foreach ($this->query->graph()->incident($class) as $item) {
            if ($item['forward'] && $item['edge']->relation === Relation::Implements) {
                foreach ($this->outline->facts[$item['other']]->signatures ?? [] as $signature) {
                    $signatures[$signature] = true;
                }
            }
        }

        return $signatures;
    }

    /**
     * @return list<string>
     */
    private function classLines(string $class, ?ClassFacts $facts, bool $full): array
    {
        $node = $this->query->graph()->node($class);
        $label = $this->query->label($class);
        if ($facts === null) {
            return [\sprintf('    %s (%s)', $label, $node->kind->value ?? 'class')];
        }
        $kind = trim(implode(' ', $facts->modifiers) . ' ' . $facts->kind);
        $implemented = array_filter($this->query->graph()->incident($class), fn (array $item): bool => $item['forward'] && $item['edge']->relation === Relation::Implements && isset($this->outline->facts[$item['other']]));
        if ($implemented !== []) {
            $kind .= ', implements ' . implode(', ', array_map(fn (array $item): string => $this->query->label($item['other']), $implemented));
        }
        $lines = [\sprintf('    %s (%s)%s', $label, $kind, $facts->summary === null ? '' : ' — ' . $facts->summary)];
        $details = [];
        if ($facts->cases !== []) {
            $details[] = 'cases ' . implode(', ', array_map(static fn (string $case, string $value): string => $value === '' ? $case : $case . ' = ' . $value, array_keys($facts->cases), $facts->cases));
        }
        if ($facts->constants !== []) {
            $details[] = 'const ' . implode(', ', array_map(static fn (string $name, string $value): string => $name . ' = ' . $value, array_keys($facts->constants), $facts->constants));
        }
        $inherited = $this->inherited($class);
        $grouped = self::grouped(array_values(array_filter($facts->signatures, static fn (string $signature): bool => !isset($inherited[$signature]))));
        $signatures = $full ? $grouped : \array_slice($grouped, 0, self::METHODS);
        if ($signatures !== []) {
            $details[] = implode('; ', $signatures) . (\count($grouped) > \count($signatures) ? \sprintf('; +%d', \count($grouped) - \count($signatures)) : '');
        }
        foreach ($details as $detail) {
            $lines[] = '      ' . $detail;
        }

        return $lines;
    }

    /**
     * Methods taking and returning the same, one entry: `{addContext,addPolicy}Violation(string $code): void`.
     *
     * @param list<string> $signatures
     *
     * @return list<string>
     */
    private static function grouped(array $signatures): array
    {
        $groups = [];
        foreach ($signatures as $signature) {
            $open = (int) strpos($signature, '(');
            $groups[substr($signature, $open)][] = substr($signature, 0, $open);
        }
        $result = [];
        foreach ($groups as $rest => $names) {
            if (\count($names) === 1) {
                $result[] = $names[0] . $rest;
                continue;
            }
            $prefix = (string) array_reduce($names, static function (?string $common, string $name): string {
                if ($common === null) {
                    return $name;
                }
                $length = 0;
                while ($length < min(\strlen($common), \strlen($name)) && $common[$length] === $name[$length]) {
                    ++$length;
                }

                return substr($common, 0, $length);
            });
            // Cut at a word of the camel case: `add{Context,Policy}Violation`, not `{cod,messag}e`.
            while ($prefix !== '' && array_filter($names, static fn (string $name): bool => !ctype_upper($name[\strlen($prefix)] ?? 'A')) !== []) {
                $prefix = substr($prefix, 0, -1);
            }
            $suffix = (string) array_reduce($names, static function (?string $common, string $name) use ($prefix): string {
                $name = substr($name, \strlen($prefix));
                if ($common === null) {
                    return $name;
                }
                $length = 0;
                while ($length < min(\strlen($common), \strlen($name)) && $common[\strlen($common) - 1 - $length] === $name[\strlen($name) - 1 - $length]) {
                    ++$length;
                }

                $suffix = $length === 0 ? '' : substr($common, -$length);
                while ($suffix !== '' && !ctype_upper($suffix[0])) {
                    $suffix = substr($suffix, 1);
                }

                return $suffix;
            });
            $middles = array_map(static fn (string $name): string => substr($name, \strlen($prefix), \strlen($name) - \strlen($prefix) - \strlen($suffix)), $names);
            $result[] = $prefix . '{' . implode(',', $middles) . '}' . $suffix . $rest;
        }

        return $result;
    }

    /**
     * A chain from a route's handler down into the core, the closure a method runs shown where it runs:
     * `UseCase::handle() → ValidationPipelineRunner::run() (closure) → Builder::build()`.
     *
     * @param list<string> $chain
     */
    private function chain(array $chain): string
    {
        $graph = $this->query->graph();
        $parts = [];
        foreach ($chain as $index => $step) {
            $previous = $chain[$index - 1] ?? null;
            if ($previous !== null) {
                foreach ([$step, ...$this->overridden($step)] as $target) {
                    foreach ($graph->incident($target) as $item) {
                        if (!$item['forward'] && $item['edge']->relation === Relation::Calls && str_starts_with($item['edge']->via(), 'closure of ')
                            && \in_array($previous, explode(', ', substr($item['edge']->via(), 11)), true)) {
                            $parts[] = $this->query->label($item['other']) . ' runs the closure';
                            break 2;
                        }
                    }
                }
            }
            $parts[] = $this->query->label($step);
        }

        return implode(' → ', $parts);
    }

    /**
     * @return list<string> the methods a method implements or overrides, up the hierarchy
     */
    private function overridden(string $method): array
    {
        $found = [];
        for ($queue = [$method]; $queue !== [];) {
            foreach ($this->query->graph()->incident((string) array_shift($queue)) as $item) {
                if ($item['forward'] && $item['edge']->relation === Relation::Overrides && !\in_array($item['other'], $found, true)) {
                    $found[] = $item['other'];
                    $queue[] = $item['other'];
                }
            }
        }

        return $found;
    }

    private function json(): string
    {
        $outline = $this->outline;

        return (string) json_encode([
            'topic' => $outline->topic,
            'seeds' => $outline->seeds,
            'core' => array_map(static fn (string $class): array => array_filter([
                'id' => $class,
                'kind' => $outline->facts[$class]->kind ?? null,
                'modifiers' => $outline->facts[$class]->modifiers ?? null,
                'summary' => $outline->facts[$class]->summary ?? null,
                'signatures' => $outline->facts[$class]->signatures ?? null,
                'constants' => $outline->facts[$class]->constants ?? null,
                'cases' => $outline->facts[$class]->cases ?? null,
            ], static fn (mixed $value): bool => $value !== null), $outline->core),
            'sourcesRead' => $outline->sourcesRead,
            'families' => $outline->families,
            'routes' => array_map(fn (array $route): array => ['route' => $this->query->label($route['route']), 'id' => $route['route'], 'chain' => $route['chain']], $outline->routes),
            'entries' => $outline->entries,
            'closures' => $outline->closures,
            'inside' => $outline->inside,
            'hints' => array_map(static fn (array $hint): array => ['class' => $hint[0], 'method' => $hint[1]->method, 'line' => $hint[1]->line, 'text' => $hint[1]->text], $outline->hints),
            'wiring' => $outline->wiring,
            'consumers' => $outline->consumers,
            'consumerFiles' => $outline->consumerFiles,
            'tests' => $outline->tests,
            'topTests' => $outline->topTests,
            'uncovered' => $outline->uncovered,
            'unused' => array_keys($outline->unused),
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param list<string> $files
     */
    private static function commonDirectory(array $files): string
    {
        if ($files === []) {
            return '';
        }
        $common = explode('/', \dirname($files[0]));
        foreach ($files as $file) {
            $parts = explode('/', \dirname($file));
            $length = 0;
            while ($length < \count($common) && ($parts[$length] ?? null) === $common[$length]) {
                ++$length;
            }
            $common = \array_slice($common, 0, $length);
        }

        return $common === [] ? '' : implode('/', $common) . '/';
    }
}
