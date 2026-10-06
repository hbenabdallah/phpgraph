<?php

declare(strict_types=1);

namespace PhpGraph\Query;

use PhpGraph\Builder\TestFiles;
use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Graph;
use PhpGraph\Graph\Node;
use PhpGraph\Graph\NodeKind;
use PhpGraph\Graph\Relation;
use PhpGraph\Project\ProjectSummary;
use PhpGraph\Query\Result\Architecture;
use PhpGraph\Query\Result\Connection;
use PhpGraph\Query\Result\Impact;
use PhpGraph\Query\Result\NamespaceGroup;
use PhpGraph\Query\Result\Outline;
use PhpGraph\Query\Result\Overview;
use PhpGraph\Query\Result\Path;
use PhpGraph\Query\Result\PathMode;
use PhpGraph\Query\Result\RankedNode;
use PhpGraph\Query\Result\RouteEntry;
use PhpGraph\Query\Result\Subgraph;

/**
 * Read-only queries over a graph. Returns structures; rendering belongs to the presentation layer.
 */
final class GraphQuery
{
    private const STOPWORDS = [
        'the', 'and', 'for', 'with', 'what', 'which', 'how', 'does', 'show', 'from', 'into', 'that', 'this',
        'les', 'des', 'une', 'quel', 'quels', 'quelle', 'quelles', 'comment', 'dans', 'pour', 'avec', 'est', 'sont',
    ];

    /**
     * Words of a question about routes: query_graph then answers with the route nodes.
     */
    private const ROUTE_WORDS = ['route', 'routes', 'routing', 'endpoint', 'endpoints', 'http', 'url', 'urls', 'uri'];

    private const HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'];

    /**
     * Relations that a dependency path may walk backwards: from an abstraction to what implements it,
     * and from a method to the class that owns it.
     */
    private const REVERSIBLE_IN_DEPENDENCY_PATH = [
        Relation::Implements,
        Relation::Extends,
        Relation::Overrides,
        Relation::HasMethod,
    ];

    /**
     * Class name suffixes worth counting: they hint at the building blocks and the entry points.
     */
    private const SUFFIXES = [
        'Controller', 'Action', 'Command', 'Query', 'Handler', 'Event', 'Listener', 'Subscriber', 'Message',
        'Repository', 'Factory', 'Service', 'Provider', 'Exception', 'Interface', 'Dto', 'DTO', 'Type', 'Voter',
        'Middleware', 'Job', 'Policy', 'Request', 'Resource', 'Response', 'Validator', 'Normalizer', 'Transformer',
    ];

    private ?Architecture $architecture = null;

    private ?Relevance $relevance = null;

    private const SEEDS = 6;

    /**
     * A namespace with more classes than this is a layer, not a module: its classes are not added as siblings.
     */
    private const MODULE = 60;

    /**
     * Above this many relations, a neighbour unrelated to the question is a hub (Notification, Request): left out.
     */
    private const HUB = 150;

    /**
     * How telling a relation is when expanding around the seeds.
     */
    private const EXPAND_WEIGHTS = [
        'handled_by' => 3, 'calls' => 3, 'dispatches' => 3, 'receives' => 3, 'requests' => 3, 'reads_state_of' => 2,
        'implements' => 2, 'extends' => 2, 'overrides' => 2, 'has_method' => 2, 'instantiates' => 1, 'references' => 1,
    ];

    /**
     * Methods shown with their class even when their name says nothing of the question: they run it.
     */
    private const ENTRY_POINTS = ['__invoke', 'handle', 'execute', 'process', 'provide', 'run', 'apply', 'resolve', 'validate', 'supports'];

    /**
     * Words of a question that name no code: how, why, work...
     */
    private const QUESTION_WORDS = [
        'how', 'why', 'where', 'when', 'who', 'what', 'which', 'does', 'doe', 'work', 'works', 'working', 'happen',
        'before', 'after', 'during', 'about', 'there', 'their', 'between', 'into', 'are', 'can', 'should', 'would',
        'could', 'get', 'used', 'use', 'explain', 'describe', 'tell', 'show', 'give', 'please', 'help', 'understand',
        'overview', 'system', 'systems', 'flow', 'flows', 'logic', 'mechanism', 'architecture', 'part', 'parts',
        'feature', 'features', 'module', 'modules', 'code', 'project', 'this', 'that', 'these', 'those', 'you', 'our',
    ];

    /**
     * @param string|null $root the project's directory, to read the sources an outline shows
     */
    public function __construct(
        private readonly Graph $graph,
        private readonly ?ProjectSummary $summary = null,
        private readonly ?string $root = null,
    ) {
    }

    /**
     * The structural outline of the feature a question names: its classes and what they declare, the families
     * around them, how the application runs into it, its wiring, its users and tests. Null when nothing matches.
     */
    public function outline(string $topic): ?Outline
    {
        return (new FeatureOutline($this, $this->relevance(), $this->root))->build($topic);
    }

    public function overview(): Overview
    {
        $nodesByKind = [];
        $application = [];
        $tests = 0;
        foreach ($this->graph->nodes() as $node) {
            $nodesByKind[$node->kind->value] = ($nodesByKind[$node->kind->value] ?? 0) + 1;
            if (!$node->kind->isClassLike() || $node->kind === NodeKind::External) {
                continue;
            }
            if ($node->file !== null && TestFiles::isTest($node->file)) {
                ++$tests;
            } else {
                $application[] = $node;
            }
        }
        ksort($nodesByKind);

        $namespaces = [];
        $global = 0;
        $layers = [];
        $suffixes = [];
        foreach ($application as $node) {
            // In a multi-service repository, the service is the first level of the tree: billing@App\Domain\Order.
            $at = strpos($node->id, '@');
            $segments = explode('\\', $at === false ? $node->id : substr($node->id, $at + 1));
            $short = array_pop($segments);
            if ($at !== false) {
                array_unshift($segments, substr($node->id, 0, $at));
            }
            if ($segments === []) {
                ++$global;
            } else {
                $namespaces[] = $segments;
            }
            foreach ($this->layerSegments($segments) as $segment => $role) {
                $layers[$role][$segment] = ($layers[$role][$segment] ?? 0) + 1;
            }
            foreach (self::SUFFIXES as $suffix) {
                if (str_ends_with($short, $suffix) && $short !== $suffix) {
                    $suffixes[$suffix] = ($suffixes[$suffix] ?? 0) + 1;
                    break;
                }
            }
        }
        foreach ($layers as &$segments) {
            arsort($segments);
        }
        unset($segments);
        arsort($suffixes);

        $root = [];
        while ($namespaces !== [] && $global === 0) {
            $first = $namespaces[0][\count($root)] ?? null;
            foreach ($namespaces as $segments) {
                if (!isset($segments[\count($root)]) || $segments[\count($root)] !== $first || \count($segments) === \count($root) + 1) {
                    break 2;
                }
            }
            $root[] = (string) $first;
        }

        return new Overview(
            $this->summary,
            $nodesByKind,
            \count($application),
            $tests,
            $global,
            implode('\\', $root),
            $this->groups($namespaces, \count($root), 3),
            $layers,
            $suffixes,
            $this->routes(),
        );
    }

    /**
     * Every route node with its controller, by path then method.
     *
     * @return list<RouteEntry>
     */
    public function routes(): array
    {
        $routes = [];
        foreach ($this->graph->nodes() as $node) {
            if ($node->kind !== NodeKind::Route) {
                continue;
            }
            $handler = null;
            foreach ($this->graph->incident($node->id) as $item) {
                if ($item['forward'] && $item['edge']->relation === Relation::HandledBy) {
                    $handler = $this->graph->node($item['other']);
                    break;
                }
            }
            // The label reads "GET|POST /orders/{id}".
            [$methods, $path] = array_pad(explode(' ', $node->label, 2), 2, '');
            $routes[] = new RouteEntry($node, explode('|', $methods), $path, $handler);
        }
        usort($routes, static fn (RouteEntry $a, RouteEntry $b): int => [$a->path, $a->route->label, $a->route->id] <=> [$b->path, $b->route->label, $b->route->id]);

        return $routes;
    }

    public function summary(): ?ProjectSummary
    {
        return $this->summary;
    }

    public function graph(): Graph
    {
        return $this->graph;
    }

    /**
     * The guard a class of $holders holds $member despite, unable to tell whether it runs for what that class passes
     * (`supports`), or null: the member of a tagged list, entered from a route's chain.
     *
     * @param list<string> $holders nodes, methods or classes
     */
    public function unresolvedGuard(array $holders, string $member): ?string
    {
        foreach ($holders as $node) {
            foreach ($this->graph->incident(explode('::', $node)[0]) as $item) {
                $via = $item['edge']->via();
                if ($item['forward'] && $item['other'] === $member && $item['edge']->relation === Relation::Receives
                    && preg_match('/, (\w+)\(\) not resolved$/', $via, $match) === 1) {
                    return $match[1];
                }
            }
        }

        return null;
    }

    public function architecture(): Architecture
    {
        return $this->architecture ??= (new LayerRules($this->graph))->check();
    }

    public function impactOf(Node $changed, int $maxDepth = 3, int $limit = 200): Impact
    {
        return (new ImpactAnalysis($this->graph))->of($changed, $maxDepth, $limit);
    }

    /**
     * @param list<list<string>> $namespaces namespace segments of each application class
     *
     * @return list<NamespaceGroup>
     */
    private function groups(array $namespaces, int $depth, int $levels): array
    {
        $byName = [];
        foreach ($namespaces as $segments) {
            if (isset($segments[$depth])) {
                $byName[$segments[$depth]][] = $segments;
            }
        }
        uasort($byName, static fn (array $a, array $b): int => \count($b) <=> \count($a));

        $groups = [];
        foreach ($byName as $name => $members) {
            // A namespace whose classes all sit in one sub-namespace reads as one: PrestaShop\PrestaShop.
            $name = (string) $name;
            $end = $depth + 1;
            while (($next = $this->sharedSegment($members, $end)) !== null) {
                $name .= '\\' . $next;
                ++$end;
            }

            $layers = [];
            foreach ($members as $segments) {
                foreach ($this->layerSegments(\array_slice($segments, $depth)) as $segment => $role) {
                    $layers[$segment] = ($layers[$segment] ?? 0) + 1;
                }
            }
            arsort($layers);

            $groups[] = new NamespaceGroup(
                $name,
                \count($members),
                $layers,
                $levels > 1 ? $this->groups($members, $end, $levels - 1) : [],
            );
        }

        return $groups;
    }

    /**
     * The segment every namespace has at this position, when they all go deeper than it.
     *
     * @param list<list<string>> $namespaces
     */
    private function sharedSegment(array $namespaces, int $position): ?string
    {
        $shared = null;
        foreach ($namespaces as $segments) {
            $segment = $segments[$position] ?? null;
            if ($segment === null || ($shared !== null && $segment !== $shared)) {
                return null;
            }
            $shared = $segment;
        }

        return $shared;
    }

    /**
     * @param list<string> $segments
     *
     * @return array<string, string> the first layer-like segment => its role, or nothing
     */
    private function layerSegments(array $segments): array
    {
        $layer = Layers::of($segments);

        return $layer === null ? [] : [$layer['segment'] => $layer['role']];
    }

    /**
     * @return list<Node>
     */
    public function candidates(string $name, int $limit = 5): array
    {
        $needle = mb_strtolower(ltrim(trim($name), '\\'));
        if ($needle === '') {
            return [];
        }

        // billing@Order: Order in the billing service (or a service whose last directory is billing).
        $service = null;
        if (str_contains($needle, '@') && !str_contains($needle, '\\@')) {
            [$service, $needle] = explode('@', $needle, 2);
            $needle = ltrim($needle, '\\');
        }

        $exact = $this->graph->node(ltrim(trim($name), '\\'));
        $scored = [];

        foreach ($this->graph->nodes() as $node) {
            $label = mb_strtolower($node->label);
            $id = mb_strtolower($node->id);
            if ($service !== null) {
                $nodeService = mb_strtolower((string) $node->service);
                if ($nodeService !== $service && !str_ends_with($nodeService, '/' . $service)) {
                    continue;
                }
                $id = substr($id, (int) strpos($id, '@') + 1);
            }

            $tier = match (true) {
                $exact !== null && $node->id === $exact->id => 0,
                $label === $needle || rtrim($label, '()') === $needle => 1,
                $id === $needle || str_ends_with($id, '\\' . $needle) => 2,
                str_contains($label, $needle) => 3,
                str_contains($id, $needle) => 4,
                default => null,
            };

            if ($tier === null) {
                continue;
            }

            $scored[] = [$tier, $node->kind === NodeKind::External ? 1 : 0, -$this->graph->degree($node->id), $node];
        }

        usort($scored, static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        return array_map(static fn (array $row): Node => $row[3], \array_slice($scored, 0, $limit));
    }

    public function resolve(string $name): ?Node
    {
        return $this->candidates($name, 1)[0] ?? null;
    }

    /**
     * Outgoing connections first, then by relation and label of the other end.
     *
     * @return list<Connection>
     */
    public function connections(Node $node, Direction $direction = Direction::Both): array
    {
        $connections = [];
        foreach ($this->graph->incident($node->id) as $item) {
            if ($direction === Direction::Out && !$item['forward']) {
                continue;
            }
            if ($direction === Direction::In && $item['forward']) {
                continue;
            }
            $connections[] = new Connection($item['edge'], $item['other'], $item['forward']);
        }

        usort(
            $connections,
            fn (Connection $a, Connection $b): int => [$b->forward, $a->edge->relation->value, $this->label($a->other)]
                <=> [$a->forward, $b->edge->relation->value, $this->label($b->other)],
        );

        return $connections;
    }

    /**
     * The callers of the methods a method implements or overrides, up the hierarchy: a call to
     * `OrderRepository::save()` may run `DbalOrderRepository::save()`.
     *
     * @return array<string, list<Connection>> implemented method => its callers
     */
    public function callersThroughParents(Node $method): array
    {
        $callers = [];
        $seen = [$method->id => true];
        for ($queue = [$method->id]; $queue !== [];) {
            foreach ($this->graph->incident((string) array_shift($queue)) as $item) {
                if (!$item['forward'] || $item['edge']->relation !== Relation::Overrides || isset($seen[$item['other']])) {
                    continue;
                }
                $parent = $item['other'];
                $seen[$parent] = true;
                $queue[] = $parent;
                foreach ($this->graph->incident($parent) as $call) {
                    if (!$call['forward'] && $call['edge']->relation === Relation::Calls) {
                        $callers[$parent][] = new Connection($call['edge'], $call['other'], false);
                    }
                }
            }
        }

        return $callers;
    }

    /**
     * Tries the most meaningful mode first and falls back to looser ones.
     */
    public function shortestPath(Node $from, Node $to): ?Path
    {
        foreach (PathMode::cases() as $mode) {
            $hops = $this->findPath($from, $to, $mode);
            if ($hops !== null) {
                return new Path($from, $to, $hops, $mode);
            }
            // No dependency from the first to the second: maybe the other way round, which tells more than a path
            // ignoring directions.
            if ($mode === PathMode::Dependency) {
                $hops = $this->findPath($to, $from, $mode);
                if ($hops !== null) {
                    return new Path($from, $to, $hops, $mode, true);
                }
            }
        }

        return null;
    }

    /**
     * @param bool $collaborators add to the seeds the classes of the feature around them (FeatureCluster): those
     *                            of the seeds' namespace cluster about the question or working with a seed
     */
    public function subgraph(string $question, int $depth = 2, int $budget = 40, bool $collaborators = true): Subgraph
    {
        $terms = $this->terms($question);
        $routes = $this->routesAsked($question, $terms, max(1, intdiv($budget, 2)));
        $stems = $this->questionStems($question);
        if ($routes !== null) {
            return $this->expand($stems, $routes, $depth, $budget);
        }

        $wantsTests = \in_array('test', $stems, true);
        $scored = [];
        foreach ($this->graph->nodes() as $node) {
            if ($node->kind === NodeKind::File || $node->kind === NodeKind::External) {
                continue;
            }
            $score = $this->relevance()->score($node, $stems);
            if ($score <= 0) {
                continue;
            }
            // Test code answers questions about tests; otherwise it repeats the names of what it tests.
            if (!$wantsTests && $node->file !== null && TestFiles::isTest($node->file)) {
                continue;
            }
            $scored[] = [$score, $node->kind->isClassLike() ? 1 : 0, $node];
        }
        usort($scored, static fn (array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);

        // The nodes closest to the best one, a class rather than its methods.
        $seeds = [];
        $classes = [];
        $best = $scored[0][0] ?? 0.0;
        foreach ($scored as [$score, , $node]) {
            if (\count($seeds) >= self::SEEDS || $score < $best * 0.4) {
                break;
            }
            $class = explode('::', $node->id)[0];
            if (isset($classes[$class])) {
                continue;
            }
            $classes[$class] = true;
            $seeds[] = $node;
        }
        $primary = array_map('strval', array_keys($classes));
        if ($collaborators && $seeds !== []) {
            $seeds = [...$seeds, ...$this->collaborators($primary, $stems, self::SEEDS * 2 - \count($seeds))];
        }

        return $this->expand($stems, $seeds, $depth, $budget, $wantsTests, $primary);
    }

    /**
     * The classes of the feature around the seeds, in the seeds' namespace cluster (`App\Shared`): about the
     * question, or taking or returning a seed. Members of a family are left to it.
     *
     * @param list<string> $seeds
     * @param list<string> $stems
     *
     * @return list<Node>
     */
    private function collaborators(array $seeds, array $stems, int $limit): array
    {
        $cluster = new FeatureCluster($this->graph, $this->relevance());
        $prefix = static fn (string $class): string => implode('\\', \array_slice(explode('\\', $class), 0, 2));
        $areas = array_flip(array_map($prefix, $seeds));
        $found = [];
        foreach ($cluster->grow($seeds, $stems)['core'] as $class) {
            $node = $this->graph->node($class);
            if ($node === null || \in_array($class, $seeds, true) || !isset($areas[$prefix($class)]) || $node->kind === NodeKind::PhpInterface) {
                continue;
            }
            $linked = array_intersect_key($cluster->linksOf($class), array_flip($seeds)) !== [];
            $about = $this->relevance()->score($node, $stems, true) > 0;
            if ($linked || $about) {
                $found[] = [($about ? 2 : 0) + ($linked ? 1 : 0), $node];
            }
        }
        usort($found, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        return array_map(static fn (array $row): Node => $row[1], \array_slice($found, 0, max(0, $limit)));
    }

    /**
     * The injected lists among classes: who receives which family under which tags, once per family rather than
     * once per member (`ContextValidator` receives 34 `ContextRuleInterface`).
     *
     * @param list<Node> $nodes
     *
     * @return list<array{receiver: string, family: ?string, members: int, via: string}>
     */
    public function injectedLists(array $nodes): array
    {
        $cluster = new FeatureCluster($this->graph, $this->relevance());
        $lists = [];
        foreach ($nodes as $node) {
            if (!$node->kind->isClassLike()) {
                continue;
            }
            $groups = [];
            foreach ($this->graph->incident($node->id) as $item) {
                $edge = $item['edge'];
                if ($item['forward'] && $edge->relation === Relation::Receives && $edge->confidence === Confidence::Extracted && str_starts_with($edge->via(), 'tagged_')) {
                    [$kind, $tag] = explode(' ', $edge->via(), 2) + [1 => ''];
                    $family = $cluster->headOf($item['other']) ?? '';
                    $groups[$family . '|' . $kind]['members'][$item['other']] = true;
                    $groups[$family . '|' . $kind]['tags'][$tag] = true;
                }
            }
            foreach ($groups as $key => $group) {
                [$family, $kind] = explode('|', (string) $key, 2);
                $lists[] = [
                    'receiver' => $node->id,
                    'family' => $family === '' ? null : $family,
                    'members' => \count($group['members']),
                    'via' => $kind . ' ' . FeatureOutline::pattern(array_map('strval', array_keys($group['tags']))),
                ];
            }
        }

        return $lists;
    }

    /**
     * The routes a question asks for, or null when it is not about routes: it names routes, endpoints, HTTP or URLs,
     * or writes a path (/orders/{id}), with or without a method. The other terms keep the routes whose path or
     * routing file mentions most of them: "mooc courses routes" gives the /courses routes declared under mooc/.
     *
     * @param list<string> $terms
     *
     * @return list<Node>|null
     */
    private function routesAsked(string $question, array $terms, int $limit): ?array
    {
        preg_match_all('~(?<![\w/{}])/[^\s?#,;]+~u', $question, $found);
        $paths = array_map(fn (string $path): array => $this->segments($path), $found[0]);
        if ($paths === [] && array_intersect($terms, self::ROUTE_WORDS) === []) {
            return null;
        }

        $methods = array_map('strtoupper', array_values(array_intersect($terms, self::HTTP_METHODS)));
        $pathWords = array_merge(...array_map(fn (array $segments): array => $this->terms(implode(' ', $segments)), [[], ...$paths]));
        $words = array_values(array_diff($terms, self::ROUTE_WORDS, self::HTTP_METHODS, $pathWords));

        $scored = [];
        foreach ($this->routes() as $route) {
            if ($methods !== [] && !\in_array('ANY', $route->methods, true) && array_intersect($methods, $route->methods) === []) {
                continue;
            }
            $segments = $this->segments($route->path);
            if ($paths !== [] && array_filter($paths, fn (array $path): bool => $this->pathMatches($path, $segments)) === []) {
                continue;
            }
            $id = mb_strtolower($route->route->id);
            $score = \count(array_filter($words, static fn (string $word): bool => str_contains($id, $word)));
            if ($words === [] || $score > 0) {
                $scored[] = [$score, $route->route];
            }
        }
        if ($scored === []) {
            return null;
        }

        // The routes matching the most terms, in path order.
        $best = max(array_column($scored, 0));

        return \array_slice(array_values(array_map(
            static fn (array $row): Node => $row[1],
            array_filter($scored, static fn (array $row): bool => $row[0] === $best),
        )), 0, $limit);
    }

    /**
     * @return list<string>
     */
    private function segments(string $path): array
    {
        return array_values(array_filter(explode('/', mb_strtolower($path)), static fn (string $segment): bool => $segment !== ''));
    }

    /**
     * A path written in a question matches a route whose path starts with it, a placeholder matching any segment.
     *
     * @param list<string> $asked
     * @param list<string> $route
     */
    private function pathMatches(array $asked, array $route): bool
    {
        if (\count($asked) > \count($route)) {
            return false;
        }
        foreach ($asked as $index => $segment) {
            $isPlaceholder = static fn (string $part): bool => str_starts_with($part, '{') || str_starts_with($part, ':');
            if ($segment !== $route[$index] && !$isPlaceholder($route[$index]) && !$isPlaceholder($segment)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The seeds and their neighbourhood, up to $depth steps and $budget nodes.
     *
     * @param list<string>      $terms
     * @param list<Node>        $seeds
     * @param list<string>|null $primary the classes the question names, whose methods may stand on their own (the
     *                                   classes of the feature added around them stand as classes)
     */
    private function expand(array $terms, array $seeds, int $depth, int $budget, bool $withTests = true, ?array $primary = null): Subgraph
    {
        $selected = [];
        $frontier = [];
        foreach ($seeds as $seed) {
            $selected[$seed->id] = true;
            $frontier[] = $seed->id;
        }

        // A method stands for its class, unless its class is a seed: an answer about a part of the code is a list of
        // classes, not of their methods.
        $seedClasses = [];
        foreach ($seeds as $seed) {
            $seedClasses[explode('::', $seed->id)[0]] = true;
        }
        $methodsShown = $primary === null ? $seedClasses : array_fill_keys($primary, true);
        $asCandidate = function (Node $node) use ($methodsShown): Node {
            if ($node->kind !== NodeKind::Method) {
                return $node;
            }
            $class = explode('::', $node->id)[0];

            return isset($methodsShown[$class]) ? $node : ($this->graph->node($class) ?? $node);
        };

        // Each step keeps the neighbours that are about the question, then those linked by a telling relation;
        // the next step starts only from the first ones, so an unrelated hub never opens the way.
        $cluster = new FeatureCluster($this->graph, $this->relevance());
        for ($level = 1; $level <= $depth && $frontier !== [] && \count($selected) < $budget; ++$level) {
            $candidates = [];
            $consider = function (Node $other, int $weight, bool $ownName) use (&$candidates, &$selected, $terms, $withTests, $cluster): void {
                if (isset($selected[$other->id]) || $other->kind === NodeKind::File || $other->kind === NodeKind::External
                    || (!$withTests && $other->file !== null && TestFiles::isTest($other->file))) {
                    return;
                }
                // A member of a family the answer holds (one rule among 34): the family stands for it.
                $head = $other->kind->isClassLike() ? $cluster->headOf($other->id) : null;
                if ($head !== null && isset($selected[$head]) && $this->relevance()->score($other, $terms, true) <= 0) {
                    return;
                }
                $score = $this->relevance()->score($other, $terms, $ownName);
                if ($score <= 0 && ($weight < 2 || $this->graph->degree($other->id) > self::HUB)) {
                    return;
                }
                $priority = $score * 10 + $weight;
                if (($candidates[$other->id][0] ?? -1.0) < $priority) {
                    $candidates[$other->id] = [$priority, $score];
                }
            };
            foreach ($frontier as $id) {
                foreach ($this->graph->incident($id) as $item) {
                    $other = $this->graph->node($item['other']);
                    $relation = $item['edge']->relation;
                    if ($other === null || $relation === Relation::Imports || $relation === Relation::Defines) {
                        continue;
                    }
                    // The members of an injected list are the list's: summed up apart (injectedLists), not neighbours.
                    if ($relation === Relation::Receives && (str_starts_with($item['edge']->via(), 'tagged_') || str_starts_with($item['edge']->via(), 'through '))) {
                        continue;
                    }
                    if ($relation === Relation::HasMethod) {
                        // A class added around the named ones stands as a class.
                        if (isset($seedClasses[$id]) && !isset($methodsShown[$id])) {
                            continue;
                        }
                        // A method of a seed: only by its own name, or an entry point.
                        $name = strtolower(substr($other->id, (int) strrpos($other->id, ':') + 1));
                        if ($this->relevance()->score($other, $terms, true) <= 0 && !\in_array($name, self::ENTRY_POINTS, true)) {
                            continue;
                        }
                        $consider($other, 2, true);
                        continue;
                    }
                    $consider($asCandidate($other), self::EXPAND_WEIGHTS[$relation->value] ?? 0, false);
                }
                // The other classes of a seed's namespace: the module the question is about.
                if ($level === 1 && isset($seedClasses[$id])) {
                    foreach ($this->siblings($id) as $sibling) {
                        $consider($sibling, 2, false);
                    }
                }
            }
            uasort($candidates, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

            $next = [];
            $room = $level === $depth ? $budget - \count($selected) : (int) ceil(($budget - \count($selected)) * 2 / 3);
            foreach (\array_slice($candidates, 0, max(0, $room), true) as $id => [, $score]) {
                $selected[$id] = true;
                if ($score > 0) {
                    $next[] = (string) $id;
                }
            }
            $frontier = $next;
        }

        $nodes = [];
        foreach (array_keys($selected) as $id) {
            $node = $this->graph->node((string) $id);
            if ($node !== null) {
                $nodes[] = $node;
            }
        }

        $edges = [];
        foreach ($nodes as $node) {
            foreach ($this->graph->incident($node->id) as $item) {
                if ($item['forward'] && isset($selected[$item['other']])) {
                    $edges[] = $item['edge'];
                }
            }
        }

        return new Subgraph($terms, $seeds, $nodes, $edges);
    }

    /** @var array<string, list<Node>>|null namespace => its classes */
    private ?array $byNamespace = null;

    /**
     * The classes, interfaces, traits and enums declared in the same namespace as a class.
     *
     * @return list<Node>
     */
    private function siblings(string $class): array
    {
        if ($this->byNamespace === null) {
            $this->byNamespace = [];
            foreach ($this->graph->nodes() as $node) {
                if ($node->kind->isClassLike() && $node->kind !== NodeKind::External) {
                    $this->byNamespace[(string) substr($node->id, 0, (int) strrpos('\\' . $node->id, '\\'))][] = $node;
                }
            }
        }
        $namespace = (string) substr($class, 0, (int) strrpos('\\' . $class, '\\'));
        $siblings = $this->byNamespace[$namespace] ?? [];

        // A namespace holding everything (App) is no module.
        return \count($siblings) <= self::MODULE ? array_values(array_filter($siblings, static fn (Node $node): bool => $node->id !== $class)) : [];
    }

    private function relevance(): Relevance
    {
        return $this->relevance ??= new Relevance($this->graph);
    }

    /**
     * @return list<string> the stems of the question's words, without the stop words
     */
    private function questionStems(string $question): array
    {
        // The stop words go before stemming: "before" would else become "befor" and match getNumberOfDaysBefore().
        // The case stays: a name written in the question (PutCourseController) is split into its words.
        $words = preg_split('/[^\p{L}\p{N}]+/u', $question, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = array_filter($words, static fn (string $word): bool => !\in_array(mb_strtolower($word), self::STOPWORDS, true) && !\in_array(mb_strtolower($word), self::QUESTION_WORDS, true));

        return Relevance::stems(implode(' ', $kept));
    }

    /**
     * The families of classes around an answer: the interfaces among its nodes or declared in the namespace of one of
     * its classes, implemented by at least two application classes, the largest first (rules: 34 context, 23
     * invariant).
     *
     * @param list<Node> $nodes
     *
     * @return array<string, array<string, int>> interface id => directory => classes
     */
    public function implementationFamilies(array $nodes, int $limit = 8): array
    {
        $namespaces = [];
        foreach ($nodes as $node) {
            if ($node->kind->isClassLike()) {
                $namespaces[$this->namespaceOf($node->id)] = true;
            }
        }
        $families = [];
        foreach ($this->graph->nodes() as $node) {
            if ($node->kind !== NodeKind::PhpInterface || !isset($namespaces[$this->namespaceOf($node->id)])
                || ($node->file !== null && TestFiles::isTest($node->file))) {
                continue;
            }
            $byDirectory = $this->implementationsByDirectory($node);
            if (array_sum($byDirectory) >= 2) {
                $families[$node->id] = $byDirectory;
            }
        }
        uasort($families, static fn (array $a, array $b): int => array_sum($b) <=> array_sum($a));

        return \array_slice($families, 0, $limit, true);
    }

    private function namespaceOf(string $id): string
    {
        $position = strrpos($id, '\\');

        return $position === false ? '' : substr($id, 0, $position);
    }

    /**
     * The application classes implementing an interface, directly, through an interface extending it or through a
     * parent class, counted by the directory of their file: a family of rules spread over bounded contexts.
     *
     * @return array<string, int> directory => classes, the largest first
     */
    public function implementationsByDirectory(Node $interface): array
    {
        $family = [$interface->id => true];
        $classes = [];
        for ($queue = [$interface->id]; $queue !== [];) {
            $next = [];
            foreach ($queue as $id) {
                foreach ($this->graph->incident($id) as $item) {
                    $relation = $item['edge']->relation;
                    if ($item['forward'] || isset($family[$item['other']])
                        || !\in_array($relation, [Relation::Implements, Relation::Extends], true)) {
                        continue;
                    }
                    $family[$item['other']] = true;
                    $next[] = $item['other'];
                    $node = $this->graph->node($item['other']);
                    if ($node?->kind === NodeKind::PhpClass && $node->file !== null && !TestFiles::isTest($node->file)) {
                        $classes[$node->id] = \dirname($node->file);
                    }
                }
            }
            $queue = $next;
        }
        $byDirectory = array_count_values($classes);
        arsort($byDirectory);

        return $byDirectory;
    }

    /**
     * @return list<RankedNode>
     */
    public function godNodes(int $limit = 15): array
    {
        $ranked = [];
        foreach ($this->graph->nodes() as $node) {
            if ($node->kind === NodeKind::File || $node->kind === NodeKind::External) {
                continue;
            }
            $ranked[] = new RankedNode($node, $this->graph->degree($node->id));
        }

        usort($ranked, static fn (RankedNode $a, RankedNode $b): int => $b->degree <=> $a->degree);

        return \array_slice($ranked, 0, $limit);
    }

    public function label(string $id): string
    {
        return $this->graph->node($id)->label ?? $id;
    }

    /**
     * Breadth-first search, so the first path found is a shortest one for the given mode.
     *
     * @return list<Connection>|null
     */
    private function findPath(Node $start, Node $end, PathMode $mode): ?array
    {
        /** @var array<string, array{string, Connection}|null> $previous */
        $previous = [$start->id => null];
        $queue = new \SplQueue();
        $queue->enqueue($start->id);

        while (!$queue->isEmpty()) {
            /** @var string $current */
            $current = $queue->dequeue();
            if ($current === $end->id) {
                break;
            }

            foreach ($this->graph->incident($current) as $item) {
                $other = $item['other'];
                if (!$this->canStep($item['edge']->relation, $item['forward'], $mode)) {
                    continue;
                }
                if (\array_key_exists($other, $previous)) {
                    // Two edges between the same nodes, same hop: keep the one that tells more (dispatches over instantiates).
                    $reached = $previous[$other];
                    if ($reached !== null && $reached[0] === $current && $this->weight($item['edge']->relation) > $this->weight($reached[1]->edge->relation)) {
                        $previous[$other] = [$current, new Connection($item['edge'], $other, $item['forward'])];
                    }
                    continue;
                }
                if ($other !== $end->id && !$this->canTraverse($other, $mode)) {
                    continue;
                }
                $previous[$other] = [$current, new Connection($item['edge'], $other, $item['forward'])];
                $queue->enqueue($other);
            }
        }

        if (!isset($previous[$end->id])) {
            return null;
        }

        $hops = [];
        $cursor = $end->id;
        while ($previous[$cursor] !== null) {
            [$parent, $connection] = $previous[$cursor];
            array_unshift($hops, $connection);
            $cursor = $parent;
        }

        return $hops;
    }

    private function weight(Relation $relation): int
    {
        return match ($relation) {
            Relation::Dispatches, Relation::HandledBy, Relation::Calls => 3,
            Relation::Implements, Relation::Extends, Relation::Overrides, Relation::UsesTrait, Relation::HasMethod, Relation::Receives => 2,
            Relation::Instantiates => 1,
            default => 0,
        };
    }

    private function canStep(Relation $relation, bool $forward, PathMode $mode): bool
    {
        if ($mode !== PathMode::Dependency || $forward) {
            return true;
        }

        return \in_array($relation, self::REVERSIBLE_IN_DEPENDENCY_PATH, true);
    }

    /**
     * Files and external nodes are hubs: going through them links unrelated classes
     * (two classes both using LoggerInterface are not related through it).
     */
    private function canTraverse(string $id, PathMode $mode): bool
    {
        if ($mode === PathMode::Any) {
            return true;
        }

        $kind = $this->graph->node($id)?->kind;

        return $kind !== NodeKind::File && $kind !== NodeKind::External;
    }

    /**
     * @return list<string>
     */
    private function terms(string $question): array
    {
        $parts = preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower($question), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $parts,
            static fn (string $part): bool => mb_strlen($part) >= 3 && !\in_array($part, self::STOPWORDS, true),
        )));
    }
}
