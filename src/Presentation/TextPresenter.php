<?php

declare(strict_types=1);

namespace PhpGraph\Presentation;

use PhpGraph\Builder\CallStats;
use PhpGraph\Graph\Edge;
use PhpGraph\Graph\Node;
use PhpGraph\Graph\NodeKind;
use PhpGraph\Query\Direction;
use PhpGraph\Query\GraphQuery;
use PhpGraph\Query\Result\Connection;
use PhpGraph\Query\Result\LayerViolation;
use PhpGraph\Query\Result\NamespaceGroup;
use PhpGraph\Query\Result\PathMode;
use PhpGraph\Query\Result\RouteEntry;

/**
 * Plain-text answers shared by the CLI and the MCP server. Kept compact: the main reader is an AI assistant.
 */
final class TextPresenter
{
    /**
     * Up to this many routes, the overview lists them all; beyond, it groups them by path prefix.
     */
    private const ROUTES_LISTED = 30;

    public function __construct(private readonly GraphQuery $query)
    {
    }

    public function explain(string $name, Direction $direction = Direction::Both, int $limit = 60): string
    {
        $candidates = $this->query->candidates($name, 6);
        if ($candidates === []) {
            return \sprintf('No node matching "%s".', $name);
        }

        $node = $candidates[0];
        $graph = $this->query->graph();
        $lines = [
            'Node: ' . $node->label,
            '  Id:        ' . $node->id,
            '  Kind:      ' . $node->kind->value,
            ...($node->service === null ? [] : ['  Service:   ' . $node->service]),
            '  Source:    ' . $this->location($node),
            '  Degree:    ' . $graph->degree($node->id),
            '',
        ];

        $connections = $this->query->connections($node, $direction);

        // By direction and relation, the most telling first; file-level links (use statements, the declaring file)
        // only counted: a class used everywhere would show nothing else.
        $groups = [];
        foreach ($connections as $connection) {
            $groups[($connection->forward ? 'out' : 'in') . ':' . $connection->edge->relation->value][] = $connection;
        }
        $order = static function (string $key): int {
            [$side, $relation] = explode(':', $key, 2);
            $position = array_search($relation, self::EXPLAIN_ORDER, true);

            return ($side === 'out' ? 0 : 100) + ($position === false ? 50 : $position);
        };
        uksort($groups, static fn (string $a, string $b): int => $order($a) <=> $order($b));

        $perGroup = max(1, intdiv($limit, 5));
        $lines[] = \sprintf('Connections (%d), by relation:', \count($connections));
        foreach ($groups as $key => $group) {
            [$side, $relation] = explode(':', $key, 2);
            if (\in_array($relation, ['imports', 'defines'], true)) {
                $lines[] = \sprintf('  %s %s: %d %s', $side === 'out' ? '-->' : '<--', $relation, \count($group), $side === 'out' ? 'nodes' : 'files');
                continue;
            }
            $lines[] = \sprintf('  %s %s (%d):', $side === 'out' ? '-->' : '<--', $relation, \count($group));
            foreach (\array_slice($group, 0, $perGroup) as $connection) {
                $lines[] = '  ' . $this->connectionLine($connection, $graph->node($connection->other));
            }
            if (\count($group) > $perGroup) {
                $lines[] = \sprintf('      ... %d more: get_neighbors, or impact_of for what depends on it', \count($group) - $perGroup);
            }
        }

        if ($direction !== Direction::Out && $node->kind === NodeKind::Method) {
            foreach ($this->query->callersThroughParents($node) as $parent => $callers) {
                $lines[] = '';
                $lines[] = \sprintf('Called through %s, which it implements (%d, INFERRED: the implementation run is chosen at runtime):', $this->query->label($parent), \count($callers));
                foreach (\array_slice($callers, 0, $limit) as $connection) {
                    $lines[] = $this->connectionLine($connection, $graph->node($connection->other));
                }
            }
        }

        $others = array_filter(
            \array_slice($candidates, 1),
            static fn (Node $other): bool => rtrim(mb_strtolower($other->label), '()') === rtrim(mb_strtolower($node->label), '()')
                || mb_strtolower($other->label) === mb_strtolower(trim($name)),
        );
        if ($others !== []) {
            $lines[] = '';
            $lines[] = 'Other matches: ' . implode(', ', array_map(static fn (Node $other): string => $other->id, $others));
        }

        return implode("\n", $lines);
    }

    /**
     * `<-- Caller::run() [calls] [INFERRED]  src/Caller.php L12, called at L20, L31`: where the other end is
     * declared, and the lines of the relation in the source.
     */
    private function connectionLine(Connection $connection, ?Node $other): string
    {
        $lines = $connection->edge->lines();

        return rtrim(\sprintf(
            '  %s %s [%s] [%s]  %s%s',
            $connection->forward ? '-->' : '<--',
            $this->query->label($connection->other),
            $connection->edge->relation->value,
            $connection->edge->confidence->value,
            $other?->file === null ? '' : $this->location($other),
            $lines === [] ? '' : ', at L' . implode(', L', \array_slice($lines, 0, 8)) . (\count($lines) > 8 ? ', ...' : ''),
        ));
    }

    public function path(string $from, string $to): string
    {
        $start = $this->query->resolve($from);
        $end = $this->query->resolve($to);

        if ($start === null || $end === null) {
            return \sprintf('No node matching "%s".', $start === null ? $from : $to);
        }

        if ($start->id === $end->id) {
            return 'Both names resolve to the same node: ' . $start->label;
        }

        $path = $this->query->shortestPath($start, $end);
        if ($path === null) {
            return \sprintf('No path between "%s" and "%s".', $start->label, $end->label);
        }

        $line = '  ' . ($path->reversed ? $end->label : $start->label);
        foreach ($path->hops as $hop) {
            $line .= $hop->forward
                ? \sprintf(' --%s--> %s', $hop->edge->relation->value, $this->query->label($hop->other))
                : \sprintf(' <--%s-- %s', $hop->edge->relation->value, $this->query->label($hop->other));
        }

        $note = match ($path->mode) {
            PathMode::Dependency => $path->reversed ? \sprintf("\nNo dependency path from %s to %s: %s depends on %s, as shown.", $start->label, $end->label, $end->label, $start->label) : '',
            PathMode::Undirected => "\nNo dependency path: this one ignores edge direction.",
            PathMode::Any => "\nNo dependency path: this one ignores edge direction and goes through files or external nodes.",
        };

        return \sprintf("Shortest path (%d hops):\n%s%s", \count($path->hops), $line, $note);
    }

    public function query(string $question, int $depth = 2, int $budget = 40): string
    {
        $subgraph = $this->query->subgraph($question, $depth, $budget);

        if ($subgraph->terms === []) {
            return 'The question contains no searchable term.';
        }
        if ($subgraph->seeds === []) {
            return 'No node matches the question terms: ' . implode(', ', $subgraph->terms);
        }

        $lines = [
            'Question: ' . $question,
            'Seeds: ' . implode(', ', array_map(static fn (Node $node): string => $node->label, $subgraph->seeds)),
            '',
            'Nodes (' . \count($subgraph->nodes) . '):',
        ];

        foreach ($subgraph->nodes as $node) {
            $lines[] = \sprintf('  - %s [%s] %s', $node->label, $node->kind->value, $this->location($node));
        }

        $edgeLimit = $budget * 3;
        $lines[] = '';
        $lines[] = 'Edges (' . \count($subgraph->edges) . '):';
        foreach (\array_slice($subgraph->edges, 0, $edgeLimit) as $edge) {
            $lines[] = '  ' . $this->formatEdge($edge);
        }
        if (\count($subgraph->edges) > $edgeLimit) {
            $lines[] = \sprintf('  ... %d more', \count($subgraph->edges) - $edgeLimit);
        }

        return implode("\n", $lines);
    }

    public function godNodes(int $limit = 15): string
    {
        $lines = ['God nodes (most connected):'];
        foreach ($this->query->godNodes($limit) as $index => $ranked) {
            $lines[] = \sprintf(
                '  %d. %s [%s] degree %d  %s',
                $index + 1,
                $ranked->node->label,
                $ranked->node->kind->value,
                $ranked->degree,
                $this->location($ranked->node),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * The first answer an agent needs on an unknown project: stack, structure and the limits of the graph.
     */
    /**
     * Why the graph is empty although the directory holds PHP code, or null.
     */
    public static function unreadSources(int $phpFiles, string $root = 'the directory'): ?string
    {
        if ($phpFiles === 0) {
            return null;
        }

        return \sprintf(
            'Empty graph: no PHP file was read, yet %s holds %d PHP files (outside vendor/, var/, node_modules/). Every '
            . 'one was excluded, by the project\'s .gitignore or by the exclude patterns (--exclude, phpgraph.yaml). '
            . 'Check those rules and rebuild: until then, the graph knows nothing about this code.',
            $root,
            $phpFiles,
        );
    }

    /**
     * A warning every answer starts with, when the graph cannot be trusted at all.
     */
    public function warning(): ?string
    {
        return self::unreadSources($this->query->summary()->phpFilesNotRead ?? 0);
    }

    public function overview(): string
    {
        $overview = $this->query->overview();
        $summary = $overview->summary;
        $lines = ['# Project overview', ''];
        $warning = $this->warning();
        if ($warning !== null) {
            array_push($lines, '**' . $warning . '**', '');
        }

        $lines[] = '## Stack';
        if ($summary === null) {
            $lines[] = 'Unknown: this graph was built by an older phpgraph. Rebuild it to see the stack and the call statistics.';
        } elseif ($summary->stack === []) {
            $lines[] = 'No composer.json: no Composer dependencies.';
        }
        foreach ($summary === null ? [] : $summary->stack as $application) {
            $lines[] = \sprintf(
                '- %s%s%s',
                $application['directory'] === '.' ? 'Root application' : $application['directory'],
                $application['php'] === null ? '' : ', PHP ' . $application['php'],
                $application['locked'] ? '' : ' (no composer.lock: constraints, not installed versions)',
            );
            $byRole = [];
            foreach ($application['packages'] as $name => $package) {
                $byRole[$package['role']][] = $name . ' ' . $package['version'];
            }
            foreach (['framework', 'persistence', 'messaging', 'tests', 'analysis'] as $role) {
                if (isset($byRole[$role])) {
                    $lines[] = \sprintf('  %s: %s', $role, implode(', ', $byRole[$role]));
                }
            }
        }

        if ($summary !== null && $summary->services !== []) {
            $lines[] = '';
            $lines[] = '## Services';
            $lines[] = \sprintf(
                '%d services, each with its own namespace space: ids read service@Class (billing@App\\Domain\\Order), and a name '
                . 'resolves in its service, then in the shared root code, never in another service. Services meet through '
                . 'contracts only. The tree below starts with them.',
                \count($summary->services),
            );
            $lines[] = '- ' . implode(', ', \array_slice($summary->services, 0, 30)) . (\count($summary->services) > 30 ? ', ...' : '');
        }

        $lines[] = '';
        $lines[] = '## Size';
        $lines[] = \sprintf(
            '%d application classes, %d test classes (tests/, spec/, Behat/, *Test.php...), %s.',
            $overview->applicationClasses,
            $overview->testClasses,
            implode(', ', array_map(static fn (string $kind, int $count): string => $count . ' ' . $kind, array_keys($overview->nodesByKind), $overview->nodesByKind)),
        );

        $lines[] = '';
        $lines[] = '## Structure of application code';
        $lines[] = 'Read from namespaces: layer names in brackets are namespace segments found below, not a verdict on the architecture.';
        if ($overview->root !== '') {
            $lines[] = 'Root namespace: ' . $overview->root;
        }
        if ($overview->globalClasses > 0) {
            $lines[] = \sprintf('%d classes without namespace.', $overview->globalClasses);
        }
        foreach (\array_slice($overview->namespaces, 0, 12) as $group) {
            $lines[] = '- ' . $this->namespaceGroup($group);
            foreach (\array_slice($group->children, 0, 8) as $child) {
                $lines[] = '  - ' . $this->namespaceGroup($child);
                // A third level only where the second is too coarse to tell anything: Sylius\Bundle, Sylius\Component.
                if (\count($group->children) <= 3) {
                    foreach (\array_slice($child->children, 0, 8) as $grandChild) {
                        $lines[] = '    - ' . $this->namespaceGroup($grandChild);
                    }
                    if (\count($child->children) > 8) {
                        $lines[] = \sprintf('    - ... %d more', \count($child->children) - 8);
                    }
                }
            }
            if (\count($group->children) > 8) {
                $lines[] = \sprintf('  - ... %d more', \count($group->children) - 8);
            }
        }
        if (\count($overview->namespaces) > 12) {
            $lines[] = \sprintf('- ... %d more', \count($overview->namespaces) - 12);
        }

        $lines[] = '';
        if ($overview->layers === []) {
            $lines[] = 'Layers: no layer-like namespace segment (Domain, Application, Infrastructure, Adapter, Controller...).';
        } else {
            $lines[] = 'Layers (application classes below a layer-like segment):';
            foreach ($overview->layers as $role => $segments) {
                $lines[] = \sprintf('  %s: %s', $role, $this->counts($segments));
            }
        }
        if ($overview->suffixes !== []) {
            $lines[] = 'Class name suffixes: ' . $this->counts(\array_slice($overview->suffixes, 0, 15, true));
        }

        $architecture = $this->query->architecture();
        if ($overview->layers !== []) {
            $lines[] = '';
            $lines[] = '## Layer rules';
            $lines[] = 'Default rules of a layered architecture, checked on application code: domain must not depend on application, '
                . 'infrastructure or interface; application and port must not depend on infrastructure or interface.';
            if ($architecture->violations === []) {
                $lines[] = '- No dependency breaks them.';
            } else {
                $lines[] = \sprintf('- %d class dependencies break them: %s. Details: phpgraph check.', \count($architecture->violations), $this->counts($this->violationPairs($architecture->violations)));
                foreach (\array_slice($architecture->violations, 0, 5) as $violation) {
                    $lines[] = '  ' . $this->violation($violation);
                }
            }
            if ($architecture->contextDependencies !== []) {
                $lines[] = \sprintf(
                    '- Dependencies between bounded contexts (read %s the layer segment, EXTRACTED and INFERRED relations): %s%s.',
                    $architecture->layerFirst ? 'after' : 'before',
                    $this->counts(\array_slice($architecture->contextDependencies, 0, 10, true)),
                    \count($architecture->contextDependencies) > 10 ? \sprintf(', ... %d more pairs', \count($architecture->contextDependencies) - 10) : '',
                );
            }
        }

        $bus = $summary?->bus;
        if ($bus !== null && !$bus->isEmpty()) {
            $lines[] = '';
            $lines[] = '## Messages';
            $lines[] = 'Sender --dispatches--> message --handled_by--> handler, linked through the message class (application code).';
            $lines[] = \sprintf('- Handlers linked to their message: %s.', $this->confidenceCounts($bus->handlers));
            $lines[] = \sprintf('- Sends linked to their message: %s.', $this->confidenceCounts($bus->sends));
            if ($bus->contracts > 0) {
                $lines[] = \sprintf('- %d message classes sent by one service and handled by another: message --contract--> message.', $bus->contracts);
            }
            if ($bus->messagesWithoutHandlerCount > 0) {
                $lines[] = \sprintf(
                    '- %d sent messages have no handler in the project (handled by a dependency, another service, or not detected): %s%s.',
                    $bus->messagesWithoutHandlerCount,
                    implode(', ', $bus->messagesWithoutHandler),
                    $bus->messagesWithoutHandlerCount > \count($bus->messagesWithoutHandler) ? ', ...' : '',
                );
            }
            if ($bus->messagesNeverSentCount > 0) {
                $lines[] = \sprintf(
                    '- %d handled messages are never sent by the project (sent by a dependency or another service, deserialized, or sent untyped): %s%s.',
                    $bus->messagesNeverSentCount,
                    implode(', ', $bus->messagesNeverSent),
                    $bus->messagesNeverSentCount > \count($bus->messagesNeverSent) ? ', ...' : '',
                );
            }
            if ($bus->untypedSends > 0) {
                $lines[] = \sprintf('- %d sends carry a message of unknown type or a channel name computed at runtime: their handler cannot be linked.', $bus->untypedSends);
            }
        }

        $http = $summary?->http;
        if ($http !== null && ($http->routes > 0 || $http->requests > 0)) {
            $lines[] = '';
            $lines[] = '## HTTP';
            $lines[] = 'Route nodes (route:GET /orders/{id}) are the entry points: route --handled_by--> controller; caller --requests--> route.';
            $unresolved = $http->routes - $http->routesWithHandler - $http->routesToDependencies - $http->routesToMissingControllers;
            $lines[] = \sprintf(
                '- %d routes (attributes, API Platform resources, Laravel route files, Symfony YAML and PHP routing files): %d handled by a controller of the project%s%s.',
                $http->routes,
                $http->routesWithHandler,
                $http->routesToDependencies > 0 ? \sprintf(', %d by a controller of a dependency (or API Platform itself)', $http->routesToDependencies) : '',
                $unresolved > 0 ? \sprintf(', %d without one: no controller (a route only the front end uses), a closure, or a controller service no configuration of the project declares (generated at runtime by a bundle)', $unresolved) : '',
            );
            if ($http->routesToMissingControllers > 0) {
                $lines[] = \sprintf(
                    '- %d routes name a controller class declared %s: a stale route, or code the build did not read. %s%s.',
                    $http->routesToMissingControllers,
                    $summary->options->readVendor && $summary->vendorDirectories > 0
                        ? 'neither in the project nor in its dependencies'
                        : 'nowhere in the project (dependencies were not read: it may come from one)',
                    implode('; ', $http->missingControllers),
                    $http->routesToMissingControllers > \count($http->missingControllers) ? '; ...' : '',
                );
            }
            if ($http->requests > 0) {
                $lines[] = \sprintf(
                    '- %d HTTP calls in application code: %d reach a route of the project%s, %d go elsewhere (external APIs, or a path computed at runtime).',
                    $http->requests,
                    $http->requestsToProject,
                    $http->requestsToServices > 0 ? \sprintf(' (%d in another service)', $http->requestsToServices) : '',
                    $http->requests - $http->requestsToProject - $http->methodMismatchCount,
                );
            }
            if ($http->methodMismatchCount > 0) {
                $lines[] = \sprintf(
                    '- %d HTTP calls match the path of a route declared for other methods only (would fail as written; linked AMBIGUOUS): %s%s.',
                    $http->methodMismatchCount,
                    implode('; ', $http->methodMismatches),
                    $http->methodMismatchCount > \count($http->methodMismatches) ? '; ...' : '',
                );
            }
        }

        if ($overview->routes !== []) {
            array_push($lines, '', ...$this->routeList($overview->routes));
        }

        $injections = $summary?->injections;
        if ($injections !== null && $injections->injections > 0) {
            $lines[] = '';
            $lines[] = '## Container injections';
            $lines[] = 'What the container configuration injects beyond constructor types: service --receives--> each service of a tag '
                . '(tagged_iterator, #[AutowireIterator]) or a service named by id (service(), @id, the decorated .inner).';
            $lines[] = \sprintf(
                '- %d injections into application code: %d linked (%d receives edges)%s.',
                $injections->injections,
                $injections->linked,
                $injections->edges,
                $injections->unlinkedCount > 0 ? \sprintf(
                    ', %d name services the project\'s configuration does not define: framework or bundle services, or ids '
                    . 'computed at runtime. Those that look like the project\'s own first: %s%s',
                    $injections->unlinkedCount,
                    implode('; ', $injections->unlinked),
                    $injections->unlinkedCount > \count($injections->unlinked) ? '; ...' : '',
                ) : '',
            );
        }

        $lines[] = '';
        $lines[] = '## What the graph does not know';
        if ($summary !== null) {
            $calls = $summary->applicationCalls;
            $lines[] = \sprintf(
                '- Method calls in application code: %d. Resolved %s (INFERRED %s, AMBIGUOUS %s), to dependencies %s, receiver type unknown %s.',
                $calls->total(),
                $this->percent($calls->inferred + $calls->ambiguous, $calls),
                $this->percent($calls->inferred, $calls),
                $this->percent($calls->ambiguous, $calls),
                $this->percent($calls->outsideProject, $calls),
                $this->percent($calls->unknownReceiver, $calls),
            );
            if ($summary->mostUnresolvedMethods !== []) {
                $lines[] = '  Most frequent unresolved calls: ' . $this->counts(array_combine(
                    array_map(static fn (int|string $name): string => '->' . $name . '()', array_keys($summary->mostUnresolvedMethods)),
                    $summary->mostUnresolvedMethods,
                ));
            }
            $lines[] = match (true) {
                $summary->stack === [] => '- No Composer application: dependencies, if any, are not Composer packages and are not read.',
                $summary->vendorDirectories === 0 => '- No installed vendor/: call chains stop at the first dependency. Run composer install and rebuild.',
                default => $summary->options->readVendor
                    ? \sprintf('- Dependencies: %d vendor/ directories, %d dependency files read for their signatures (not graph nodes).', $summary->vendorDirectories, $summary->vendorFilesRead)
                    : '- Dependencies not read (--no-vendor): call chains stop at the first dependency.',
            };
            if ($summary->options->excludes !== []) {
                $lines[] = '- Excluded from the build: ' . implode(', ', $summary->options->excludes);
            }
            if ($summary->filesFailed > 0) {
                $lines[] = \sprintf('- %d files could not be parsed: see GRAPH_REPORT.md.', $summary->filesFailed);
            }
            if ($summary->duplicateCount > 0) {
                $lines[] = \sprintf(
                    '- %d names declared more than once, only the first is a node: %s%s.',
                    $summary->duplicateCount,
                    implode(', ', $summary->duplicates),
                    $summary->duplicateCount > \count($summary->duplicates) ? ', ...' : '',
                );
            }
        }
        $lines[] = '- Not modelled: calls to global functions, dynamic calls ($obj->$name(), __call), routes declared in XML, '
            . 'services built at runtime (compiler passes, bundle extensions, ids computed in code), and API schemas (OpenAPI, protobuf).';

        return implode("\n", $lines);
    }

    /**
     * Every dependency breaking a layer rule, for phpgraph check.
     *
     * @param list<string> $accepted violation keys ("From -> To") listed in a baseline
     */
    public function layerViolations(array $accepted = []): string
    {
        $violations = array_values(array_filter(
            $this->query->architecture()->violations,
            static fn (LayerViolation $violation): bool => !\in_array($violation->key(), $accepted, true),
        ));
        if ($violations === []) {
            return $accepted === [] ? 'No dependency breaks the layer rules.' : 'No new dependency breaks the layer rules.';
        }

        $lines = [\sprintf('%d class dependencies break the layer rules%s:', \count($violations), $accepted === [] ? '' : ' (not in the baseline)')];
        foreach ($this->violationPairs($violations) as $pair => $count) {
            $lines[] = '';
            $lines[] = \sprintf('%s (%d):', $pair, $count);
            foreach ($violations as $violation) {
                if ($violation->fromLayer . ' -> ' . $violation->toLayer === $pair) {
                    $lines[] = '  ' . $this->violation($violation);
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * What depends on a class or a method, directly or not: what a change may break.
     */
    public const IMPACT_SECTIONS = ['direct', 'state', 'tests', 'helpers'];

    /**
     * The relations of a node, the most telling first.
     */
    private const EXPLAIN_ORDER = [
        'handled_by', 'calls', 'dispatches', 'requests', 'receives', 'reads_state_of', 'extends', 'implements', 'uses_trait',
        'overrides', 'instantiates', 'references', 'contract', 'has_method', 'defines', 'imports',
    ];

    /**
     * Call sites shown per class, unless every one is asked for.
     */
    private const IMPACT_SITES = 3;

    /**
     * @param int     $limit   entries per section, 0 for all
     * @param ?string $section one of IMPACT_SECTIONS, null for all of them
     */
    public function impact(string $name, int $depth = 3, int $limit = 40, ?string $section = null): string
    {
        $node = $this->query->candidates($name, 1)[0] ?? null;
        if ($node === null) {
            return \sprintf('No node matching "%s".', $name);
        }

        // Complete lists: the search itself goes further than its usual 200 classes.
        $impact = $this->query->impactOf($node, $depth, $limit === 0 ? 5000 : 200);
        $isTestCase = fn (string $class): bool => preg_match('/(Test|TestCase|Cest|Spec|Context|Feature)$/', $this->query->label($class)) === 1;
        $groups = ['direct' => [], 'state' => [], 'tests' => [], 'stateTests' => [], 'helpers' => []];
        foreach ($impact->classes as $class) {
            $key = match (true) {
                $class->isTest && !$isTestCase($class->class) => 'helpers',
                $class->isTest => $class->throughState ? 'stateTests' : 'tests',
                default => $class->throughState ? 'state' : 'direct',
            };
            $groups[$key][] = $class;
        }

        // What is reached through the state only possibly depends on the change: the classes and tests in the modules
        // of the direct dependents first (their first two namespace segments).
        $module = static fn (string $class): string => implode('\\', \array_slice(explode('\\', $class), 0, 2));
        $modules = array_fill_keys(array_map(static fn ($class): string => $module($class->class), $groups['direct']), true);
        // Readers filtering on what it writes (INFERRED) before those reading everything (AMBIGUOUS), then by module.
        $rank = static fn ($class): int => $class->confidence === \PhpGraph\Graph\Confidence::Ambiguous ? 1 : 0;
        foreach (['state', 'stateTests'] as $key) {
            usort($groups[$key], static fn ($a, $b): int => [$rank($a), isset($modules[$module($b->class)]), $a->depth] <=> [$rank($b), isset($modules[$module($a->class)]), $b->depth]);
        }

        $lines = [
            \sprintf('Impact of changing %s [%s], %s', $node->label, $node->kind->value, $this->location($node)),
            \sprintf(
                '%d application classes depend on it up to %d relations away, %d more possibly through the state it changes; '
                . '%d tests to run, %d possibly affected through the state; %d test helpers%s.',
                \count($groups['direct']),
                $impact->maxDepth,
                \count($groups['state']),
                \count($groups['tests']),
                \count($groups['stateTests']),
                \count($groups['helpers']),
                $impact->truncated ? ' (stopped at the limit: more exist)' : '',
            ),
            'Each class comes with the weakest confidence on the way, then its methods reaching the change and their source lines. '
            . \sprintf(
                'Lists show %s: impact_of with limit 0 (CLI: --all) gives every class and every call site, section (direct, state, tests, helpers) one list.',
                $limit === 0 ? 'everything' : \sprintf('the first %d classes and %d call sites per class', $limit, self::IMPACT_SITES),
            ),
        ];
        $sites = function ($class) use ($limit): array {
            $lines = [];
            $shown = $limit === 0 ? $class->sites : \array_slice($class->sites, 0, self::IMPACT_SITES);
            foreach ($shown as $edge) {
                $lines[] = '      ' . $this->formatEdge($edge);
            }
            if (\count($class->sites) > \count($shown)) {
                $lines[] = \sprintf('      ... %d more call sites', \count($class->sites) - \count($shown));
            }

            return $lines;
        };
        $wanted = static fn (string $name): bool => $section === null || $section === $name;
        $slice = static fn (array $entries): array => $limit === 0 ? $entries : \array_slice($entries, 0, $limit);
        $more = static function (array $entries) use ($limit, &$lines): void {
            if ($limit > 0 && \count($entries) > $limit) {
                $lines[] = \sprintf('  - ... %d more', \count($entries) - $limit);
            }
        };

        if ($wanted('direct')) {
            for ($level = 1; $level <= $impact->maxDepth; ++$level) {
                $atDepth = array_values(array_filter($groups['direct'], static fn ($class): bool => $class->depth === $level));
                if ($atDepth === []) {
                    continue;
                }
                $lines[] = '';
                $lines[] = $level === 1 ? 'Direct dependents:' : \sprintf('%d relations away:', $level);
                foreach ($slice($atDepth) as $class) {
                    $lines[] = \sprintf(
                        '  - %s [%s]%s%s  %s',
                        $this->query->label($class->class),
                        $class->confidence->value,
                        $class->through === null ? '' : ' (calls it through ' . $this->query->label($class->through) . ')',
                        $class->followed ? '' : ($class->edge->relation === \PhpGraph\Graph\Relation::Receives
                            ? ' (receives it among others: its users are not followed)'
                            : ' (inherited code shared with other subclasses: its callers are not followed)'),
                        $this->classLocation($class->class),
                    );
                    array_push($lines, ...$sites($class));
                }
                $more($atDepth);
            }
        }

        if ($wanted('state') && $groups['state'] !== []) {
            $lines[] = '';
            $lines[] = 'Possibly affected through the state it changes: they call a method reading what it writes. Those '
                . 'reading what it records (the method tests the constant it writes, INFERRED) come first, then those reading '
                . 'all of it (AMBIGUOUS), in the modules of the direct dependents first. Not followed further:';
            foreach ($slice($groups['state']) as $class) {
                $lines[] = \sprintf('  - %s [%s]  %s', $this->query->label($class->class), $class->confidence->value, $this->classLocation($class->class));
                array_push($lines, ...$sites($class));
            }
            $more($groups['state']);
        }

        foreach ([
            'tests' => ['tests', 'Tests to run:'],
            'stateTests' => ['tests', 'Tests possibly affected through the state (they call a method reading it; INFERRED ones, then the modules of the direct dependents, first):'],
            'helpers' => ['helpers', 'Test helpers on the way (fakers, fixtures, base classes):'],
        ] as $key => [$name, $title]) {
            if (!$wanted($name) || $groups[$key] === []) {
                continue;
            }
            $lines[] = '';
            $lines[] = $title;
            foreach ($slice($groups[$key]) as $class) {
                $lines[] = \sprintf('  - %s%s  %s', $this->query->label($class->class), $key === 'stateTests' ? ' [' . $class->confidence->value . ']' : '', $this->classLocation($class->class));
                if ($key !== 'helpers') {
                    array_push($lines, ...$sites($class));
                }
            }
            $more($groups[$key]);
        }

        return implode("\n", $lines);
    }

    private function classLocation(string $class): string
    {
        $node = $this->query->graph()->node($class);

        return $node === null ? '' : $this->location($node);
    }

    /**
     * @param list<LayerViolation> $violations
     *
     * @return array<string, int> "domain -> infrastructure" => class dependencies, largest first
     */
    private function violationPairs(array $violations): array
    {
        $pairs = [];
        foreach ($violations as $violation) {
            $pair = $violation->fromLayer . ' -> ' . $violation->toLayer;
            $pairs[$pair] = ($pairs[$pair] ?? 0) + 1;
        }
        arsort($pairs);

        return $pairs;
    }

    private function violation(LayerViolation $violation): string
    {
        $node = $this->query->graph()->node(explode('::', $violation->edge->source)[0]);

        return \sprintf(
            '%s -> %s: %s%s  %s',
            $violation->from,
            $violation->to,
            $this->formatEdge($violation->edge),
            $violation->edges > 1 ? \sprintf(' (+%d more)', $violation->edges - 1) : '',
            $node === null ? '' : $this->location($node),
        );
    }

    /**
     * @param array<string, int> $counts confidence value => count
     */
    private function confidenceCounts(array $counts): string
    {
        $parts = [];
        foreach (['EXTRACTED', 'INFERRED', 'AMBIGUOUS'] as $confidence) {
            if (isset($counts[$confidence])) {
                $parts[] = $counts[$confidence] . ' ' . $confidence;
            }
        }

        return $parts === [] ? 'none' : implode(', ', $parts);
    }

    private function namespaceGroup(NamespaceGroup $group): string
    {
        return \sprintf('%s (%d)%s', $group->name, $group->classes, $group->layers === [] ? '' : ' [' . $this->counts(\array_slice($group->layers, 0, 4, true)) . ']');
    }

    /**
     * @param array<string, int> $counts
     */
    private function counts(array $counts): string
    {
        return implode(', ', array_map(static fn (int|string $name, int $count): string => $name . ' ' . $count, array_keys($counts), $counts));
    }

    private function percent(int $part, CallStats $calls): string
    {
        return $calls->total() === 0 ? '-' : \sprintf('%.0f%%', 100 * $part / $calls->total());
    }

    private function formatEdge(Edge $edge): string
    {
        $lines = $edge->lines();

        return \sprintf(
            '%s --%s--> %s [%s]%s',
            $this->query->label($edge->source),
            $edge->relation->value,
            $this->query->label($edge->target),
            $edge->confidence->value,
            $lines === [] ? '' : ' at L' . implode(', L', \array_slice($lines, 0, 8)) . (\count($lines) > 8 ? ', ...' : ''),
        );
    }

    /**
     * Every route when there are few, otherwise the routes grouped by service and path prefix.
     *
     * @param list<RouteEntry> $routes
     *
     * @return list<string>
     */
    private function routeList(array $routes): array
    {
        if (\count($routes) <= self::ROUTES_LISTED) {
            $lines = ['## Routes', 'Method, path, controller, routing file. query_graph("routes /path") or get_node on a route gives its neighbours.'];
            foreach ($routes as $route) {
                $lines[] = \sprintf(
                    '- %s%s -> %s (%s)',
                    $route->route->service === null ? '' : $route->route->service . ': ',
                    $route->route->label,
                    $route->handler === null ? 'no controller in the graph' : $route->handler->label,
                    $this->location($route->route),
                );
            }

            return $lines;
        }

        // A prefix holding more than a third of the routes (/api) is split by its next literal segment (/api/orders).
        $groups = $this->routeGroups($routes, 1);
        foreach ($groups as $key => $group) {
            if ($group['count'] > max(10, intdiv(\count($routes), 3))) {
                $deeper = $this->routeGroups($group['routes'], $group['depth'] + 1);
                // Only when it groups: /orders/1, /orders/2... are no better than /orders.
                if (\count($deeper) > 1 && \count($deeper) <= intdiv($group['count'], 2)) {
                    unset($groups[$key]);
                    $groups += $deeper;
                }
            }
        }
        uasort($groups, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        $lines = [
            '## Routes',
            \sprintf(
                '%d routes, by path prefix (count, routing files). query_graph("routes /prefix") lists those of a prefix with their controllers.',
                \count($routes),
            ),
        ];
        foreach (\array_slice($groups, 0, self::ROUTES_LISTED, true) as $key => $group) {
            $files = array_keys($group['files']);
            $lines[] = \sprintf('- %s: %d (%s%s)', $key, $group['count'], implode(', ', \array_slice($files, 0, 3)), \count($files) > 3 ? ', ...' : '');
        }
        if (\count($groups) > self::ROUTES_LISTED) {
            $lines[] = \sprintf('- ... %d more prefixes', \count($groups) - self::ROUTES_LISTED);
        }

        return $lines;
    }

    /**
     * Routes by the first $depth literal segments of their path, and the placeholders before them: /{_locale}/account.
     *
     * @param list<RouteEntry> $routes
     *
     * @return array<string, array{count: int, depth: int, files: array<string, true>, routes: list<RouteEntry>}>
     */
    private function routeGroups(array $routes, int $depth): array
    {
        $groups = [];
        foreach ($routes as $route) {
            $prefix = '';
            $literals = 0;
            foreach (explode('/', trim($route->path, '/')) as $segment) {
                $prefix .= '/' . $segment;
                if (!str_starts_with($segment, '{') && !str_starts_with($segment, ':') && ++$literals === $depth) {
                    break;
                }
            }
            $key = ($route->route->service === null ? '' : $route->route->service . ': ') . $prefix;
            $groups[$key]['count'] = ($groups[$key]['count'] ?? 0) + 1;
            $groups[$key]['depth'] = $depth;
            $groups[$key]['files'][(string) $route->route->file] = true;
            $groups[$key]['routes'][] = $route;
        }

        return $groups;
    }

    private function location(Node $node): string
    {
        if ($node->file === null) {
            return '(external)';
        }

        return $node->line === null ? $node->file : \sprintf('%s L%d', $node->file, $node->line);
    }
}
