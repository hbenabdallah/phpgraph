<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Mcp\McpServer;
use PhpGraph\Project\BuildOptions;
use PhpGraph\Project\ProjectGraph;
use PhpGraph\Query\GraphQueryProvider;
use PHPUnit\Framework\TestCase;

/**
 * The zero-configuration MCP server: the graph is built on first use and rebuilt when the sources change.
 */
final class ProjectGraphTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/phpgraph-project-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src', 0777, true);
        $this->write('src/Order.php', 'namespace App; class Order {}');
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testBuildsTheGraphWhenItIsMissing(): void
    {
        $project = $this->project();

        self::assertTrue($project->refresh());
        self::assertFileExists($this->root . '/phpgraph-out/graph.json');
        self::assertFileExists($this->root . '/phpgraph-out/GRAPH_REPORT.md');
        self::assertFalse($project->refresh(), 'Unchanged sources are not rebuilt');
    }

    public function testRebuildsWhenAFileIsAddedEditedOrRemoved(): void
    {
        $project = $this->project();
        $project->refresh();

        $this->write('src/Invoice.php', 'namespace App; class Invoice {}');
        self::assertTrue($project->refresh(), 'added');

        $this->write('src/Order.php', 'namespace App; class Order { public function total(): int { return 0; } }');
        self::assertTrue($project->refresh(), 'edited');

        unlink($this->root . '/src/Invoice.php');
        self::assertTrue($project->refresh(), 'removed');
    }

    public function testAGraphFromAnEarlierRunIsRebuiltOnlyWhenStale(): void
    {
        $this->project()->refresh();

        self::assertFalse($this->project()->refresh(), 'A new server reuses an up-to-date graph');

        $this->write('src/Invoice.php', 'namespace App; class Invoice {}');
        self::assertTrue($this->project()->refresh(), 'A new server rebuilds a stale graph');
    }

    public function testChecksTheSourcesAtMostOncePerInterval(): void
    {
        $project = ProjectGraph::reusingSavedOptions($this->root, $this->root . '/phpgraph-out', null, checkInterval: 60.0);
        $project->refresh();

        $this->write('src/Invoice.php', 'namespace App; class Invoice {}');

        self::assertFalse($project->refresh());
    }

    public function testReusesTheOptionsOfTheLastBuild(): void
    {
        (new ProjectGraph($this->root, $this->root . '/phpgraph-out', new BuildOptions(['legacy'], false, 3)))->build();

        $options = $this->project()->options();

        self::assertSame(['legacy'], $options->excludes);
        self::assertFalse($options->readVendor);
        self::assertSame(3, $options->depth);
    }

    public function testTheMcpServerAnswersWithTheCurrentCode(): void
    {
        $server = new McpServer(GraphQueryProvider::forProject($this->project()));
        $getInvoice = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_node', 'arguments' => ['name' => 'Invoice']]];

        self::assertStringContainsString('No node', $this->text($server->handle($getInvoice)));

        $this->write('src/Invoice.php', 'namespace App; class Invoice {}');

        self::assertStringContainsString('App\Invoice', $this->text($server->handle($getInvoice)));
    }

    public function testAGraphBuiltByAnotherPhpgraphIsRebuilt(): void
    {
        $this->project()->build();
        $path = $this->root . '/phpgraph-out/graph.json';
        $data = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($data);
        $data['meta']['phpgraph']['builder'] = 'an older phpgraph';
        file_put_contents($path, json_encode($data));

        self::assertTrue($this->project()->refresh(), 'same sources, other builder');
        self::assertFalse($this->project()->refresh());
    }

    public function testAGraphInAnotherFormatIsRefusedThenRebuilt(): void
    {
        $this->project()->build();
        $path = $this->root . '/phpgraph-out/graph.json';
        file_put_contents($path, (string) preg_replace('/^\{\s*"version":\s*2/', '{"version": 1', (string) file_get_contents($path)));

        $message = null;
        try {
            (new \PhpGraph\Storage\JsonGraphStorage())->loadWithMeta($path);
        } catch (\RuntimeException $exception) {
            $message = $exception->getMessage();
        }
        self::assertStringContainsString('is in format 1, this phpgraph reads format 2', (string) $message);
        self::assertTrue($this->project()->refresh());
    }

    public function testTheOverviewNamesTheOtherLanguagesOfAMixedProject(): void
    {
        @mkdir($this->root . '/front/src', 0777, true);
        @mkdir($this->root . '/front/node_modules/lib', 0777, true);
        file_put_contents($this->root . '/front/src/app.ts', 'export {}');
        file_put_contents($this->root . '/front/src/orders.tsx', 'export {}');
        file_put_contents($this->root . '/front/src/vendor.min.js', '');
        file_put_contents($this->root . '/front/node_modules/lib/index.js', '');
        $server = new McpServer(GraphQueryProvider::forProject($this->project()));

        self::assertStringContainsString('Other languages, not in the graph: TypeScript (2 files).', $this->text($server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'overview', 'arguments' => []]])), 'minified and node_modules/ files left out');
        $initialize = $server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => []]);
        self::assertStringStartsWith('Knowledge graph', (string) ($initialize['result']['instructions'] ?? ''), 'some PHP: the usual instructions');
    }

    public function testAProjectWithoutPhpIsToldAtOnce(): void
    {
        unlink($this->root . '/src/Order.php');
        file_put_contents($this->root . '/src/Main.java', 'class Main {}');
        $server = new McpServer(GraphQueryProvider::forProject($this->project()));

        $initialize = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]);
        self::assertStringStartsWith('No PHP code: this project is written in Java (1 file).', (string) ($initialize['result']['instructions'] ?? ''), 'before any build');
        self::assertStringContainsString('its tools cannot help here', $this->text($server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'get_node', 'arguments' => ['name' => 'Main']]])));
    }

    private function project(): ProjectGraph
    {
        return ProjectGraph::reusingSavedOptions($this->root, $this->root . '/phpgraph-out', null, checkInterval: 0.0);
    }

    private function write(string $path, string $code): void
    {
        file_put_contents($this->root . '/' . $path, "<?php\n" . $code . "\n");
    }

    /**
     * @param array<string, mixed>|null $response
     */
    private function text(?array $response): string
    {
        $text = $response['result']['content'][0]['text'] ?? null;
        self::assertIsString($text);

        return $text;
    }
}
