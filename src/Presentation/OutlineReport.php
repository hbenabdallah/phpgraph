<?php

declare(strict_types=1);

namespace PhpGraph\Presentation;

use PhpGraph\Extractor\ClassFacts;
use PhpGraph\Graph\NodeKind;
use PhpGraph\Graph\Relation;
use PhpGraph\Query\GraphQuery;
use PhpGraph\Query\Layers;
use PhpGraph\Query\Result\Outline;

/**
 * An outline as text, compact by default (a section lists all its entries, `full` also lifts the limits of each line),
 * or as JSON.
 */
final class OutlineReport
{
    public const SECTIONS = ['core', 'families', 'flow', 'behaviour', 'wiring', 'users'];

    private const METHODS = 5;

    private const LIST = 12;

    private const HINTS = 12;

    private const INSIDE = 7;

    private const ENTRIES = 6;

    private const LEFT = 5;

    private string $prefix = '';

    /** @var array<string, string>|null class => its label, with namespace segments when another class the outline names shares it */
    private ?array $names = null;

    public function __construct(
        private readonly GraphQuery $query,
        private readonly Outline $outline,
    ) {
    }

    /**
     * @param ?string $section one of SECTIONS, uncut, null for all of them
     */
    public function render(string $format, ?string $section = null): string
    {
        return $format === 'json' ? $this->json() : $this->text($format === 'full', $section);
    }

    private function text(bool $full, ?string $section): string
    {
        $wanted = static fn (string $name): bool => $section === null || $section === $name;
        $outline = $this->outline;
        $graph = $this->query->graph();
        $files = array_filter(array_map(fn (string $class): ?string => $graph->node($class)?->file, $outline->core));
        $this->prefix = self::commonDirectory(array_values($files));
        // A section alone lists everything, each line still compact; full also spells out every line.
        $all = $full || $section !== null;
        // The methods a flow line names, 0 for all.
        $named = $full ? 0 : 4;
        $more = static fn (array $all, array $shown): string => \count($all) > \count($shown) ? \sprintf(' (+%d)', \count($all) - \count($shown)) : '';

        $lines = [
            \sprintf(
                'Outline of "%s": %d classes, from %s; paths under %s. From the graph%s: read the bodies for the rest.',
                $outline->topic,
                \count($outline->core),
                $outline->method === null
                    ? implode(', ', array_map(fn (string $seed): string => $this->className($seed), $outline->seeds))
                    : \sprintf('%s and what it calls, %d calls deep (each class with those methods only)', $this->query->label($outline->method), max([0, ...$outline->depths])),
                $this->prefix === '' ? 'the project' : $this->prefix,
                $outline->sourcesRead ? ' and the sources' : ' (sources not found: no signatures nor hints)',
            ),
        ];
        // A class of many methods mixes features: the outline of one of them follows only what it runs.
        if ($section === null && $outline->large !== null) {
            $large = $outline->large;
            $class = $this->query->label($large['class']);
            $example = array_key_first($large['entries']);
            $lines[] = \sprintf(
                '%s declares %d methods, several features mixed: outline one of them for what it runs only, e.g. outline "%s".%s',
                $class,
                $large['methods'],
                $example === null ? $class . '::<method>' : $class . '::' . substr($example, (int) strrpos($example, ':') + 1),
                $large['entries'] === [] ? '' : ' Its entry points: ' . implode(', ', array_map(self::entryPoint(...), array_keys($large['entries']), $large['entries'])) . '.',
            );
        }

        // The core is capped: what qualified past it, so the agent narrows the question rather than take it as whole.
        if ($section === null && $outline->left !== []) {
            $shown = \array_slice($outline->left, 0, self::LEFT);
            $lines[] = \sprintf(
                'The core is full: %d more classes qualified, left out: %s%s. A narrower question, or a class name, outlines them.',
                \count($outline->left),
                implode(', ', array_map(fn (string $class): string => $this->className($class), $shown)),
                $more($outline->left, $shown),
            );
        }

        // At a glance: what an agent explaining the feature must not miss, before the details.
        $hints = $outline->hints;
        $glance = [];
        foreach ($section === null ? $hints : [] as $index => [$class, $hint]) {
            if (str_contains($hint->text, ', else ') && \count($glance) < 3) {
                $glance[] = \sprintf('  Outcome: %s::%s L%d: %s', $this->query->label($class), $hint->method, $hint->line, $hint->text);
                unset($hints[$index]);
            }
        }
        // A section alone: the gaps with the users, the outcomes with the behaviour.
        $gaps = $section === null || $section === 'users';
        if ($gaps && $outline->unused !== []) {
            $glance[] = '  Nothing in the application uses: ' . implode(', ', array_map(
                fn (string $class, bool $tested): string => $this->className($class) . ($tested ? ' (tests only)' : ''),
                array_keys($outline->unused),
                $outline->unused,
            )) . '.';
        }
        if ($gaps && $outline->uncovered !== []) {
            $glance[] = '  No test touches: ' . implode(', ', array_map(fn (string $class): string => $this->className($class), $outline->uncovered)) . '.';
        }
        // A guess may be wrong: an untyped receiver whose method name is declared once in the project.
        if ($section === null && $outline->guesses !== []) {
            $guesses = self::limited($all, $outline->guesses, self::LIST);
            $glance[] = \sprintf(
                '  Guessed calls (AMBIGUOUS: untyped receiver, linked by a method name declared once; check them in the code)%s: %s%s.',
                $outline->guessed === [] ? '' : ', what only they reach is marked "guessed"',
                implode('; ', array_map(fn (string $caller, array $callees): string => $this->query->label($caller) . ' → ' . $this->methodList($callees, 0), array_keys($guesses), $guesses)),
                $more($outline->guesses, $guesses),
            );
        }
        if ($glance !== []) {
            $lines[] = '';
            $lines[] = $section === null ? 'At a glance:' : 'Gaps:';
            array_push($lines, ...$glance);
        }
        $hints = array_values($hints);

        // Core, by namespace.
        if ($wanted('core')) {
            $byNamespace = [];
            foreach ($outline->core as $class) {
                $byNamespace[substr($class, 0, (int) strrpos($class, '\\'))][] = $class;
            }
            $common = array_reduce(array_map('strval', array_keys($byNamespace)), static function (?string $common, string $namespace): string {
                if ($common === null) {
                    return $namespace . '\\';
                }
                // Without a parent left (`App\` against `Database\`, or no namespace), nothing in common: substr()
                // would keep the first letter and never get shorter.
                while ($common !== '' && !str_starts_with($namespace . '\\', $common)) {
                    $parent = strrpos(rtrim($common, '\\'), '\\');
                    $common = $parent === false ? '' : substr($common, 0, $parent + 1);
                }

                return $common;
            }) ?? '';
            $lines[] = '';
            $common = rtrim($common, '\\') === '' ? '' : $common;
            $lines[] = 'Core, by namespace' . ($common === '' ? '' : ' (under ' . rtrim($common, '\\') . ')') . ':';
            foreach ($byNamespace as $namespace => $classes) {
                $layer = Layers::of(explode('\\', (string) $namespace));
                $lines[] = '  ' . self::namespaceLabel((string) $namespace, $common) . ($layer === null ? '' : ' [' . $layer['segment'] . ']');
                foreach ($classes as $class) {
                    array_push($lines, ...$this->classLines($class, $outline->facts[$class] ?? null, $all));
                }
            }

        }

        // Families.
        if ($wanted('families') && $outline->families !== []) {
            $lines[] = '';
            $directories = [];
            foreach ($outline->families as $family) {
                array_push($directories, ...array_map('strval', array_keys($family['directories'])));
            }
            $base = self::commonDirectory(array_map(static fn (string $directory): string => $directory . '/', $directories));
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
                $shown = self::limited($all, $modules, \count($modules) > 3 ? 2 : 3);
                $inModules = '';
                if ($modules !== []) {
                    $hidden = \count($modules) > \count($shown) ? \sprintf(' +%d modules', \count($modules) - \count($shown)) : '';
                    $inModules = ' in ' . implode(', ', array_map(static fn (string $module, int $count): string => $module . ' ' . $count, array_keys($shown), $shown)) . $hidden;
                }
                $lines[] = \sprintf(
                    '  %s: %s%s%s',
                    $this->query->label($family['head']),
                    $family['members'] === 0 ? '0 implementations' : $family['members'],
                    $inModules,
                    $family['receivers'] === [] ? '' : '; received by ' . implode(', ', array_map(fn (string $receiver): string => $this->query->label(explode(' ', $receiver, 2)[0]) . ' ' . (explode(' ', $receiver, 2)[1] ?? ''), $family['receivers'])),
                );
            }
            if ($single !== []) {
                $lines[] = '  One implementation each: ' . implode(', ', $single);
            }
        }

        // Flow.
        if ($wanted('flow')) {
            $lines[] = '';
            $lines[] = 'Flow:';
            if ($outline->routes !== []) {
                $lines[] = \sprintf('  Routes (%d), from their handler into the core:', \count($outline->routes));
                foreach (self::limited($all, $outline->routes, self::LIST) as $route) {
                    $operations = $this->operations($route['route']);
                    $lines[] = '    ' . $this->query->label($route['route']) . ' → ' . $this->chain($route['chain'])
                        . ($operations === [] ? '' : ' (API: ' . implode(', ', array_map(fn (string $operation): string => $this->operationLabel($operation), $operations)) . ')');
                }
            }
            $rpcs = $this->rpcs($outline->core);
            if ($rpcs !== []) {
                $lines[] = '  API operations its methods implement:';
                foreach (self::limited($all, array_keys($rpcs), self::LIST) as $operation) {
                    $lines[] = '    ' . $this->operationLabel((string) $operation) . ' → ' . $this->query->label($rpcs[$operation]);
                }
            }
            // The methods called from outside, then those called only by the members of a family.
            $called = array_keys($outline->entries + $outline->fromFamilies);
            if ($called !== []) {
                $lines[] = '  Into the core, from outside (callers):';
                foreach (self::limited($all, $called, self::ENTRIES) as $method) {
                    $callers = $outline->entries[$method] ?? [];
                    $classes = array_unique(array_map(static fn (string $caller): string => explode('::', $caller)[0], $callers));
                    $from = [];
                    if ($callers !== []) {
                        $from[] = \count($classes) > 8 && !$full ? \sprintf('%d classes', \count($classes)) : $this->methodList($callers, $named);
                    }
                    // The members of a family by name when few, else counted under the family.
                    foreach ($outline->fromFamilies[$method] ?? [] as $head => $members) {
                        $memberClasses = array_unique(array_map(static fn (string $caller): string => explode('::', $caller)[0], $members));
                        $from[] = \count($memberClasses) > 4 && !$full
                            ? \sprintf('%d %s', \count($memberClasses), $this->query->label((string) $head))
                            : $this->methodList($members, 0) . ' (' . $this->query->label((string) $head) . ')';
                    }
                    // The callers from the core too, else the line reads as every caller while "Inside" is cut.
                    $fromCore = array_keys(array_filter($outline->inside, static fn (array $callees): bool => \in_array($method, $callees, true)));
                    $coreCallers = '';
                    if ($fromCore !== []) {
                        $separator = $from === [] ? '' : '; ';
                        $coreCallers = $separator . 'from the core: ' . $this->methodList(array_map('strval', $fromCore), $named);
                    }
                    $lines[] = \sprintf('    %s ← %s%s', $this->query->label($method), implode(', ', $from), $coreCallers);
                }
                if (!$all && \count($called) > self::ENTRIES) {
                    $lines[] = \sprintf('    +%d more: section flow', \count($called) - self::ENTRIES);
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
                $lines[] = $outline->method === null ? '  Inside the core:' : '  Calls, nearest first:';
                $inside = self::limited($all, $outline->inside, $outline->method === null ? self::INSIDE : self::HINTS);
                foreach ($inside as $method => $callees) {
                    $guessed = $outline->guesses[$method] ?? [];
                    $sure = array_values(array_diff($callees, $guessed));
                    $lines[] = \sprintf(
                        '    %s → %s',
                        $this->query->label($method),
                        implode('; ', array_filter([
                            $sure === [] ? '' : $this->methodList($sure, $named),
                            $guessed === [] ? '' : 'guessed: ' . $this->methodList($guessed, $named),
                        ])),
                    );
                }
                if (!$all && \count($outline->inside) > \count($inside)) {
                    $lines[] = \sprintf('    +%d more: section flow', \count($outline->inside) - \count($inside));
                }
            }
            if ($outline->hubs !== []) {
                $hubs = self::limited($all, $outline->hubs, self::LIST);
                $lines[] = \sprintf(
                    '  Hubs it calls, not followed (application classes calling them): %s%s',
                    implode(', ', array_map(fn (string $hub, int $classes): string => $this->query->label($hub) . ' ' . $classes, array_keys($hubs), $hubs)),
                    $more($outline->hubs, $hubs),
                );
            }

        }

        // Behaviour.
        if ($wanted('behaviour') && $hints !== []) {
            $lines[] = '';
            $lines[] = 'Behaviour (read in the method bodies)' . ($hints === $outline->hints ? '' : ', besides the outcomes above') . ':';
            foreach (self::limited($all, $hints, self::HINTS) as [$class, $hint]) {
                $lines[] = \sprintf('  %s::%s L%d: %s', $this->query->label($class), $hint->method, $hint->line, $hint->text);
            }
            if (!$all && \count($hints) > self::HINTS) {
                $lines[] = \sprintf('  +%d more: section behaviour', \count($hints) - self::HINTS);
            }
        }

        // Wiring.
        if ($wanted('wiring') && $outline->wiring !== []) {
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
        if ($wanted('users')) {
            $lines[] = '';
            $consumers = $outline->consumers;
            $shownConsumers = self::limited($all, $consumers, 8);
            // No class outside the core calls the method: an entry point only when nothing calls it, its own class neither.
            $callers = $outline->method === null ? [] : array_values(array_unique($outline->entries[$outline->method] ?? []));
            if ($outline->method !== null && $consumers === [] && $callers === []) {
                $lines[] = \sprintf('No application class calls %s: an entry point%s.', $this->query->label($outline->method), $outline->routes === [] ? ' (no route found either: called by name, a hook, or dead)' : ', see its routes');
            } elseif ($outline->method !== null && $consumers === []) {
                $class = explode('::', $outline->method)[0];
                $own = array_filter($callers, static fn (string $caller): bool => explode('::', $caller)[0] === $class) === $callers;
                $shown = $all ? $callers : \array_slice($callers, 0, self::ENTRIES);
                $lines[] = \sprintf(
                    '%s is called only from %s: %s%s.',
                    $this->query->label($outline->method),
                    $own ? 'its own class' : 'the core',
                    implode(', ', array_map(fn (string $caller): string => $own
                        ? substr($caller, (int) strrpos($caller, ':') + 1) . '()'
                        : $this->query->label($caller), $shown)),
                    $more($callers, $shown),
                );
            } else {
                $lines[] = \sprintf(
                    'Used by %d files outside the core. Application%s: %s%s.',
                    $outline->consumerFiles,
                    ' (links)',
                    implode(', ', array_map(fn (string $class, int $links): string => $this->className($class) . ' ' . $links, array_keys($shownConsumers), $shownConsumers)),
                    $more($consumers, $shownConsumers),
                );
            }
            if ($outline->tests !== []) {
                $tests = $outline->tests;
                $shownTests = self::limited($all, $tests, 4);
                $lines[] = \sprintf(
                    'Tests touching the core: %d files in %d modules (%s%s); touching the most: %s.',
                    array_sum($tests),
                    \count($tests),
                    implode(', ', array_map(static fn (string $module, int $count): string => $module . ' ' . $count, array_keys($shownTests), $shownTests)),
                    $more($tests, $shownTests),
                    implode(', ', $outline->topTests),
                );
            }
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
            return [\sprintf('    %s (%s%s)', $label, $node->kind->value ?? 'class', $this->isGuessed($class) ? '; guessed' : '')];
        }
        $kind = trim(implode(' ', $facts->modifiers) . ' ' . $facts->kind);
        $implemented = array_filter($this->query->graph()->incident($class), fn (array $item): bool => $item['forward'] && $item['edge']->relation === Relation::Implements && isset($this->outline->facts[$item['other']]));
        if ($implemented !== []) {
            $kind .= ', implements ' . implode(', ', array_map(fn (array $item): string => $this->query->label($item['other']), $implemented));
        }
        $lines = [\sprintf('    %s (%s%s)%s', $label, $kind, $this->isGuessed($class) ? '; guessed' : '', $facts->summary === null ? '' : ' — ' . $facts->summary)];
        $details = [];
        if ($facts->cases !== []) {
            $details[] = 'cases ' . implode(', ', array_map(static fn (string $case, string $value): string => $value === '' ? $case : $case . ' = ' . $value, array_keys($facts->cases), $facts->cases));
        }
        if ($facts->constants !== []) {
            $details[] = 'const ' . implode(', ', array_map(static fn (string $name, string $value): string => $name . ' = ' . $value, array_keys($facts->constants), $facts->constants));
        }
        if ($facts->properties !== []) {
            $details[] = implode('; ', array_map(static fn (string $name, array $property): string => '$' . $name . ': ' . $property['type'], array_keys($facts->properties), $facts->properties));
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
     * A class of a method's outline whose methods are all reached through guessed calls only.
     */
    private function isGuessed(string $class): bool
    {
        $methods = array_filter(array_keys($this->outline->depths), static fn (string $method): bool => explode('::', $method)[0] === $class);

        return $methods !== [] && array_diff($methods, $this->outline->guessed) === [];
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
     * The first entries of a list, keys kept; all of them with `all`.
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param array<TKey, TValue> $items
     *
     * @return array<TKey, TValue>
     */
    private static function limited(bool $all, array $items, int $count): array
    {
        return $all ? $items : \array_slice($items, 0, $count, true);
    }

    /**
     * The API operations a route or a method implements (OpenAPI, .proto).
     *
     * @return list<string>
     */
    private function operations(string $id): array
    {
        $operations = [];
        foreach ($this->query->graph()->incident($id) as $item) {
            if (!$item['forward'] && $item['edge']->relation === Relation::ImplementedBy && $this->query->graph()->node($item['other'])?->kind === NodeKind::Operation) {
                $operations[] = $item['other'];
            }
        }

        return $operations;
    }

    /**
     * The operations the methods of the core implement: gRPC methods.
     *
     * @param list<string> $core
     *
     * @return array<string, string> operation => method
     */
    private function rpcs(array $core): array
    {
        $rpcs = [];
        foreach ($core as $class) {
            foreach ($this->query->graph()->incident($class) as $item) {
                if ($item['forward'] && $item['edge']->relation === Relation::HasMethod) {
                    foreach ($this->operations($item['other']) as $operation) {
                        $rpcs[$operation] ??= $item['other'];
                    }
                }
            }
        }

        return $rpcs;
    }

    /**
     * `save() (2 routes, called by 3 classes)`: an entry point of a large class.
     *
     * @param array{routes: int, callers: int} $entry
     */
    private static function entryPoint(string $method, array $entry): string
    {
        $uses = [];
        if ($entry['routes'] > 0) {
            $uses[] = \sprintf('%d route%s', $entry['routes'], $entry['routes'] > 1 ? 's' : '');
        }
        if ($entry['callers'] > 0) {
            $uses[] = \sprintf('called by %d class%s', $entry['callers'], $entry['callers'] > 1 ? 'es' : '');
        }

        return substr($method, (int) strrpos($method, ':') + 1) . '() (' . implode(', ', $uses) . ')';
    }

    /**
     * A namespace of the core below the one common to all, or whole when it is the common one.
     */
    private static function namespaceLabel(string $namespace, string $common): string
    {
        if ($namespace === '') {
            return '(no namespace)';
        }
        $below = substr($namespace, \strlen($common));

        return $below === '' ? $namespace : $below;
    }

    /**
     * `GET /orders openapi.yaml:3`
     */
    private function operationLabel(string $operation): string
    {
        $label = $this->query->label($operation);
        $node = $this->query->graph()->node($operation);
        if ($node?->file === null) {
            return $label;
        }
        $line = $node->line === null ? '' : ':' . $node->line;

        return $label . ' ' . $node->file . $line;
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
        $data = [
            'topic' => $outline->topic,
            'seeds' => $outline->seeds,
            'core' => array_map($this->coreClassData(...), $outline->core),
            'sourcesRead' => $outline->sourcesRead,
            'families' => $outline->families,
            'routes' => array_map($this->routeData(...), $outline->routes),
            'operations' => $this->rpcs($outline->core),
            'entries' => $outline->entries,
            'fromFamilies' => $outline->fromFamilies,
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
        ];
        if ($outline->method !== null) {
            $data['method'] = $outline->method;
            $data['depths'] = $outline->depths;
            $data['hubs'] = $outline->hubs;
            $data['guesses'] = $outline->guesses;
            $data['guessed'] = $outline->guessed;
        }
        if ($outline->large !== null) {
            $data['large'] = $outline->large;
        }
        if ($outline->left !== []) {
            $data['left'] = $outline->left;
        }

        return (string) json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * A class of the core with the facts its source gave, those it gave none of left out.
     *
     * @return array{id: string, kind?: string, modifiers?: list<string>, summary?: string, signatures?: list<string>, constants?: array<string, string>, cases?: array<string, string>, properties?: array<string, string>}
     */
    private function coreClassData(string $class): array
    {
        $facts = $this->outline->facts[$class] ?? null;
        $properties = array_map(static fn (array $property): string => $property['type'], $facts->properties ?? []);

        return array_filter([
            'id' => $class,
            'kind' => $facts?->kind,
            'modifiers' => $facts?->modifiers,
            'summary' => $facts?->summary,
            'signatures' => $facts?->signatures,
            'constants' => $facts?->constants,
            'cases' => $facts?->cases,
            'properties' => $properties === [] ? null : $properties,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array{route: string, chain: list<string>} $route
     *
     * @return array{route: string, id: string, chain: list<string>, operations?: list<string>}
     */
    private function routeData(array $route): array
    {
        $data = ['route' => $this->query->label($route['route']), 'id' => $route['route'], 'chain' => $route['chain']];
        $operations = $this->operations($route['route']);
        if ($operations !== []) {
            $data['operations'] = $operations;
        }

        return $data;
    }

    /**
     * A class's label in a flat list: `LogController`, or `Api\LogController` beside `Http\Controllers\LogController`
     * when the outline names both.
     */
    private function className(string $class): string
    {
        if ($this->names === null) {
            $outline = $this->outline;
            $classes = array_unique(array_map('strval', [...$outline->seeds, ...$outline->core, ...$outline->left, ...$outline->uncovered, ...array_keys($outline->unused), ...array_keys($outline->consumers)]));
            $byLabel = [];
            foreach ($classes as $named) {
                $byLabel[$this->query->label($named)][] = $named;
            }
            $this->names = [];
            foreach ($byLabel as $label => $sharing) {
                foreach ($sharing as $named) {
                    $this->names[$named] = \count($sharing) === 1 ? $label : self::distinct($named, $sharing);
                }
            }
        }

        return $this->names[$class] ?? $this->query->label($class);
    }

    /**
     * The fewest last segments of a class name telling it from the others.
     *
     * @param list<string> $sharing
     */
    private static function distinct(string $class, array $sharing): string
    {
        $segments = explode('\\', $class);
        for ($length = 2; $length < \count($segments); ++$length) {
            $suffix = implode('\\', \array_slice($segments, -$length));
            $clashes = array_filter($sharing, static fn (string $other): bool => $other !== $class && str_ends_with('\\' . $other, '\\' . $suffix));
            if ($clashes === []) {
                return $suffix;
            }
        }

        return $class;
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
