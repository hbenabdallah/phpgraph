<?php

declare(strict_types=1);

namespace PhpGraph\Query;

use PhpGraph\Builder\TestFiles;
use PhpGraph\Extractor\ClassFacts;
use PhpGraph\Extractor\Hint;
use PhpGraph\Extractor\SourceFacts;
use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Graph;
use PhpGraph\Graph\NodeKind;
use PhpGraph\Graph\Relation;
use PhpGraph\Query\Result\Outline;
use PhpParser\Node as AstNode;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * Builds the outline of a feature from the classes a question names: the cluster of classes it is made of
 * (FeatureCluster), what their sources declare (SourceFacts, read on demand), and from the graph how the application
 * runs into it, its wiring, its users and its tests.
 */
final class FeatureOutline
{
    /**
     * A method of the core called by more classes than this is a hub of the feature (Notification::hasErrors), not
     * an entry into it: its callers are counted, not followed to the routes.
     */
    private const ENTRY_CALLERS = 8;

    private const ROUTE_ENTRIES = 4;

    private const MORE_ENTRIES = 12;

    private readonly Graph $graph;

    /** @var array<string, array<string, ClassFacts>|null> file => its classes' facts, null when unreadable */
    private array $sources = [];

    /** @var array<string, array<AstNode>|null> */
    private array $parsed = [];

    public function __construct(
        private readonly GraphQuery $query,
        private readonly Relevance $relevance,
        private readonly ?string $root,
    ) {
        $this->graph = $query->graph();
    }

    public function build(string $topic): ?Outline
    {
        $subgraph = $this->query->subgraph($topic, 1, 10, false);
        $seeds = [];
        foreach ($subgraph->seeds as $seed) {
            $class = explode('::', $seed->id)[0];
            $node = $this->graph->node($class);
            if ($node !== null && $node->kind->isClassLike() && $node->file !== null) {
                $seeds[$class] = true;
            }
        }
        if ($seeds === []) {
            return null;
        }
        $seeds = array_map('strval', array_keys($seeds));

        $cluster = new FeatureCluster($this->graph, $this->relevance);
        ['core' => $core, 'families' => $families] = $cluster->grow($seeds, $subgraph->terms);
        $inCore = array_fill_keys($core, true);
        // The members of an interface family are the family's: counted with it, not as users of the core.
        $members = [];
        foreach ($families as $head => $found) {
            if ($this->graph->node($head)?->kind === NodeKind::PhpInterface) {
                foreach ($found as $member) {
                    $members[$member] = $head;
                }
            }
        }

        $facts = [];
        $sourcesRead = true;
        foreach ($core as $class) {
            $found = $this->facts($class);
            $sourcesRead = $sourcesRead && $found !== null;
            if ($found !== null) {
                $facts[$class] = $found;
            }
        }

        [$entries, $closures, $inside, $fromFamilies] = $this->calls($core, $inCore, $members);
        [$routes, $routeEntries] = $this->routes($entries, $inside, $members);
        // The entries followed to the routes first, then those called by the most classes.
        uksort($entries, fn (string $a, string $b): int => [!\in_array($a, $routeEntries, true), -\count($this->classesOf($entries[$a]))]
            <=> [!\in_array($b, $routeEntries, true), -\count($this->classesOf($entries[$b]))]);
        // The methods calling the most other classes of the core first: the ones running it.
        uasort($inside, fn (array $a, array $b): int => \count($this->classesOf($b)) <=> \count($this->classesOf($a)));

        $hints = [];
        foreach ($facts as $class => $found) {
            foreach ($found->hints as $hint) {
                $hints[] = [$class, $hint];
            }
        }
        // What the methods running into the core check: an aggregate's create() returning null on errors.
        $callers = [];
        foreach ($routeEntries as $entry) {
            foreach ($entries[$entry] ?? [] as $caller) {
                $callers[$caller] = true;
            }
        }
        // Only what they check of the core: `returns null if $notification->hasErrors()`, not their own guards.
        $names = [];
        foreach ($core as $class) {
            $names[] = preg_quote($this->query->label($class), '/');
            foreach ($this->methods($class) as $method) {
                $names[] = '(->|::)' . preg_quote(substr($method, (int) strrpos($method, ':') + 1), '/') . '\(';
            }
        }
        $mentionsCore = '/' . implode('|', array_unique($names)) . '/';
        foreach (array_keys($callers) as $caller) {
            [$class, $method] = explode('::', (string) $caller, 2) + [1 => ''];
            foreach ($this->facts($class)->hints ?? [] as $hint) {
                if (strcasecmp($hint->method, $method) === 0 && preg_match($mentionsCore, $hint->text) === 1) {
                    $hints[] = [$class, $hint];
                }
            }
        }

        // What a reader cannot guess first: a guarded throw, then what the code running into the core checks of it
        // (`create()` returns null on errors), a comparison, a branch on constants, an early return naming its
        // outcome; type assertions, unguarded throws, boolean searches and constructor checks last.
        $outside = array_flip(array_keys($callers));
        $rank = static function (string $class, Hint $hint) use ($outside): int {
            $text = $hint->text;
            $isCaller = isset($outside[$class . '::' . $hint->method]);

            return match (true) {
                strtolower($hint->method) === '__construct' => 6,
                str_starts_with($text, 'throws') && (str_contains($text, 'instanceof') || preg_match('/ (if|unless) /', $text) !== 1) => 4,
                str_starts_with($text, 'throws') => $isCaller ? 4 : 0,
                $isCaller => preg_match('/^returns (null|false|early) /', $text) === 1 ? 1 : 4,
                !str_starts_with($text, 'returns') => 1,
                preg_match('/^returns [\\w\\\\]+::\\w+\\(\\) /', $text) === 1 => 2,
                preg_match('/^returns (true|false) /', $text) === 1 => 5,
                default => 3,
            };
        };
        uksort($hints, static fn (int $a, int $b): int => [$rank(...$hints[$a]), $a] <=> [$rank(...$hints[$b]), $b]);
        $hints = array_values($hints);

        [$consumers, $consumerFiles, $tests, $topTests] = $this->users($core, $inCore, $members);

        $usage = new Usage($this->graph);
        $onTheWay = $core;
        foreach ($routes as $route) {
            foreach ($route['chain'] as $step) {
                $onTheWay[] = explode('::', $step)[0];
            }
        }
        $uncovered = [];
        foreach (array_unique($onTheWay) as $class) {
            if ($this->graph->node($class)?->kind === NodeKind::PhpClass && !$usage->touchedByTests($class)) {
                $uncovered[] = $class;
            }
        }
        $unused = [];
        foreach ($core as $class) {
            [$isUnused, $tested] = $usage->unused($class) ?? [false, false];
            if ($isUnused) {
                $unused[$class] = $tested;
            }
        }

        return new Outline(
            $topic,
            $seeds,
            $core,
            $facts,
            $sourcesRead,
            $this->families($families, $cluster),
            $routes,
            $entries,
            $fromFamilies,
            $closures,
            $inside,
            $hints,
            $this->wiring($core),
            $consumers,
            $consumerFiles,
            $tests,
            $topTests,
            $uncovered,
            $unused,
        );
    }

    /**
     * The calls crossing the core's border and inside it: the methods outside calling into it (the entries), the
     * closures its methods run, and its methods calling other classes of the core.
     *
     * @param list<string>          $core
     * @param array<string, true>   $inCore
     * @param array<string, string> $members
     *
     * @return array{array<string, list<string>>, array<string, array<string, list<string>>>, array<string, list<string>>, array<string, array<string, list<string>>>}
     */
    private function calls(array $core, array $inCore, array $members): array
    {
        $entries = $closures = $inside = $fromFamilies = [];
        foreach ($core as $class) {
            foreach ($this->methods($class) as $method) {
                foreach ($this->graph->incident($method) as $item) {
                    $edge = $item['edge'];
                    if ($edge->relation !== Relation::Calls) {
                        continue;
                    }
                    $other = $item['other'];
                    $owner = explode('::', $other)[0];
                    if (str_starts_with($edge->via(), 'closure of ')) {
                        if ($item['forward']) {
                            foreach (explode(', ', substr($edge->via(), 11)) as $source) {
                                $closures[$method][$source][] = $other;
                            }
                        }
                        continue;
                    }
                    if ($item['forward']) {
                        if (isset($inCore[$owner]) && $owner !== $class) {
                            $inside[$method][] = $other;
                        }
                        continue;
                    }
                    $node = $this->graph->node($owner);
                    if (isset($inCore[$owner]) || $node === null || !$node->kind->isClassLike() || $node->file === null || TestFiles::isTest($node->file)) {
                        continue;
                    }
                    // A family member stands in its family: kept apart, not followed to the routes.
                    if (isset($members[$owner])) {
                        $fromFamilies[$method][$members[$owner]][] = $other;
                    } else {
                        $entries[$method][] = $other;
                    }
                }
            }
        }

        return [$entries, $closures, $inside, $fromFamilies];
    }

    /**
     * The routes running into the core, each with its chain down to the entry it reaches: from the entries called
     * by few classes, those reaching the most of the core first.
     *
     * @param array<string, list<string>> $entries
     * @param array<string, list<string>> $inside
     * @param array<string, string>       $members the members of the interface families: one rule among 34 on a
     *                                             chain is an arbitrary pick, a chain without one is preferred
     *
     * @return array{list<array{route: string, chain: list<string>}>, list<string>} the routes, the entries followed
     */
    private function routes(array $entries, array $inside, array $members): array
    {
        $ranked = [];
        foreach ($entries as $method => $callers) {
            $classes = \count($this->classesOf($callers));
            if ($classes <= self::ENTRY_CALLERS) {
                $ranked[$method] = [\count($this->reach($method, $inside)), $classes];
            }
        }
        uasort($ranked, static fn (array $a, array $b): int => $b <=> $a);
        // The entries reaching most of the core give the routes: a validator used in passing by every bulk use case
        // is not the flow. The other entries called by few classes only lengthen the chains of those routes (the
        // pipeline's use case → its builder → the aggregate → the validators).
        $best = (int) (reset($ranked)[0] ?? 0);
        $main = array_keys(array_filter($ranked, static fn (array $rank): bool => $rank[0] >= max(1, $best / 2)));
        $main = array_map('strval', \array_slice($main, 0, self::ROUTE_ENTRIES));
        $others = array_map('strval', \array_slice(array_diff(array_keys($ranked), $main), 0, self::MORE_ENTRIES));

        // An extra entry lengthens a route through the closures a main entry runs: the use case running the
        // pipeline builds the aggregate in the closure it passes, and the aggregate runs the validators.
        $ranInClosures = [];
        foreach ($main as $method) {
            foreach ($this->graph->incident($method) as $item) {
                if ($item['forward'] && str_starts_with($item['edge']->via(), 'closure of ')) {
                    $ranInClosures[$item['other']] = true;
                    foreach ($this->graph->incident($item['other']) as $implementation) {
                        if (!$implementation['forward'] && $implementation['edge']->relation === Relation::Overrides) {
                            $ranInClosures[$implementation['other']] = true;
                        }
                    }
                }
            }
        }
        $routes = [];
        $followed = [];
        foreach ([...$main, ...$others] as $method) {
            $node = $this->graph->node($method);
            if ($node === null) {
                continue;
            }
            $isMain = \in_array($method, $main, true);
            foreach ($this->query->impactOf($node)->classes as $impacted) {
                if (!str_starts_with($impacted->class, 'route:') || $impacted->chain === []
                    || (!$isMain && (!isset($routes[$impacted->class]) || array_intersect_key(array_flip($impacted->chain), $ranInClosures) === []))) {
                    continue;
                }
                $score = fn (array $chain): array => [
                    array_filter($chain, fn (mixed $step): bool => \is_string($step) && $this->dispatched($step, $members)) === [],
                    \count($chain),
                ];
                if (!isset($routes[$impacted->class]) || $score($impacted->chain) > $score($routes[$impacted->class])) {
                    $routes[$impacted->class] = $impacted->chain;
                    $followed[$method] = true;
                }
            }
        }
        $followed = array_map('strval', array_keys($followed));
        ksort($routes);

        return [array_map(
            static fn (string $route, array $chain): array => ['route' => $route, 'chain' => $chain],
            array_map('strval', array_keys($routes)),
            array_values($routes),
        ), $followed];
    }

    /**
     * A method of a family member run through the family's interface: one rule's apply() among 34.
     *
     * @param array<string, string> $members
     */
    private function dispatched(string $method, array $members): bool
    {
        $head = $members[explode('::', $method)[0]] ?? null;
        if ($head === null) {
            return false;
        }
        foreach ($this->graph->incident($method) as $item) {
            if ($item['forward'] && $item['edge']->relation === Relation::Overrides && explode('::', $item['other'])[0] === $head) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, list<string>> $inside
     *
     * @return array<string, true> the classes of the core a method reaches through it
     */
    private function reach(string $method, array $inside): array
    {
        $reached = [];
        for ($queue = [$method]; $queue !== [];) {
            $current = (string) array_shift($queue);
            foreach ($inside[$current] ?? [] as $next) {
                $class = explode('::', $next)[0];
                if (!isset($reached[$class])) {
                    $reached[$class] = true;
                    $queue[] = $next;
                }
            }
        }

        return $reached;
    }

    /**
     * @param array<string, list<string>> $families
     *
     * @return list<array{head: string, members: int, directories: array<string, int>, receivers: list<string>}>
     */
    private function families(array $families, FeatureCluster $cluster): array
    {
        $result = [];
        foreach ($families as $head => $found) {
            $directories = [];
            foreach ($found as $member) {
                $file = $this->graph->node($member)?->file;
                if ($file !== null) {
                    $directories[\dirname($file)] = ($directories[\dirname($file)] ?? 0) + 1;
                }
            }
            arsort($directories);

            // Who receives the family from the container, and under which tags.
            $tags = [];
            foreach ($found as $member) {
                foreach ($this->graph->incident($member) as $item) {
                    $edge = $item['edge'];
                    if (!$item['forward'] && $edge->relation === Relation::Receives && $edge->confidence === Confidence::Extracted
                        && str_starts_with($edge->via(), 'tagged_')) {
                        [$kind, $tag] = explode(' ', $edge->via(), 2) + [1 => ''];
                        $tags[$item['other'] . ' (' . $kind][$tag] = true;
                    }
                }
            }
            $receivers = [];
            foreach ($tags as $receiver => $names) {
                $receivers[] = $receiver . ' ' . self::pattern(array_map('strval', array_keys($names))) . ')';
            }

            $result[] = ['head' => $head, 'members' => \count($found), 'directories' => $directories, 'receivers' => $receivers];
        }
        usort($result, static fn (array $a, array $b): int => [$b['members'] > 0, $b['members']] <=> [$a['members'] > 0, $a['members']]);

        return $result;
    }

    /**
     * `sales.order.rule` and `sales.quote.rule` as `sales.{order,quote}.rule`: the tags of one family in one line.
     *
     * @param list<string> $names
     */
    public static function pattern(array $names): string
    {
        sort($names);
        if (\count($names) < 2) {
            return $names[0] ?? '';
        }
        $split = array_map(static fn (string $name): array => explode('.', $name), $names);
        $prefix = [];
        foreach ($split[0] as $index => $part) {
            foreach ($split as $parts) {
                if (($parts[$index] ?? null) !== $part) {
                    break 2;
                }
            }
            $prefix[] = $part;
        }
        $suffix = [];
        $reversed = array_map('array_reverse', $split);
        foreach ($reversed[0] as $index => $part) {
            foreach ($reversed as $parts) {
                if (($parts[$index] ?? null) !== $part || \count($parts) - $index <= \count($prefix)) {
                    break 2;
                }
            }
            array_unshift($suffix, $part);
        }
        $middles = array_map(
            static fn (array $parts): string => implode('.', \array_slice($parts, \count($prefix), \count($parts) - \count($prefix) - \count($suffix))),
            $split,
        );

        return implode('.', [...$prefix, '{' . implode(',', array_unique($middles)) . '}', ...$suffix]);
    }

    /**
     * The users of the core outside it: application classes and their links, files, tests by module and the tests
     * touching the most of it.
     *
     * @param list<string>          $core
     * @param array<string, true>   $inCore
     * @param array<string, string> $members
     *
     * @return array{array<string, int>, int, array<string, int>, list<string>}
     */
    private function users(array $core, array $inCore, array $members): array
    {
        $consumers = $files = $tests = $touched = [];
        foreach ($core as $class) {
            foreach ([$class, ...$this->methods($class)] as $node) {
                foreach ($this->graph->incident($node) as $item) {
                    if ($item['forward'] || \in_array($item['edge']->relation, [Relation::Imports, Relation::Defines, Relation::HasMethod], true)) {
                        continue;
                    }
                    $owner = explode('::', $item['other'])[0];
                    $other = $this->graph->node($owner);
                    if (isset($inCore[$owner]) || $other === null || !$other->kind->isClassLike() || $other->file === null) {
                        continue;
                    }
                    $files[$other->file] = true;
                    if (TestFiles::isTest($other->file)) {
                        $tests[TestFiles::module($other->file)][$other->file] = true;
                        $touched[$other->file][$class] = true;
                        continue;
                    }
                    if (!isset($members[$owner])) {
                        $consumers[$owner] = ($consumers[$owner] ?? 0) + 1;
                    }
                }
            }
        }
        arsort($consumers);
        $byModule = array_map('count', $tests);
        arsort($byModule);
        // The test cases touching the most of it, not their helpers.
        $touched = array_filter($touched, static fn (string $file): bool => preg_match('/(Test|Spec|Cest)\.php$/', $file) === 1, \ARRAY_FILTER_USE_KEY);
        uasort($touched, static fn (array $a, array $b): int => \count($b) <=> \count($a));

        return [$consumers, \count($files), $byModule, \array_slice(array_map(static fn (string $file): string => basename($file, '.php'), array_map('strval', array_keys($touched))), 0, 5)];
    }

    /**
     * The configuration calling the core (a wiring helper), each call as written with its literal arguments, and
     * the services it defines that the container injects by id: which use case receives which pipeline.
     *
     * @param list<string> $core
     *
     * @return list<array{file: string, line: int, call: string, services: list<array{id: string, class: string, consumers: list<string>}>}>
     */
    private function wiring(array $core): array
    {
        $services = [];
        $calls = [];
        foreach ($core as $class) {
            foreach ($this->graph->incident($class) as $item) {
                $edge = $item['edge'];
                if (!$item['forward'] && $edge->relation === Relation::Receives && str_starts_with($edge->via(), 'service ')) {
                    $id = substr($edge->via(), 8);
                    $services[$id]['class'] = $class;
                    $services[$id]['consumers'][] = $item['other'];
                }
            }
            foreach ($this->methods($class) as $method) {
                foreach ($this->graph->incident($method) as $item) {
                    $caller = $this->graph->node($item['other']);
                    if ($item['forward'] || $item['edge']->relation !== Relation::Calls || $caller?->kind !== NodeKind::File) {
                        continue;
                    }
                    foreach ($item['edge']->lines() as $line) {
                        $calls[] = [(string) $caller->file, $line, $method];
                    }
                }
            }
        }
        ksort($services);

        $wiring = [];
        $placed = [];
        foreach ($calls as [$file, $line, $method]) {
            [$text, $literals] = $this->callAt($file, $line, $method);
            $defined = [];
            foreach ($services as $id => $service) {
                foreach ($literals as $literal) {
                    if (str_starts_with((string) $id, $literal . '.')) {
                        $defined[] = ['id' => (string) $id, 'class' => $service['class'], 'consumers' => $service['consumers']];
                        $placed[$id] = true;
                        break;
                    }
                }
            }
            $wiring[] = ['file' => $file, 'line' => $line, 'call' => $text, 'services' => $defined];
        }
        usort($wiring, static fn (array $a, array $b): int => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

        $rest = [];
        foreach ($services as $id => $service) {
            if (!isset($placed[$id])) {
                $rest[] = ['id' => (string) $id, 'class' => $service['class'], 'consumers' => $service['consumers']];
            }
        }
        if ($rest !== []) {
            $wiring[] = ['file' => '', 'line' => 0, 'call' => '', 'services' => $rest];
        }

        return $wiring;
    }

    /**
     * A call as written at a line of a file, its literal arguments kept and the others elided:
     * `BoundingContextValidationWiring::wire($services, 'sales_order.customer_order', …)`.
     *
     * @return array{string, list<string>} the call, its literal string arguments
     */
    private function callAt(string $file, int $line, string $method): array
    {
        $name = substr($method, (int) strrpos($method, ':') + 1);
        $short = $this->query->label(explode('::', $method)[0]);
        $fallback = [$short . '::' . $name . '()', []];
        $statements = $this->parse($file);
        if ($statements === null) {
            return $fallback;
        }
        foreach ((new NodeFinder())->find($statements, static fn (AstNode $node): bool => ($node instanceof Expr\StaticCall || $node instanceof Expr\MethodCall)
            && $node->getStartLine() === $line && $node->name instanceof AstNode\Identifier && strcasecmp($node->name->toString(), $name) === 0) as $call) {
            \assert($call instanceof Expr\StaticCall || $call instanceof Expr\MethodCall);
            $arguments = [];
            $literals = [];
            foreach ($call->args as $arg) {
                $value = $arg instanceof AstNode\Arg ? $arg->value : null;
                if ($value instanceof Scalar\String_) {
                    $arguments[] = "'" . $value->value . "'";
                    $literals[] = $value->value;
                } elseif ($value instanceof Expr\Variable && \is_string($value->name)) {
                    $arguments[] = '$' . $value->name;
                } else {
                    $arguments[] = '…';
                }
            }
            $class = $call instanceof Expr\StaticCall && $call->class instanceof Name ? $call->class->getLast() : $short;

            return [$class . '::' . $name . '(' . implode(', ', $arguments) . ')', $literals];
        }

        return $fallback;
    }

    private function facts(string $class): ?ClassFacts
    {
        $file = $this->graph->node($class)?->file;
        if ($file === null || $this->root === null) {
            return null;
        }
        if (!\array_key_exists($file, $this->sources)) {
            $code = @file_get_contents($this->root . '/' . $file);
            $this->sources[$file] = $code === false ? null : (new SourceFacts())->read($code);
        }
        $id = str_contains($class, '@') ? substr($class, (int) strpos($class, '@') + 1) : $class;

        return $this->sources[$file][$id] ?? null;
    }

    /**
     * @return array<AstNode>|null
     */
    private function parse(string $file): ?array
    {
        if (!\array_key_exists($file, $this->parsed)) {
            $code = $this->root === null ? false : @file_get_contents($this->root . '/' . $file);
            try {
                $this->parsed[$file] = $code === false ? null : (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
            } catch (\PhpParser\Error) {
                $this->parsed[$file] = null;
            }
        }

        return $this->parsed[$file];
    }

    /**
     * @return list<string>
     */
    private function methods(string $class): array
    {
        $methods = [];
        foreach ($this->graph->incident($class) as $item) {
            if ($item['forward'] && $item['edge']->relation === Relation::HasMethod) {
                $methods[] = $item['other'];
            }
        }

        return $methods;
    }

    /**
     * @param list<string> $methods
     *
     * @return array<string, true>
     */
    private function classesOf(array $methods): array
    {
        $classes = [];
        foreach ($methods as $method) {
            $classes[explode('::', $method)[0]] = true;
        }

        return $classes;
    }
}
