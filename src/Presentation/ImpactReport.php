<?php

declare(strict_types=1);

namespace PhpGraph\Presentation;

use PhpGraph\Builder\TestFiles;
use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Edge;
use PhpGraph\Graph\Node;
use PhpGraph\Graph\NodeKind;
use PhpGraph\Graph\Relation;
use PhpGraph\Query\GraphQuery;
use PhpGraph\Query\Result\Impact;
use PhpGraph\Query\Result\ImpactedClass;
use PhpGraph\Query\Usage;

/**
 * The answer of `impact`, compact: one line per class with its call sites, one per route with its chain, one per test
 * with what it reaches; or the same as JSON. An agent reads it whole: every byte counts.
 */
final class ImpactReport
{
    private const SITES = 8;

    /** Other chains of a route shown by default. */
    private const CHAINS = 2;

    private const TESTS = 100;

    /** @var array<string, list<ImpactedClass>> */
    private array $groups = ['direct' => [], 'routes' => [], 'state' => [], 'quietState' => [], 'tests' => [], 'stateTests' => [], 'quietStateTests' => [], 'helpers' => []];

    private string $prefix;

    /** @var array<string, int> short class name => classes listed with it */
    private array $names = [];

    public function __construct(
        private readonly GraphQuery $query,
        private readonly Node $changed,
        private readonly Impact $impact,
    ) {
        foreach ($impact->classes as $class) {
            $key = match (true) {
                str_starts_with($class->class, 'route:') || str_contains($class->class, '@route:') => 'routes',
                $class->isTest && !$this->isTestCase($class->class) => 'helpers',
                $class->isTest && $class->throughState && $class->confidence === Confidence::Ambiguous => 'quietStateTests',
                $class->isTest => $class->throughState ? 'stateTests' : 'tests',
                // Reading all of the state, without using what the change writes: counted, listed on request.
                $class->throughState && $class->confidence === Confidence::Ambiguous => 'quietState',
                default => $class->throughState ? 'state' : 'direct',
            };
            $this->groups[$key][] = $class;
        }
        // Through the state: the readers filtering on what it writes (INFERRED) first, then by module.
        $module = static fn (string $class): string => implode('\\', \array_slice(explode('\\', $class), 0, 2));
        $modules = array_fill_keys(array_map(static fn (ImpactedClass $class): string => $module($class->class), $this->groups['direct']), true);
        $rank = static fn (ImpactedClass $class): int => $class->confidence === Confidence::Ambiguous ? 1 : 0;
        foreach (['state', 'quietState', 'stateTests', 'quietStateTests'] as $key) {
            usort($this->groups[$key], static fn ($a, $b): int => [$rank($a), isset($modules[$module($b->class)]), $a->depth] <=> [$rank($b), isset($modules[$module($a->class)]), $b->depth]);
        }

        $files = [];
        foreach ($impact->classes as $class) {
            $file = $this->query->graph()->node($class->class)?->file;
            if ($file !== null) {
                $files[] = $file;
            }
        }
        $this->prefix = $this->commonDirectory($files);
        foreach ($impact->classes as $class) {
            $name = $this->query->label($class->class);
            $this->names[$name] = ($this->names[$name] ?? 0) + 1;
        }
    }

    public function render(string $format, int $limit, ?string $section): string
    {
        return $format === 'json' ? $this->json($section) : $this->text($limit, $section);
    }

    private function text(int $limit, ?string $section): string
    {
        $g = $this->groups;
        $lines = [
            \sprintf('Impact of changing %s [%s], %s', $this->changed->label, $this->changed->kind->value, $this->location($this->changed, false)),
            \sprintf(
                '%d classes up to %d relations away, %d routes, %d more through the state%s; %d tests, %d through the state%s; %d helpers%s.',
                \count($g['direct']),
                $this->impact->maxDepth,
                \count($g['routes']),
                \count($g['state']),
                $g['quietState'] === [] ? '' : \sprintf(' (+%d reading all of it: section state)', \count($g['quietState'])),
                \count($g['tests']),
                \count($g['stateTests']),
                $g['quietStateTests'] === [] ? '' : \sprintf(' (+%d reading all of it: section state-tests)', \count($g['quietStateTests'])),
                \count($g['helpers']),
                $this->impact->truncated ? ' (stopped at the limit: more exist)' : '',
            ),
            \sprintf(
                'Paths under %s; a class shows its module. INFERRED unless marked. Per class: methods reaching the change, lines, → what they call. %s',
                $this->prefix === '' ? 'the project root' : $this->prefix,
                $limit === 0 ? 'Complete.' : \sprintf('%d per list, %d sites per class: limit 0 for all, format json or full.', $limit, self::SITES),
            ),
        ];
        $wanted = static fn (string $name): bool => $section === null || $section === $name;
        $slice = static fn (array $entries, int $default) => $limit === 0 ? $entries : \array_slice($entries, 0, max($limit, $default));
        $more = static function (array $all, array $shown) use (&$lines): void {
            if (\count($all) > \count($shown)) {
                $lines[] = \sprintf('  ... %d more', \count($all) - \count($shown));
            }
        };

        if ($wanted('routes') && $g['routes'] !== []) {
            $lines[] = '';
            $lines[] = 'Routes reaching it, ← from their handler:';
            foreach ($shown = $slice($g['routes'], 0) as $route) {
                $marked = $route->confidence === Confidence::Inferred ? '' : ' [' . $route->confidence->value . ']';
                $also = \array_slice($route->otherChains, 0, $limit === 0 ? 5 : self::CHAINS);
                $others = \count($route->otherChains) - \count($also);
                $lines[] = \sprintf(
                    '  %s%s ← %s%s',
                    $this->query->label($route->class),
                    $marked,
                    $this->chain($route->chain),
                    $others === 0 || $limit === 0 ? '' : \sprintf(' (+%d other chain%s: limit 0)', $others, $others > 1 ? 's' : ''),
                );
                foreach ($also as $chain) {
                    $lines[] = '    also ← ' . $this->chain($chain);
                }
            }
            $more($g['routes'], $shown);
        }

        if ($wanted('direct')) {
            for ($level = 1; $level <= $this->impact->maxDepth; ++$level) {
                $atDepth = array_values(array_filter($g['direct'], static fn (ImpactedClass $class): bool => $class->depth === $level));
                if ($atDepth === []) {
                    continue;
                }
                $lines[] = '';
                $lines[] = $level === 1 ? 'Direct dependents:' : \sprintf('%d relations away:', $level);
                foreach ($shown = $slice($atDepth, 0) as $class) {
                    $lines[] = '  ' . $this->classLine($class, $limit);
                }
                $more($atDepth, $shown);
            }
            $uncovered = $this->uncovered();
            if ($uncovered !== []) {
                $lines[] = '';
                $lines[] = 'No test touches: ' . implode(', ', $uncovered);
            }
        }

        $state = $section === 'state' ? [...$g['state'], ...$g['quietState']] : $g['state'];
        if ($wanted('state') && $state !== []) {
            $lines[] = '';
            $lines[] = 'Through the state it changes (they call a method reading what it writes and filter on it, compare before and after, or use what it writes):';
            foreach ($shown = $slice($state, 0) as $class) {
                $lines[] = '  ' . $this->classLine($class, $limit);
            }
            $more($state, $shown);
        }
        if ($section === null) {
            $unused = $this->unused();
            if ($unused !== []) {
                $lines[] = '';
                $lines[] = 'Nothing in the application uses: ' . implode(', ', array_map(
                    static fn (string $name, bool $tested): string => $name . ($tested ? ' (tests only)' : ''),
                    array_keys($unused),
                    $unused,
                ));
            }
        }

        foreach ([
            'tests' => ['Tests to run, by module (path below it; ← what they reach):', $g['tests'], $wanted('tests')],
            'stateTests' => ['Tests possibly affected through the state:', $g['stateTests'], $wanted('tests') || $section === 'state-tests'],
            // Tests reaching only what reads all of the state: counted in the summary, listed on request.
            'quietStateTests' => ['Tests reaching a method that reads all of the state (AMBIGUOUS):', $g['quietStateTests'], $section === 'state-tests'],
        ] as [$title, $tests, $show]) {
            if ($tests === [] || !$show) {
                continue;
            }
            $lines[] = '';
            $lines[] = $title;
            // One test per line, its path below its module: what PHPUnit takes, and unique where names repeat.
            $byModule = [];
            foreach ($shown = $slice($tests, self::TESTS) as $test) {
                $file = (string) $this->file($test->class);
                $module = $this->module($file);
                $base = $this->prefix . ($module === '' ? '' : $module . '/');
                $byModule[$module][] = (str_starts_with($file, $base) ? substr($file, \strlen($base)) : $file) . $this->reason($test);
            }
            ksort($byModule);
            foreach ($byModule as $module => $paths) {
                sort($paths);
                $lines[] = \sprintf('  %s%s/', $this->prefix, $module);
                foreach ($paths as $path) {
                    $lines[] = '    ' . $path;
                }
            }
            $more($tests, $shown);
        }

        if ($wanted('helpers') && $g['helpers'] !== []) {
            $lines[] = '';
            $lines[] = 'Test helpers on the way: ' . implode(', ', array_map(fn (ImpactedClass $class): string => $this->query->label($class->class), $g['helpers']));
            $unused = $this->unusedHelperMethods();
            if ($unused !== []) {
                $lines[] = 'Unused (no caller): ' . implode(', ', $unused);
            }
        }

        return implode("\n", $lines);
    }

    private function json(?string $section): string
    {
        $class = fn (ImpactedClass $class): array => [
            'class' => $class->class,
            'file' => $this->file($class->class),
            'depth' => $class->depth,
            'confidence' => $class->confidence->value,
        ] + ($class->comparesState ? ['comparesState' => true] : []) + ($class->followed ? [] : ['followed' => false]) + [
            'sites' => array_map(static fn (Edge $edge): array => [
                'source' => $edge->source,
                'relation' => $edge->relation->value,
                'target' => $edge->target,
            ] + ($edge->lines() === [] ? [] : ['lines' => $edge->lines()]) + ($edge->via() === '' ? [] : ['via' => $edge->via()]), $class->sites),
        ];
        $data = [
            'changed' => ['id' => $this->changed->id, 'kind' => $this->changed->kind->value, 'file' => $this->changed->file, 'line' => $this->changed->line],
            'maxDepth' => $this->impact->maxDepth,
            'truncated' => $this->impact->truncated,
            'routes' => array_map(fn (ImpactedClass $route): array => [
                'route' => $this->query->label($route->class),
                'id' => $route->class,
                'confidence' => $route->confidence->value,
                'chain' => $route->chain,
            ], $this->groups['routes']),
            'direct' => array_map($class, $this->groups['direct']),
            'uncovered' => $this->uncovered(),
            'unused' => array_keys($this->unused()),
            'state' => array_map($class, [...$this->groups['state'], ...$this->groups['quietState']]),
            'tests' => array_map($class, $this->groups['tests']),
            'stateTests' => array_map($class, [...$this->groups['stateTests'], ...$this->groups['quietStateTests']]),
            'helpers' => array_map(static fn (ImpactedClass $helper): string => $helper->class, $this->groups['helpers']),
            'unusedHelperMethods' => $this->unusedHelperMethods(),
        ];
        if ($section !== null) {
            $key = match ($section) {
                'state-tests' => 'stateTests',
                default => $section,
            };
            $data = array_intersect_key($data, ['changed' => true, $key => true]);
        }

        return (string) json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * `PaymentsModeRule [INFERRED] SalesOrder/CustomerOrder/…/PaymentsModeRule.php: apply L63, L70; check L85 → notifyContextViolation()`
     */
    private function classLine(ImpactedClass $class, int $limit): string
    {
        $notes = [];
        if ($class->comparesState) {
            $notes[] = 'compares before and after';
        }
        if ($class->uses !== null) {
            $notes[] = 'uses ' . $this->query->label($class->uses);
        }
        if ($class->through !== null) {
            $notes[] = 'through ' . $this->query->label($class->through);
        }
        // Through the state, nothing is followed further: the section says so.
        if (!$class->followed && !$class->throughState) {
            $notes[] = $class->edge->relation === Relation::Receives ? 'receives it among others, not followed' : 'inherited code, not followed';
        }

        // By calling method: its lines, and what it calls when that is not the change itself.
        $byMethod = [];
        foreach ($class->sites as $edge) {
            $method = str_contains($edge->source, '::') ? substr($edge->source, (int) strrpos($edge->source, ':') + 1) : $edge->relation->value;
            $target = $edge->target === $this->changed->id || $edge->target === $class->class ? '' : ' → ' . $this->target($edge->target, $class->class);
            $key = $method . $target;
            $byMethod[$key]['method'] = $method;
            $byMethod[$key]['target'] = $target;
            $byMethod[$key]['lines'] = [...$byMethod[$key]['lines'] ?? [], ...$edge->lines()];
            if ($edge->lines() === [] && $edge->relation !== Relation::Calls) {
                $byMethod[$key]['relation'] = $edge->relation->value;
            }
            if (str_starts_with($edge->via(), 'named: ')) {
                // The names a rename would break; the first three, then how many more.
                $names = explode(', ', substr($edge->via(), 7));
                $byMethod[$key]['named'] = implode(', ', \array_slice($names, 0, 3)) . (\count($names) > 3 ? \sprintf(' +%d', \count($names) - 3) : '');
            }
        }
        $sites = [];
        foreach ($byMethod as $site) {
            $lines = array_values(array_unique($site['lines']));
            sort($lines);
            $sites[] = trim($site['method'] . ($lines === [] ? '' : ' L' . implode(', L', $lines)) . (isset($site['relation']) ? ' (' . $site['relation'] . ')' : '')
                . (isset($site['named']) ? ' (named: ' . $site['named'] . ')' : '') . $site['target']);
        }
        $shown = $limit === 0 ? $sites : \array_slice($sites, 0, self::SITES);

        if ($class->confidence !== Confidence::Inferred) {
            array_unshift($notes, $class->confidence->value);
        }
        // Reading the state twice in a method, around what changes it: `count($n->all())` before and after a rule.
        foreach ($class->sites as $edge) {
            if (!$class->comparesState && \count($edge->lines()) >= 2 && $this->readsTheChange($edge->target)) {
                $notes[] = \sprintf('compares %s before and after', $this->target($edge->target, $class->class));
                break;
            }
        }

        $where = $this->where($class->class);

        return \sprintf(
            '%s%s%s%s',
            $this->query->label($class->class),
            $where === '' || $where === '.' ? '' : ' (' . $where . ')',
            $notes === [] ? '' : ' [' . implode(', ', $notes) . ']',
            $shown === [] ? '' : ': ' . implode('; ', $shown) . (\count($sites) > \count($shown) ? \sprintf('; +%d', \count($sites) - \count($shown)) : ''),
        );
    }

    /**
     * Why a test is listed: the first class of the project on its way to the change.
     */
    private function reason(ImpactedClass $test): string
    {
        foreach ($test->chain as $index => $node) {
            $class = explode('::', $node)[0];
            $file = $this->query->graph()->node($class)?->file;
            if ($index > 0 && $file !== null && !TestFiles::isTest($file)) {
                return $class === explode('::', $this->changed->id)[0] ? '' : ' (← ' . $this->short($node) . ')';
            }
        }

        return '';
    }

    /**
     * A class by its short name, with its folder when another listed class has the same name:
     * `GetEstimatePreview/LoadCustomerRule`.
     */
    private function name(string $class): string
    {
        $label = $this->query->label($class);
        $file = $this->file($class);

        return ($this->names[$label] ?? 0) > 1 && $file !== null ? basename(\dirname($file)) . '/' . $label : $label;
    }

    /**
     * Where a class is: its module when its file is ClassName.php (PSR-4), else its compact path; its last folder
     * too when another listed class has the same name.
     */
    private function where(string $class): string
    {
        $file = $this->file($class);
        $short = substr($class, (int) strrpos('\\' . $class, '\\'));

        if ($file === null || basename($file) !== $short . '.php') {
            return $this->compact($file);
        }
        $module = $this->module($file);
        $folder = basename(\dirname($file));

        return ($this->names[$short] ?? 0) > 1 && !str_ends_with($module, $folder) ? $module . '/…/' . $folder : $module;
    }

    /**
     * The first two folders below the common directory: `SalesOrder/CustomerOrder`.
     */
    private function module(?string $file): string
    {
        $relative = $file !== null && $this->prefix !== '' && str_starts_with($file, $this->prefix) ? substr($file, \strlen($this->prefix)) : (string) $file;

        return implode('/', \array_slice(explode('/', \dirname($relative)), 0, 2));
    }

    /**
     * @param list<string> $chain from the route's handler down to the change
     */
    private function chain(array $chain): string
    {
        $graph = $this->query->graph();
        $parts = [];
        foreach ($chain as $index => $node) {
            if ($node === $this->changed->id && $index > 0) {
                break;
            }
            $label = $this->target($node, '');
            $next = $chain[$index + 1] ?? null;
            if ($next !== null) {
                foreach ($graph->incident(explode('::', $node)[0]) as $item) {
                    if ($item['forward'] && $item['other'] === explode('::', $next)[0] && $item['edge']->relation === Relation::Receives && $item['edge']->via() !== '') {
                        $label .= ' (' . $item['edge']->via() . ')';
                        break;
                    }
                }
                foreach ($graph->incident($node) as $item) {
                    if ($item['forward'] && $item['other'] === $next && $item['edge']->relation === Relation::ReadsStateOf) {
                        $label .= ' (reads it)';
                        break;
                    }
                }
            }
            if ($parts === [] || end($parts) !== $label) {
                $parts[] = $label;
            }
        }

        return implode(' ← ', $parts);
    }

    /**
     * The application classes listed as dependents that no test touches: no test class instantiates, calls or
     * references it or one of its methods, anywhere in the project. A change there runs untested.
     *
     * @return list<string>
     */
    private function uncovered(): array
    {
        $usage = new Usage($this->query->graph());
        $uncovered = [];
        foreach ($this->groups['direct'] as $class) {
            if ($class->followed && $this->query->graph()->node($class->class)?->kind === NodeKind::PhpClass && !$usage->touchedByTests($class->class)) {
                $uncovered[] = $this->name($class->class);
            }
        }

        return $uncovered;
    }

    /**
     * The application classes on the way that nothing outside tests uses: no other class of the application calls,
     * instantiates, references, receives or extends it, nor its methods. Only a class standing alone counts: one
     * implementing an interface or extending a class may be reached through that type, wired by the framework.
     *
     * @return array<string, bool> name => whether a test uses it
     */
    private function unused(): array
    {
        $usage = new Usage($this->query->graph());
        $unused = [];
        foreach ([...$this->groups['direct'], ...$this->groups['state'], ...$this->groups['quietState']] as $class) {
            [$unusedByApplication, $tested] = $usage->unused($class->class) ?? [false, false];
            if ($unusedByApplication) {
                $unused[$this->name($class->class)] = $tested;
            }
        }

        return $unused;
    }

    /**
     * Methods of the test helpers on the way that nothing calls: a faker method no test uses any more.
     *
     * @return list<string>
     */
    private function unusedHelperMethods(): array
    {
        $graph = $this->query->graph();
        $unused = [];
        foreach ($this->groups['helpers'] as $helper) {
            foreach ($helper->sites as $edge) {
                if (!str_contains($edge->source, '::') || isset($unused[$edge->source])) {
                    continue;
                }
                $called = false;
                foreach ($graph->incident($edge->source) as $item) {
                    if (!$item['forward'] && $item['edge']->relation === Relation::Calls) {
                        $called = true;
                        break;
                    }
                }
                if (!$called) {
                    $unused[$edge->source] = $this->query->label($edge->source);
                }
            }
        }

        return array_values($unused);
    }

    private function isTestCase(string $class): bool
    {
        return preg_match('/(Test|TestCase|Cest|Spec|Context|Feature)$/', $this->query->label($class)) === 1;
    }

    /**
     * A method reading what the changed one writes: `all()` for `addContextViolation()`.
     */
    private function readsTheChange(string $method): bool
    {
        foreach ($this->query->graph()->incident($method) as $item) {
            if ($item['forward'] && $item['other'] === $this->changed->id && $item['edge']->relation === Relation::ReadsStateOf) {
                return true;
            }
        }

        return false;
    }

    /**
     * A method of the changed class or of the class itself by its name only: `→ notifyContextViolation()`.
     */
    private function target(string $node, string $class): string
    {
        $owner = explode('::', $node)[0];
        if (str_contains($node, '::') && ($owner === $class || $owner === explode('::', $this->changed->id)[0])) {
            return substr($node, (int) strrpos($node, ':') + 1) . '()';
        }

        return $this->short($node);
    }

    private function short(string $node): string
    {
        return rtrim($this->query->label($node), '()') . (str_contains($node, '::') ? '()' : '');
    }

    private function file(string $class): ?string
    {
        return $this->query->graph()->node($class)?->file;
    }

    private function location(Node $node, bool $compact = true): string
    {
        if ($node->file === null) {
            return '(external)';
        }

        return ($compact ? $this->compact($node->file) : $node->file) . ($node->line === null ? '' : ' L' . $node->line);
    }

    /**
     * Below the directory all listed files share, the first two folders and the file name:
     * `SalesOrder/CustomerOrder/…/PaymentsModeRule.php`.
     */
    private function compact(?string $file): string
    {
        if ($file === null) {
            return '(external)';
        }
        $relative = $this->prefix !== '' && str_starts_with($file, $this->prefix) ? substr($file, \strlen($this->prefix)) : $file;
        $segments = explode('/', $relative);

        return \count($segments) <= 4 ? $relative : implode('/', [...\array_slice($segments, 0, 2), '…', $segments[\count($segments) - 1]]);
    }

    /**
     * @param list<string> $files
     */
    private function commonDirectory(array $files): string
    {
        if ($files === []) {
            return '';
        }
        $common = explode('/', \dirname($files[0]));
        foreach ($files as $file) {
            $segments = explode('/', \dirname($file));
            $length = 0;
            while ($length < \count($common) && $length < \count($segments) && $common[$length] === $segments[$length]) {
                ++$length;
            }
            $common = \array_slice($common, 0, $length);
        }

        return $common === [] || $common === ['.'] ? '' : implode('/', $common) . '/';
    }
}
