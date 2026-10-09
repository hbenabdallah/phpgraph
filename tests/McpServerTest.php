<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Builder\GraphBuilder;
use PhpGraph\Mcp\McpServer;
use PhpGraph\Query\GraphQueryProvider;
use PhpGraph\Storage\JsonGraphStorage;
use PHPUnit\Framework\TestCase;

final class McpServerTest extends TestCase
{
    private string $graphPath;

    protected function setUp(): void
    {
        $this->graphPath = sys_get_temp_dir() . '/phpgraph-test-' . bin2hex(random_bytes(4)) . '/graph.json';
        (new JsonGraphStorage())->save((new GraphBuilder())->build(__DIR__ . '/Fixtures/src')->graph, $this->graphPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->graphPath);
        @rmdir(\dirname($this->graphPath));
    }

    public function testInitializeNegotiatesTheProtocolVersion(): void
    {
        $response = $this->call(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]);

        self::assertSame('2025-06-18', $response['result']['protocolVersion']);

        $latest = McpServer::PROTOCOLS[\count(McpServer::PROTOCOLS) - 1];
        foreach ([['protocolVersion' => '2099-01-01'], []] as $params) {
            $response = $this->call(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => $params]);
            self::assertSame($latest, $response['result']['protocolVersion'] ?? null, 'a version it does not speak: its latest');
        }
        self::assertSame('phpgraph', $response['result']['serverInfo']['name']);
        self::assertStringContainsString('Call overview first', $response['result']['instructions']);
    }

    public function testNotificationsReceiveNoResponse(): void
    {
        self::assertNull($this->server()->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
    }

    public function testListsTheToolsOverviewFirst(): void
    {
        $response = $this->call(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);

        self::assertSame(
            ['overview', 'query_graph', 'get_node', 'get_neighbors', 'impact_of', 'outline', 'shortest_path', 'god_nodes'],
            array_column($response['result']['tools'], 'name'),
        );
    }

    public function testCallsShortestPathTool(): void
    {
        $response = $this->call([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'shortest_path', 'arguments' => ['from' => 'PlaceOrderHandler', 'to' => 'DbalOrderRepository']],
        ]);

        self::assertFalse($response['result']['isError']);
        self::assertStringContainsString('Shortest path', $response['result']['content'][0]['text']);
    }

    public function testRejectsUnknownToolsAndMissingArguments(): void
    {
        $unknown = $this->call(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'nope']]);
        $missing = $this->call(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'get_node', 'arguments' => []]]);

        self::assertSame(-32602, $unknown['error']['code']);
        self::assertSame(-32602, $missing['error']['code']);
    }

    public function testReportsToolErrorWhenGraphIsMissing(): void
    {
        $server = new McpServer(new GraphQueryProvider('/nonexistent/graph.json'));
        $response = $this->call(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => ['name' => 'god_nodes']], $server);

        self::assertTrue($response['result']['isError']);
    }

    public function testOutlivesTheSocketTimeout(): void
    {
        // Node (Claude Code) gives its children a socketpair as stdin: a read there gives up after the socket timeout.
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($sockets);
        [$server, $client] = $sockets;
        stream_set_timeout($server, 1);

        // The client waits past the timeout, sends a ping, then closes its end when it exits.
        $ping = json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'ping']);
        $process = proc_open(
            [PHP_BINARY, '-r', sprintf('sleep(2); fwrite(STDOUT, %s);', var_export($ping . "\n", true))],
            [0 => ['file', '/dev/null', 'r'], 1 => $client, 2 => STDERR],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($client);

        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);
        $started = microtime(true);
        $this->server()->run($server, $output);
        $elapsed = microtime(true) - $started;
        proc_close($process);

        self::assertGreaterThan(1.5, $elapsed, 'the session lasted until the client closed');
        rewind($output);
        self::assertSame(['jsonrpc' => '2.0', 'id' => 7, 'result' => []], json_decode((string) stream_get_contents($output), true));
    }

    public function testStopsAtTheEndOfItsInput(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($sockets);
        [$server, $client] = $sockets;
        stream_set_timeout($server, 1);
        fwrite($client, json_encode(['jsonrpc' => '2.0', 'id' => 8, 'method' => 'ping']) . "\n");
        fclose($client);

        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);
        $started = microtime(true);
        $this->server()->run($server, $output);

        self::assertLessThan(1.0, microtime(true) - $started, 'it stops at the end of the stream, without waiting');
        rewind($output);
        self::assertSame(['jsonrpc' => '2.0', 'id' => 8, 'result' => []], json_decode((string) stream_get_contents($output), true));
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function call(array $request, ?McpServer $server = null): array
    {
        $response = ($server ?? $this->server())->handle($request);
        self::assertIsArray($response);

        return $response;
    }

    private function server(): McpServer
    {
        return new McpServer(new GraphQueryProvider($this->graphPath));
    }
}
