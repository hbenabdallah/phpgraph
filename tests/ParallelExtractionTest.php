<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Builder\GraphBuilder;
use PhpGraph\Builder\ParallelExtraction;
use PhpGraph\Extractor\CachingExtractor;
use PhpGraph\Extractor\FileExtraction;
use PhpGraph\Extractor\PhpFileExtractor;
use PhpGraph\Tests\Support\TemporaryProject;
use PHPUnit\Framework\TestCase;

final class ParallelExtractionTest extends TestCase
{
    use TemporaryProject;

    public function testWorkersExtractWhatTheProcessItselfWould(): void
    {
        $codes = $this->sources(90);
        $codes['src/Broken.php'] = "<?php\nclass Broken {";

        $alone = (new ParallelExtraction(new PhpFileExtractor(), 1))->extract($codes);
        $parallel = new ParallelExtraction(new PhpFileExtractor(), 3);
        $shared = $parallel->extract($codes);

        self::assertSame(3, $parallel->workersUsed(), 'pcntl is available where the tests run (bin/dev, CI)');
        self::assertEquals($alone, $shared);
        self::assertIsString($shared['src/Broken.php'], 'a file that cannot be parsed comes back as the parser error');
        self::assertInstanceOf(FileExtraction::class, $shared['src/Class0.php']);
    }

    public function testAFewFilesAreParsedWithoutWorkers(): void
    {
        $extraction = new ParallelExtraction(new PhpFileExtractor(), 4);
        $extraction->extract($this->sources(30));

        self::assertSame(0, $extraction->workersUsed(), 'forking costs more than parsing 30 files');
    }

    public function testTheCacheKeepsWhatTheWorkersParsed(): void
    {
        $codes = $this->sources(60);
        $cache = new CachingExtractor(new PhpFileExtractor(), $this->root . '/cache.bin');

        (new ParallelExtraction($cache, 2))->extract($codes);
        (new ParallelExtraction($cache, 2))->extract($codes);

        self::assertSame(['parsed' => \count($codes), 'reused' => \count($codes)], $cache->stats(), 'the second extraction reuses all the first one parsed');
        self::assertTrue($cache->isDirty());
    }

    public function testABuildWithWorkersGivesTheSameGraph(): void
    {
        $sources = [];
        foreach ($this->sources(80) as $path => $code) {
            $sources[$path] = substr($code, \strlen("<?php\n"));
        }
        $this->write($sources);

        $alone = (new GraphBuilder(workers: 1))->build($this->root);
        $parallel = (new GraphBuilder(workers: 4))->build($this->root);

        self::assertSame($alone->graph->toArray(), $parallel->graph->toArray());
        self::assertSame($alone->calls->toArray(), $parallel->calls->toArray());
    }

    /**
     * Classes calling the one before them, and a duplicate declaration: the first file read must still win.
     *
     * @return array<string, string>
     */
    private function sources(int $count): array
    {
        $codes = [];
        for ($index = 0; $index < $count; ++$index) {
            $previous = max(0, $index - 1);
            $codes["src/Class{$index}.php"] = "<?php\nnamespace App;\nclass Class{$index} {\n"
                . "    public function __construct(private Class{$previous} \$previous) {}\n"
                . "    public function run(): void { \$this->previous->run(); }\n}\n";
        }
        $codes['src/Duplicate.php'] = "<?php\nnamespace App;\nclass Class0 { public function other(): void {} }\n";

        return $codes;
    }
}
