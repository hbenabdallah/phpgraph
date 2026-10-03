<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Builder\GraphBuilder;
use PhpGraph\Mcp\McpServer;
use PhpGraph\Project\ProjectGraph;
use PhpGraph\Query\GraphQueryProvider;
use PhpGraph\Tests\Support\TemporaryProject;
use PHPUnit\Framework\TestCase;

/**
 * Only the analysed project's .gitignore rules apply, never those of a repository above it; and a graph left empty
 * by those rules says so.
 */
final class GitignoreTest extends TestCase
{
    use TemporaryProject;

    public function testAParentRepositoryIgnoringTheProjectDoesNotEmptyTheGraph(): void
    {
        mkdir($this->root . '/.git');
        $this->write([
            '.gitignore' => "/var/\n",
            'var/bench/app/.gitignore' => "/generated/\n*.local.php\n!keep.local.php\n",
            'var/bench/app/src/Order.php' => 'namespace App; class Order {}',
            'var/bench/app/src/Draft.local.php' => 'namespace App; class Draft {}',
            'var/bench/app/src/keep.local.php' => 'namespace App; class Kept {}',
            'var/bench/app/generated/Proxy.php' => 'namespace App; class Proxy {}',
            'var/bench/app/src/Legacy/.gitignore' => "Old.php\n",
            'var/bench/app/src/Legacy/Old.php' => 'namespace App\Legacy; class Old {}',
        ]);

        $result = (new GraphBuilder())->build($this->root . '/var/bench/app');
        $directory = getcwd();
        chdir($this->root . '/var');
        $relative = (new GraphBuilder())->build('bench/app');
        chdir((string) $directory);

        self::assertSame(array_keys($result->graph->nodes()), array_keys($relative->graph->nodes()), 'a relative root reads the same files');
        self::assertNotNull($result->graph->node('App\Order'));
        self::assertNotNull($result->graph->node('App\Kept'), 'a negated rule re-includes the file');
        self::assertNull($result->graph->node('App\Draft'));
        self::assertNull($result->graph->node('App\Proxy'));
        self::assertNull($result->graph->node('App\Legacy\Old'), 'a nested .gitignore applies below its directory');
        self::assertSame(0, $result->phpFilesNotRead);
    }

    public function testAGraphEmptiedByTheRulesSaysSo(): void
    {
        $this->write([
            '.gitignore' => "src/\n",
            'src/Order.php' => 'namespace App; class Order {}',
        ]);

        $project = ProjectGraph::reusingSavedOptions($this->root, $this->root . '/phpgraph-out', null, checkInterval: 0.0);
        $server = new McpServer(GraphQueryProvider::forProject($project));
        $call = static fn (string $tool, array $arguments = []): string => (string) ($server->handle(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]],
        )['result']['content'][0]['text'] ?? '');

        self::assertStringContainsString('Empty graph: no PHP file was read, yet the directory holds 1 PHP files', $call('overview'));
        self::assertStringStartsWith('Empty graph', $call('get_node', ['name' => 'Order']));
    }
}
