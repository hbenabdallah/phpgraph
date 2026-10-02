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

    public function testInitializeEchoesClientProtocolVersion(): void
    {
        $response = $this->call(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]);

        self::assertSame('2025-06-18', $response['result']['protocolVersion']);
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
            ['overview', 'query_graph', 'get_node', 'get_neighbors', 'impact_of', 'shortest_path', 'god_nodes'],
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
