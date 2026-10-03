<?php

declare(strict_types=1);

namespace PhpGraph\Mcp;

use PhpGraph\Presentation\TextPresenter;
use PhpGraph\Query\Direction;
use PhpGraph\Query\GraphQueryProvider;

final class McpServer
{
    private const DEFAULT_PROTOCOL = '2024-11-05';

    private const NAME = 'phpgraph';


    private const INSTRUCTIONS = 'Knowledge graph of this PHP codebase, built by static analysis: deterministic facts, no guesses beyond '
        . 'what each confidence level says. Call overview first: it gives the stack, the structure and what the graph cannot see. '
        . 'Then use query_graph to find code by topic, get_node or get_neighbors to read one class or method, and shortest_path '
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
            'protocolVersion' => \is_string($params['protocolVersion'] ?? null) ? $params['protocolVersion'] : self::DEFAULT_PROTOCOL,
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
                'description' => 'Find the code about a topic when you do not know the class names. Matches the words of the '
                    . 'question against class, method and function names (keywords, not semantic search: use words likely to '
                    . 'appear in names, such as "stock availability" or "invoice payment"), then returns the matched nodes and '
                    . 'their neighbourhood as relations (calls, implements, instantiates, references...) with confidence. A question '
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
                    . 'confidence on the way. Lists the test classes to run apart. Follows methods, not whole classes: a class '
                    . 'is reached only through the methods that use the change.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => $name,
                        'depth' => $integer('How many relations away to follow (default 3).'),
                    ],
                    'required' => ['name'],
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
                'impact_of' => $presenter->impact($this->requireString($arguments, 'name'), max(1, (int) ($arguments['depth'] ?? 3))),
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
