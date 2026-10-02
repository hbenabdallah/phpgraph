<?php

declare(strict_types=1);

namespace PhpGraph\Tests\Support;

use PhpGraph\Builder\BuildResult;
use PhpGraph\Builder\GraphBuilder;
use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Graph;
use PhpGraph\Graph\Relation;

/**
 * A project written to a temporary directory, file by file, then built.
 */
trait TemporaryProject
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/phpgraph-' . bin2hex(random_bytes(4));
        mkdir($this->root);
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

    /**
     * @param array<string, string> $files path => content; PHP files get their opening tag
     */
    private function write(array $files): void
    {
        foreach ($files as $path => $content) {
            @mkdir(\dirname($this->root . '/' . $path), 0777, true);
            file_put_contents($this->root . '/' . $path, str_ends_with($path, '.php') ? "<?php\n" . $content . "\n" : $content);
        }
    }

    /**
     * @param array<string, string> $files
     */
    private function buildProject(array $files): BuildResult
    {
        $this->write($files);

        return (new GraphBuilder())->build($this->root);
    }

    private function hasEdge(Graph $graph, string $source, string $target, Relation $relation, ?Confidence $confidence = null): bool
    {
        foreach ($graph->edges() as $edge) {
            if ($edge->source === $source && $edge->target === $target && $edge->relation === $relation && ($confidence === null || $edge->confidence === $confidence)) {
                return true;
            }
        }

        return false;
    }
}
