<?php

declare(strict_types=1);

namespace PhpGraph\Mcp;

use PhpGraph\Presentation\OutlineReport;
use PhpGraph\Presentation\TextPresenter;
use PhpGraph\Query\Direction;
use PhpGraph\Query\GraphQueryProvider;

final class McpServer
{
    /**
     * The MCP protocol versions this server speaks, oldest first. It answers with the client's version when it is one
     * of them, else with the latest: the client then decides whether it can go on (MCP lifecycle).
     */
    public const PROTOCOLS = ['2024-11-05', '2025-03-26', '2025-06-18', '2025-11-25'];

    private const NAME = 'phpgraph';


    private const INSTRUCTIONS = 'Knowledge graph of this PHP codebase, built by static analysis: deterministic facts, no guesses beyond '
        . 'what each confidence level says. Call overview first: it gives the stack, the structure and what the graph cannot see. '
        . 'To explain a feature or how something works, call outline first, not query_graph (its classes, families, flow, behaviour, wiring and tests in one answer: '
        . 'read only the files it points to). Use query_graph to find code by topic when you do not know where it is, get_node or get_neighbors to read one class or method, and shortest_path '
        . 'to see how two pieces of code are connected, impact_of before changing a class or a method. Relations are EXTRACTED (read in the code), INFERRED (resolved from '
        . 'declared types) or AMBIGUOUS (guessed from a unique method name). Routes (route:GET /orders) and message '
        . 'channels are nodes too; in a multi-service repository ids read service@Class and services meet through '
        . 'contracts (shared message classes, channels, HTTP routes). The graph rebuilds itself when the code changes.';

    public function __construct(
        private readonly GraphQueryProvider $provider,
        private readonly string $version = 'dev',
    ) {
    }

    /**
     * @param resource $input
     * @param resource $output
     */
    public function run($input = STDIN, $output = STDOUT): void
    {
        while (($line = fgets($input)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $request = json_decode($line, true);
            $response = \is_array($request)
                ? $this->handle($request)
                : $this->error(null, -32700, 'Parse error');

            if ($response !== null) {
                fwrite($output, json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
                fflush($output);
            }
        }
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>|null
     */
    public function handle(array $request): ?array
    {
        if (!\array_key_exists('id', $request)) {
            return null;
        }

        $id = $request['id'];
        $method = $request['method'] ?? null;
        $params = \is_array($request['params'] ?? null) ? $request['params'] : [];

        if (!\is_string($method)) {
            return $this->error($id, -32600, 'Invalid request');
        }

        try {
            $result = match ($method) {
                'initialize' => $this->initialize($params),
                'ping' => new \stdClass(),
                'tools/list' => ['tools' => $this->tools()],
                'tools/call' => $this->callTool($params),
                default => null,
            };
        } catch (\InvalidArgumentException $exception) {
            return $this->error($id, -32602, $exception->getMessage());
        }

        if ($result === null) {
            return $this->error($id, -32601, sprintf('Method not found: %s', $method));
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        return [
            'protocolVersion' => \in_array($params['protocolVersion'] ?? null, self::PROTOCOLS, true) ? $params['protocolVersion'] : self::PROTOCOLS[\count(self::PROTOCOLS) - 1],
            'capabilities' => ['tools' => new \stdClass()],
            'serverInfo' => ['name' => self::NAME, 'version' => $this->version],
            'instructions' => self::INSTRUCTIONS,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tools(): array
    {
        $text = static fn (string $description): array => ['type' => 'string', 'description' => $description];
        $integer = static fn (string $description): array => ['type' => 'integer', 'description' => $description];

        $name = $text('Short name (OrderRepository), fully qualified name (App\\Sales\\Domain\\OrderRepository), method '
            . '(OrderRepository::save), route (POST /orders) or channel (ticket.create). In a multi-service repository, '
            . 'prefix with the service: billing@OrderRepository.');

        return [
            [
                'name' => 'overview',
                'description' => 'Start here on an unfamiliar PHP project. Returns: the Composer stack per application (framework, '
                    . 'ORM, message bus, test and analysis tools, with versions); the namespace tree of application code, each '
                    . 'branch annotated with the layer-like segments found below it (Domain, Application, Infrastructure, Adapter, '
                    . 'Model, Controller...) so you can tell bounded contexts and layers; counts of class name suffixes (Handler, '
                    . 'Repository, Controller...); and the gaps of the graph: share of method calls whose receiver type is unknown, '
                    . 'the method names most often unresolved, missing vendor/, parse failures, duplicate class names, and what is '
                    . 'not modelled at all; the dependencies breaking the layer rules (domain must not depend on '
                    . 'infrastructure...) and between bounded contexts; the services of a multi-service repository; HTTP '
                    . 'routes (entry points): each route with method, path, controller and routing file when there are up to '
                    . '30, else counts by path prefix; routes whose controller class exists nowhere; HTTP calls; messages, their handlers and what is left unlinked. Test code is '
                    . 'counted apart.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'query_graph',
                'description' => 'Find the code about a topic when you do not know the class names. To explain how a feature '
                    . 'works ("explain the notification validation system"), call outline instead: it answers in one call what '
                    . 'query_graph and several get_node calls would. Matches the words of the '
                    . 'question against the words of class, method and function names (validated finds Validator, prices finds '
                    . 'PriceCalculator; rare words weigh more than common ones; not semantic search: use words likely to appear '
                    . 'in names, such as "stock availability" or "invoice payment"), then returns up to 6 best matches, the other '
                    . 'classes of their namespace (the module asked about) and the neighbours that are about the question too, as relations (calls, handled_by, receives...) with confidence. '
                    . 'Test code only when the question mentions tests. A question '
                    . 'about routes (the words routes, endpoints, HTTP or URL, or a path such as /orders/{id}, with or without a '
                    . 'method such as GET) returns the matching route nodes and their controllers; its other words narrow them '
                    . 'by path or routing file: "mooc courses routes", "GET /courses".',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'question' => $text('Question or keywords, for example "how is stock availability computed" or "order routes".'),
                        'depth' => $integer('How many relations away from the matched nodes to go (default 2).'),
                        'limit' => $integer('Maximum number of nodes returned (default 40).'),
                    ],
                    'required' => ['question'],
                ],
            ],
            [
                'name' => 'get_node',
                'description' => 'Everything about one class, interface, trait, enum, method or function: kind, file and line, '
                    . 'and every relation in and out. Each relation has a confidence: EXTRACTED (read in the code), INFERRED '
                    . '(resolved from declared types, across files and through vendor/ signatures) or AMBIGUOUS (guessed: the '
                    . 'only method of the project with that name). When several nodes match the name, the others are listed.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['name' => $name],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'get_neighbors',
                'description' => 'Like get_node, in one direction. "in": what uses this node (callers, implementations, '
                    . 'subclasses, code that instantiates or references it), to judge the impact of a change. "out": what this '
                    . 'node depends on.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => $name,
                        'direction' => ['type' => 'string', 'enum' => ['in', 'out', 'both'], 'description' => '"in", "out" or "both" (default both).'],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'impact_of',
                'description' => 'What may break when a class or a method changes: every class that depends on it, directly or '
                    . 'through others (callers, subclasses, implementations, code that instantiates or references it, senders '
                    . 'and handlers of a message), nearest first, each with the relation that reaches it and the weakest '
                    . 'confidence on the way, then its methods reaching the change with the source lines of their calls '
                    . '(3 per class, all with limit 0). Follows methods, not whole classes: a class '
                    . 'is reached only through the methods that use the change. Also reaches the callers of the interface or '
                    . 'parent method a method implements (INFERRED), and, in a separate list, the code possibly affected '
                    . 'through the state the method writes (direct callers of methods reading the same properties, such as '
                    . 'all() or hasErrors() for add(), not followed further). Lists the tests to run apart, found through test helpers (fakers) without the depth limit. '
                    . 'Stops at a service receiving the change among others (a tagged collection) and at inherited code '
                    . 'shared with other subclasses, which are listed but not followed. Lists the routes reaching the change '
                    . 'without the depth limit, each with its chain down to the change, scoped by the injected lists on the way. Lists are cut at 40 entries: '
                    . 'pass limit 0 for complete lists, or section to get a single one.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => $name,
                        'depth' => $integer('How many relations away to follow (default 3).'),
                        'limit' => $integer('Entries per list (default 40); 0 for complete lists, instead of reading the cut ones node by node.'),
                        'section' => ['type' => 'string', 'enum' => TextPresenter::IMPACT_SECTIONS, 'description' => 'Only one list: direct (the call chain), routes, state (possibly affected through the state it changes), tests (to run), state-tests (reaching what reads the state), helpers.'],
                        'format' => ['type' => 'string', 'enum' => TextPresenter::IMPACT_FORMATS, 'description' => 'text (default, compact: one line per class with its call sites), full (every relation spelled out) or json.'],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'outline',
                'description' => 'Call this first, instead of query_graph, whenever you are asked to explain, describe or document '
                    . 'a feature or how something works ("explain the notification validation system", "how does stock '
                    . 'reservation work"), before reading any code. Returns its structural outline, enough to '
                    . 'read only the few files with non-obvious logic. From the classes the topic names, the cluster they '
                    . 'form, by namespace and layer, each with its kind, the first sentence of its docblock, its public '
                    . 'signatures, constants and enum cases; the interfaces and base classes around it with their number of '
                    . 'implementations by folder (0 stated) and the tags injecting them; the flow from the routes into it, '
                    . 'the closures it runs and the calls inside it; behaviour read in the bodies (guarded throws, early '
                    . 'returns, branches on constants, loop caps, state compared before and after); the container wiring '
                    . 'with service ids; its users (tests counted by module), the classes no test touches and those nothing uses.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'topic' => $text('The feature, in words or class names: "notification validation", "stock reservation".'),
                        'section' => ['type' => 'string', 'enum' => OutlineReport::SECTIONS, 'description' => 'Only one section, uncut: what a "+N more: section ..." line names.'],
                        'format' => ['type' => 'string', 'enum' => TextPresenter::IMPACT_FORMATS, 'description' => 'text (default, compact, about 15 KB), full (every list uncut: often too large to read inline; prefer section) or json.'],
                    ],
                    'required' => ['topic'],
                ],
            ],
            [
                'name' => 'shortest_path',
                'description' => 'How two pieces of code are connected: the shortest chain of relations from one node to the '
                    . 'other. Follows dependencies first (calls and references in their direction, from an interface to its '
                    . 'implementations, never through files or classes outside the project); otherwise ignores the direction. '
                    . 'The answer says which mode found the path.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'from' => $text('Start node: short name, fully qualified name or Class::method.'),
                        'to' => $text('End node: short name, fully qualified name or Class::method.'),
                    ],
                    'required' => ['from', 'to'],
                ],
            ],
            [
                'name' => 'god_nodes',
                'description' => 'The most connected classes and methods: the hubs most code depends on, often central domain '
                    . 'objects, shared services or base classes. Changing them has the widest impact.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['limit' => $integer('Number of nodes (default 15).')],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function callTool(array $params): array
    {
        $name = $params['name'] ?? null;
        $arguments = \is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if (!\is_string($name) || !\in_array($name, array_column($this->tools(), 'name'), true)) {
            throw new \InvalidArgumentException(sprintf('Unknown tool: %s', \is_string($name) ? $name : 'null'));
        }

        try {
            $presenter = new TextPresenter($this->provider->get());

            $text = match ($name) {
                'query_graph' => $presenter->query(
                    $this->requireString($arguments, 'question'),
                    max(1, (int) ($arguments['depth'] ?? 2)),
                    max(1, (int) ($arguments['limit'] ?? 40)),
                ),
                'get_node' => $presenter->explain($this->requireString($arguments, 'name')),
                'get_neighbors' => $presenter->explain(
                    $this->requireString($arguments, 'name'),
                    Direction::tryFrom(\is_string($arguments['direction'] ?? null) ? $arguments['direction'] : '') ?? Direction::Both,
                ),
                'shortest_path' => $presenter->path($this->requireString($arguments, 'from'), $this->requireString($arguments, 'to')),
                'overview' => $presenter->overview(),
                'outline' => $presenter->outline(
                    $this->requireString($arguments, 'topic'),
                    \is_string($arguments['format'] ?? null) && \in_array($arguments['format'], TextPresenter::IMPACT_FORMATS, true) ? $arguments['format'] : 'text',
                    \is_string($arguments['section'] ?? null) && \in_array($arguments['section'], OutlineReport::SECTIONS, true) ? $arguments['section'] : null,
                ),
                'impact_of' => $presenter->impact(
                    $this->requireString($arguments, 'name'),
                    max(1, (int) ($arguments['depth'] ?? 3)),
                    max(0, (int) ($arguments['limit'] ?? 40)),
                    \is_string($arguments['section'] ?? null) && \in_array($arguments['section'], TextPresenter::IMPACT_SECTIONS, true) ? $arguments['section'] : null,
                    \is_string($arguments['format'] ?? null) && \in_array($arguments['format'], TextPresenter::IMPACT_FORMATS, true) ? $arguments['format'] : 'text',
                ),
                default => $presenter->godNodes(max(1, (int) ($arguments['limit'] ?? 15))),
            };
            $warning = $presenter->warning();
            if ($warning !== null && $name !== 'overview') {
                $text = $warning . "\n\n" . $text;
            }
        } catch (\InvalidArgumentException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            return ['content' => [['type' => 'text', 'text' => $exception->getMessage()]], 'isError' => true];
        }

        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => false];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function requireString(array $arguments, string $key): string
    {
        $value = $arguments[$key] ?? null;
        if (!\is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException(sprintf('Missing required argument: %s', $key));
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
