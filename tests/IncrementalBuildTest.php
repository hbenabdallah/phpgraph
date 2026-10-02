<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Builder\BuildResult;
use PhpGraph\Builder\GraphBuilder;
use PhpGraph\Extractor\CachingExtractor;
use PhpGraph\Extractor\FileExtraction;
use PhpGraph\Extractor\FileExtractor;
use PhpGraph\Extractor\PhpFileExtractor;
use PhpGraph\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A rebuild in the same process reads only the files that changed, resolves again only their calls when no
 * declaration changed, and gives exactly the graph of a full build.
 */
final class IncrementalBuildTest extends TestCase
{
    use TemporaryProject;

    private const SOURCES = [
        'src/Order.php' => 'namespace App; class Order { public function total(): int { return 0; } public function lines(): Lines { return new Lines(); } }',
        'src/Lines.php' => 'namespace App; class Lines { public function count(): int { return 0; } }',
        'src/Cart.php' => 'namespace App; class Cart { public function sum(Order $order): int { return $order->lines()->count() + $order->total(); } }',
        'src/Checkout.php' => 'namespace App; class Checkout { public function run(Cart $cart, $any): void { $cart->sum(new Order()); $any->total(); } }',
    ];

    /**
     * @return iterable<string, array{array<string, string>, list<string>}>
     */
    public static function changes(): iterable
    {
        yield 'a method body' => [['src/Checkout.php' => 'namespace App; class Checkout { public function run(Cart $cart, $any): void { $cart->sum(new Order()); } }'], []];
        yield 'a return type' => [['src/Order.php' => 'namespace App; class Order { public function total(): int { return 0; } public function lines(): Cart { return new Cart(); } }'], []];
        yield 'a method added where a call was ambiguous' => [['src/Lines.php' => 'namespace App; class Lines { public function count(): int { return 0; } public function total(): int { return 0; } }'], []];
        yield 'a file added' => [['src/Invoice.php' => 'namespace App; class Invoice { public function total(): int { return 0; } }'], []];
        yield 'a file removed' => [[], ['src/Lines.php']];
    }

    /**
     * @param array<string, string> $write
     * @param list<string>          $remove
     */
    #[DataProvider('changes')]
    public function testARebuildEqualsAFullBuild(array $write, array $remove): void
    {
        $this->write(self::SOURCES);
        $builder = new GraphBuilder(new PhpFileExtractor());
        $state = $builder->build($this->root)->state;

        $this->change($write, $remove);
        $incremental = $builder->build($this->root, [], null, $state);

        self::assertSame($this->snapshot((new GraphBuilder(new PhpFileExtractor()))->build($this->root)), $this->snapshot($incremental));
    }

    public function testReadsOnlyTheFilesThatChanged(): void
    {
        $this->write(self::SOURCES);
        $extractor = new CountingExtractor();
        $builder = new GraphBuilder($extractor);
        $state = $builder->build($this->root)->state;
        $extractor->files = [];

        $this->change(['src/Checkout.php' => 'namespace App; class Checkout { public function run(Cart $cart): void {} }'], []);
        $builder->build($this->root, [], null, $state);

        self::assertSame(['src/Checkout.php'], $extractor->files);
    }

    public function testTheCacheFileKeepsTheFilesAProcessDidNotReadAgain(): void
    {
        $this->write(self::SOURCES);
        $cache = $this->root . '/cache.bin';
        $extractor = new CachingExtractor(new PhpFileExtractor(), $cache);
        $builder = new GraphBuilder($extractor);
        $state = $builder->build($this->root . '/src')->state;
        $extractor->save();

        $this->change(['src/Checkout.php' => 'namespace App; class Checkout {}'], []);
        $state = $builder->build($this->root . '/src', [], null, $state)->state;
        $extractor->save(array_keys($state->files ?? []));

        $fresh = new CachingExtractor(new PhpFileExtractor(), $cache);
        (new GraphBuilder($fresh))->build($this->root . '/src');
        self::assertSame(['parsed' => 0, 'reused' => 4], $fresh->stats());
    }

    /**
     * @param array<string, string> $write
     * @param list<string>          $remove
     */
    private function change(array $write, array $remove): void
    {
        // A modification time in the past second would look unchanged: the test moves it forward.
        foreach ($write as $path => $content) {
            $this->write([$path => $content]);
            touch($this->root . '/' . $path, time() + 10);
        }
        foreach ($remove as $path) {
            unlink($this->root . '/' . $path);
        }
        clearstatcache();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(BuildResult $result): array
    {
        $edges = array_map(static fn ($edge): string => $edge->key() . ' ' . $edge->confidence->value, $result->graph->edges());
        sort($edges);
        $nodes = array_keys($result->graph->nodes());
        sort($nodes);

        return ['nodes' => $nodes, 'edges' => $edges, 'calls' => $result->calls->toArray(), 'unresolved' => $result->mostUnresolvedMethods];
    }
}

/**
 * Records the files it is asked to extract.
 */
final class CountingExtractor implements FileExtractor
{
    /** @var list<string> */
    public array $files = [];

    private readonly PhpFileExtractor $extractor;

    public function __construct()
    {
        $this->extractor = new PhpFileExtractor();
    }

    public function extract(string $code, string $relativePath): FileExtraction
    {
        $this->files[] = $relativePath;

        return $this->extractor->extract($code, $relativePath);
    }
}
