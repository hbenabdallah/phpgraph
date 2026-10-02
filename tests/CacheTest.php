<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Builder\GraphBuilder;
use PhpGraph\Extractor\CachingExtractor;
use PhpGraph\Extractor\PhpFileExtractor;
use PhpGraph\Graph\Graph;
use PhpGraph\Project\ProjectGraph;
use PhpGraph\Tests\Support\TemporaryProject;
use PHPUnit\Framework\TestCase;

/**
 * The incremental build: unchanged files are not parsed again, and the result is the same as a full build.
 */
final class CacheTest extends TestCase
{
    use TemporaryProject;

    private const SOURCES = [
        'src/Order.php' => 'namespace App; class Order { public function total(): int { return 0; } }',
        'src/Cart.php' => 'namespace App; class Cart { public function sum(Order $order): int { return $order->total(); } }',
        'src/Clock.php' => 'namespace App; class Clock {}',
    ];

    public function testParsesOnlyTheFilesThatChanged(): void
    {
        $this->write(self::SOURCES);
        $this->cachedBuild();

        $this->write(['src/Clock.php' => 'namespace App; class Clock { public function now(): int { return 0; } }']);
        [$graph, $stats] = $this->cachedBuild();

        self::assertSame(['parsed' => 1, 'reused' => 2], $stats);
        self::assertTrue($graph->hasNode('App\Clock::now'));
        self::assertSame($this->edges((new GraphBuilder())->build($this->root . '/src')->graph), $this->edges($graph), 'Same graph as a full build');
    }

    public function testForgetsTheFilesThatAreGone(): void
    {
        $this->write(self::SOURCES);
        $this->cachedBuild();
        unlink($this->root . '/src/Clock.php');

        [$graph] = $this->cachedBuild();

        self::assertFalse($graph->hasNode('App\Clock'));
    }

    public function testIgnoresACacheFileItCannotTrust(): void
    {
        $this->write(self::SOURCES);
        file_put_contents($this->root . '/cache.bin', "phpgraph-cache:other-version\n" . serialize(['x' => new \ArrayObject()]));
        [, $stats] = $this->cachedBuild();
        self::assertSame(['parsed' => 3, 'reused' => 0], $stats, 'Another version');

        $version = explode("\n", (string) file_get_contents($this->root . '/cache.bin'), 2)[0];
        file_put_contents($this->root . '/cache.bin', $version . "\n" . serialize(['x' => new \ArrayObject()]));
        [, $stats] = $this->cachedBuild();
        self::assertSame(['parsed' => 3, 'reused' => 0], $stats, 'Only extraction classes are read back');
    }

    public function testDoesNotRewriteAnUnchangedCache(): void
    {
        $this->write(self::SOURCES);
        $this->cachedBuild();
        $written = (string) file_get_contents($this->root . '/cache.bin');
        file_put_contents($this->root . '/cache.bin', $written . ' ');

        $this->cachedBuild();

        self::assertSame($written . ' ', file_get_contents($this->root . '/cache.bin'));
    }

    public function testAServerKeepsTheExtractionsInMemoryBetweenRebuilds(): void
    {
        $this->write(self::SOURCES);
        $project = new ProjectGraph($this->root, $this->root . '/phpgraph-out', checkInterval: 0.0);
        $project->build();
        unlink($this->root . '/phpgraph-out/cache.bin');

        $this->write(['src/Clock.php' => 'namespace App; class Clock { public function now(): int { return 0; } }']);
        self::assertTrue($project->refresh());

        self::assertTrue($project->lastBuild()?->graph()->hasNode('App\Clock::now'));
        self::assertTrue($project->lastBuild()->graph()->hasNode('App\Cart::sum'), 'Unchanged files come from memory, not from the deleted cache file');
    }

    /**
     * @return array{Graph, array{parsed: int, reused: int}}
     */
    private function cachedBuild(): array
    {
        $extractor = new CachingExtractor(new PhpFileExtractor(), $this->root . '/cache.bin');
        $graph = (new GraphBuilder($extractor))->build($this->root . '/src')->graph;
        $extractor->save();

        return [$graph, $extractor->stats()];
    }

    /**
     * @return list<string>
     */
    private function edges(Graph $graph): array
    {
        $edges = array_map(static fn ($edge): string => $edge->key(), $graph->edges());
        sort($edges);

        return $edges;
    }
}
