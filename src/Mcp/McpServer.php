<?php

declare(strict_types=1);

namespace PhpGraph\Mcp;

use PhpGraph\Presentation\OutlineReport;
use PhpGraph\Presentation\TextPresenter;
use PhpGraph\Query\Direction;
use PhpGraph\Query\GraphQueryProvider;
use PhpGraph\Values;

/**
 * Not final: a test replaces handle() to check what run() writes.
 */
class McpServer
{
    /**
     * The MCP protocol versions this server speaks, oldest first. It answers with the client's version when it is one
     * of them, else with the latest: the client then decides whether it can go on (MCP lifecycle).
     */
    public const PROTOCOLS = ['2024-11-05', '2025-03-26', '2025-06-18', '2025-11-25'];

    private const NAME = 'phpgraph';


    private const INSTRUCTIONS = 'Knowledge graph of this PHP codebase, built by static analysis: deterministic facts, no guesses beyond '
        . 'what each confidence level says. Use it before grep, glob or reading files to explore or change the code, then read only the files it '
        . 'points to. To explain a feature or how something works, call outline first. Call impact_of before changing a class or a method, '
        . 'instead of searching for its usages with grep. Use get_node or get_neighbors to read one class or method. Relations are EXTRACTED (read in the code), INFERRED (resolved from declared '
        . 'types) or AMBIGUOUS (guessed from a unique method name). Routes (route:GET /orders), message channels and API schema operations are nodes '
        . 'too; in a multi-service repository ids read service@Class. The graph rebuilds itself when the code changes.';

    /** What the tools left out by default add to the instructions. */
    private const MORE_INSTRUCTIONS = ' Also: overview for the stack, structure and gaps of the graph, query_graph to find code by topic, '
        . 'shortest_path to see how two pieces of code connect, god_nodes for the hubs.';

    /**
     * The tools listed by default. Each listed tool is read again on every turn of the agent: on 10 November 2026 agents
     * never called the others, which cost them about 1 000 tokens per turn. Those stay callable and are listed with
     * `serve --all-tools`.
     */
    private const DEFAULT_TOOLS = ['get_node', 'get_neighbors', 'impact_of', 'outline'];

    /** The executable as it was when the server started: size, date and inode, null when not run from a PHAR. */
    private readonly ?string $stamp;

    /**
     * @param string $executable the PHAR or the binary running the server (`Phar::running(false)`), '' for none
     */
    public function __construct(
        private readonly GraphQueryProvider $provider,
        private readonly string $version = 'dev',
        private readonly string $executable = '',
        private readonly bool $allTools = false,
    ) {
        $this->stamp = $executable === '' ? null : self::stamp($executable);
    }

    /**
     * @param resource $input
     * @param resource $output
     */
    public function run($input = STDIN, $output = STDOUT): void
    {
        while (true) {
            $line = fgets($input);
            if ($line === false) {
                // On a socket (Node, hence Claude Code, gives its children socketpairs), fgets also gives up after the
                // stream timeout: only the end of the stream ends the session.
                if (feof($input)) {
                    return;
                }

                continue;
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $request = json_decode($line, true);
            $response = \is_array($request)
                ? $this->handle($request)
                : $this->error(null, -32700, 'Parse error');

            if ($response !== null) {
                fwrite($output, $this->encode($response) . "\n");
                fflush($output);
            }
        }
    }

    /**
     * The response to a request, null for a notification: one of the shapes of initialize(), tools(), callTool() or
     * error(). Open, as run() sends whatever a subclass answers (a test answers what JSON cannot encode).
     *
     * @param array<mixed> $request
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

        // PHP reads the classes of a PHAR from its file when it first needs them: once another version replaced it,
        // it would read them at the old offsets of the new file and print source code in place of the response.
        if ($this->stamp !== null && self::stamp($this->executable) !== $this->stamp) {
            return $this->error($id, -32603, sprintf(
                'phpgraph was replaced by another version (%s) while this server was running: restart the MCP server (in Claude Code: /mcp, then reconnect phpgraph).',
                $this->executable,
            ));
        }

        try {
            $result = match ($method) {
                'initialize' => $this->initialize($params),
                'ping' => new \stdClass(),
                'tools/list' => ['tools' => $this->listed()],
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
     * @param array<mixed> $params
     *
     * @return array{protocolVersion: string, capabilities: array{tools: \stdClass}, serverInfo: array{name: string, version: string}, instructions: string}
     */
    private function initialize(array $params): array
    {
        $note = $this->provider->projectNote();

        return [
            'protocolVersion' => \in_array($params['protocolVersion'] ?? null, self::PROTOCOLS, true) ? $params['protocolVersion'] : self::PROTOCOLS[\count(self::PROTOCOLS) - 1],
            'capabilities' => ['tools' => new \stdClass()],
            'serverInfo' => ['name' => self::NAME, 'version' => $this->version],
            'instructions' => ($note === null ? '' : $note . "\n\n") . self::INSTRUCTIONS . ($this->allTools ? self::MORE_INSTRUCTIONS : ''),
        ];
    }

    /**
     * @return list<array{name: string, description: string, inputSchema: array{type: string, properties: \stdClass|array<string, array{type: string, description: string, enum?: list<string>}>, required?: list<string>}}>
     */
    private function listed(): array
    {
        if ($this->allTools) {
            return $this->tools();
        }

        return array_values(array_filter($this->tools(), static fn (array $tool): bool => \in_array($tool['name'], self::DEFAULT_TOOLS, true)));
    }

    /**
     * Every tool, listed or not: a client may still call one it knows.
     *
     * @return list<array{name: string, description: string, inputSchema: array{type: string, properties: \stdClass|array<string, array{type: string, description: string, enum?: list<string>}>, required?: list<string>}}>
     */
    private function tools(): array
    {
        $text = static fn (string $description): array => ['type' => 'string', 'description' => $description];
        $integer = static fn (string $description): array => ['type' => 'integer', 'description' => $description];

        $name = $text('Short or fully qualified name, Class::method, route (POST /orders), channel or API operation; '
            . 'service@Name in a multi-service repository.');

        return [
            [
                'name' => 'overview',
                'description' => 'Start here on an unfamiliar project: the Composer stack, the namespace tree with its layer-like segments '
                    . '(Domain, Infrastructure...), class suffix counts, routes, messages and handlers, API schemas, services, breaks of the layer '
                    . 'rules, and what the graph cannot see (unknown receivers, missing vendor/, parse failures).',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'query_graph',
                'description' => 'Find the code about a topic when you do not know the class names (to explain a feature, call outline). '
                    . 'Matches the words of the question against class, method and function names, not semantic: use words likely in names '
                    . '("stock availability"). Returns the best matches, the rest of their namespace and the neighbours on the topic. Test code '
                    . 'only when the question mentions tests; routes when it mentions routes, endpoints or a path ("GET /courses").',
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
                'description' => 'One class, interface, trait, enum, method or function: kind, file, line and every relation in and out, with '
                    . 'its confidence. Lists the other nodes matching the name.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['name' => $name],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'get_neighbors',
                'description' => 'Like get_node in one direction: "in" what uses it (callers, implementations, subclasses, instantiations), '
                    . '"out" what it depends on.',
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
                'description' => 'Call this before changing a class or a method, instead of searching for its usages with grep. What may break when it changes: every class that depends on it, directly or '
                    . 'through others (callers, subclasses, implementations, code that instantiates or references it, senders '
                    . 'and handlers of a message), nearest first, each with the relation that reaches it and the weakest '
                    . 'confidence on the way, then its methods reaching the change with the source lines of their calls. Follows methods, not whole classes: a class '
                    . 'is reached only through the methods that use the change. Also reaches the callers of the interface or '
                    . 'parent method a method implements (INFERRED), and, in a separate list, the code possibly affected '
                    . 'through the state the method writes. Lists the tests to run apart, found through test helpers (fakers). '
                    . 'Stops at a service receiving the change among others (a tagged collection) and at inherited code '
                    . 'shared with other subclasses, which are listed but not followed. Lists the routes reaching the change, each with its chain down to the change. '
                    . 'A large impact comes compact (routes cut, farther classes by name, indirect tests counted) and still names every direct dependent: '
                    . 'the default answer is the one to use, other options are several times larger.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => $name,
                        'depth' => $integer('How many relations away to follow (default 3).'),
                        'limit' => $integer('Entries per list (default 40). Leave it out.'),
                        'section' => ['type' => 'string', 'enum' => TextPresenter::IMPACT_SECTIONS, 'description' => 'Only one list, uncut.'],
                        'format' => ['type' => 'string', 'enum' => TextPresenter::IMPACT_FORMATS, 'description' => 'Leave it out: text (default). full spells out every relation, about 5 times larger; json for scripts.'],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'outline',
                'description' => 'Call this first, before grep or reading files, to explain or document a feature or how something works '
                    . '("how does stock reservation work"). From the classes the topic names: the cluster by namespace and layer (kind, docblock '
                    . 'summary, public signatures, constants), the interfaces and base classes around it, the flow from the routes, behaviour read '
                    . 'in the bodies (guarded throws, branches), container wiring, users and untested classes. A topic naming a method '
                    . '(OrderService::place) outlines what it runs, 3 calls deep.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'topic' => $text('The feature in words or class names ("stock reservation"), or one method ("OrderService::place").'),
                        'section' => ['type' => 'string', 'enum' => OutlineReport::SECTIONS, 'description' => 'Only one section, uncut: what a "+N more: section ..." line names.'],
                        'format' => ['type' => 'string', 'enum' => TextPresenter::IMPACT_FORMATS, 'description' => 'text (default, about 15 KB), full (every list uncut, often too large: prefer section) or json.'],
                    ],
                    'required' => ['topic'],
                ],
            ],
            [
                'name' => 'shortest_path',
                'description' => 'How two pieces of code are connected: the shortest chain of relations, following dependencies first, '
                    . 'else in any direction.',
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
                'description' => 'The most connected classes and methods: the hubs whose change reaches the most code.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['limit' => $integer('Number of nodes (default 15).')],
                ],
            ],
        ];
    }

    /**
     * @param array<mixed> $params
     *
     * @return array{content: list<array{type: string, text: string}>, structuredContent?: \stdClass, isError: bool}
     */
    private function callTool(array $params): array
    {
        $name = $params['name'] ?? null;
        $arguments = \is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if (!\is_string($name) || !\in_array($name, array_column($this->tools(), 'name'), true)) {
            throw new \InvalidArgumentException(sprintf('Unknown tool: %s', \is_string($name) ? $name : 'null'));
        }

        $format = \is_string($arguments['format'] ?? null) && \in_array($arguments['format'], TextPresenter::IMPACT_FORMATS, true) ? $arguments['format'] : 'text';
        try {
            $presenter = new TextPresenter($this->provider->get());

            $text = match ($name) {
                'query_graph' => $presenter->query(
                    $this->requireString($arguments, 'question'),
                    max(1, Values::number($arguments['depth'] ?? 2)),
                    max(1, Values::number($arguments['limit'] ?? 40)),
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
                    $format,
                    \is_string($arguments['section'] ?? null) && \in_array($arguments['section'], OutlineReport::SECTIONS, true) ? $arguments['section'] : null,
                ),
                'impact_of' => $presenter->impact(
                    $this->requireString($arguments, 'name'),
                    max(1, Values::number($arguments['depth'] ?? 3)),
                    max(0, Values::number($arguments['limit'] ?? 40)),
                    \is_string($arguments['section'] ?? null) && \in_array($arguments['section'], TextPresenter::IMPACT_SECTIONS, true) ? $arguments['section'] : null,
                    $format,
                ),
                default => $presenter->godNodes(max(1, Values::number($arguments['limit'] ?? 15))),
            };
            $structured = $format === 'json' && \in_array($name, ['outline', 'impact_of'], true) ? self::structured($text) : null;
            $warning = $presenter->warning();
        } catch (\InvalidArgumentException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            return ['content' => [['type' => 'text', 'text' => $exception->getMessage()]], 'isError' => true];
        }

        if ($structured === null) {
            $warned = $warning !== null && $name !== 'overview' ? $warning . "\n\n" . $text : $text;

            return ['content' => [['type' => 'text', 'text' => $warned]], 'isError' => false];
        }
        // The JSON stays parseable: the warning goes in a block of its own.
        $content = [['type' => 'text', 'text' => $text]];
        if ($warning !== null) {
            $content[] = ['type' => 'text', 'text' => $warning];
        }

        return ['content' => $content, 'structuredContent' => $structured, 'isError' => false];
    }

    /**
     * The JSON of an answer as structured content (protocol 2025-06-18), the shape of docs/schemas/; none for an
     * error, which the text says.
     */
    private static function structured(string $json): ?\stdClass
    {
        $value = json_decode($json);

        return $value instanceof \stdClass && !property_exists($value, 'error') ? $value : null;
    }

    /**
     * @param array<mixed> $arguments
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
     * One line of JSON whatever the response holds: invalid UTF-8 becomes U+FFFD, and a response that cannot be encoded
     * anyway is an error with its id. Never an empty line: the client would wait for the answer forever.
     *
     * @param array<string, mixed> $response
     */
    private function encode(array $response): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        $json = json_encode($response, $flags);

        return $json !== false
            ? $json
            : (string) json_encode($this->error($response['id'] ?? null, -32603, 'The response could not be encoded as JSON: ' . json_last_error_msg()), $flags);
    }

    private static function stamp(string $file): string
    {
        clearstatcache(true, $file);
        $stat = @stat($file);

        return $stat === false ? 'missing' : $stat['size'] . ':' . $stat['mtime'] . ':' . $stat['ino'];
    }

    /**
     * @return array{jsonrpc: string, id: mixed, error: array{code: int, message: string}}
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
