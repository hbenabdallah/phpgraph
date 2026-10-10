<?php

declare(strict_types=1);

namespace PhpGraph\Project;

use PhpGraph\Builder\BuildResult;
use PhpGraph\Builder\BuildState;
use PhpGraph\Builder\GraphBuilder;
use PhpGraph\Builder\SourceFiles;
use PhpGraph\Extractor\CachingExtractor;
use PhpGraph\Extractor\PhpFileExtractor;
use PhpGraph\Presentation\TextPresenter;
use PhpGraph\Query\GraphQuery;
use PhpGraph\Query\ReportGenerator;
use PhpGraph\Storage\JsonGraphStorage;
use PhpGraph\Version;

/**
 * The graph of one project on disk: graph.json and GRAPH_REPORT.md in the output directory, rebuilt when the
 * sources no longer match the fingerprint saved with the graph.
 */
final class ProjectGraph
{
    public const OUTPUT_DIRECTORY = 'phpgraph-out';

    private ?string $builtFingerprint = null;

    private ?float $checkedAt = null;

    private ?CachingExtractor $extractor = null;

    private ?GraphQuery $lastBuild = null;

    private ?BuildState $state = null;

    /**
     * A long-running process (the MCP server) writes the extraction cache at most every so often, and when it ends:
     * writing 150 MB after every change of a large project would cost more than the rebuild.
     */
    private ?float $cacheSaveInterval = null;

    private ?float $cacheSavedAt = null;

    private bool $saveOnShutdown = false;

    /**
     * @param \Closure(string): void|null $log           progress messages (the MCP server sends them to stderr)
     * @param float                       $checkInterval seconds between two looks at the sources: an agent often
     *                                                   makes several calls in a row
     */
    public function __construct(
        public readonly string $root,
        private readonly string $outputDirectory,
        private BuildOptions $options = new BuildOptions(),
        private readonly ?\Closure $log = null,
        private readonly float $checkInterval = 2.0,
        private readonly JsonGraphStorage $storage = new JsonGraphStorage(),
        private readonly bool $cache = true,
    ) {
    }

    /**
     * Reuses the options of the graph already on disk, when there is one and no option was given.
     */
    public static function reusingSavedOptions(string $root, string $outputDirectory, ?BuildOptions $options, ?\Closure $log = null, float $checkInterval = 2.0): self
    {
        $project = new self($root, $outputDirectory, $options ?? new BuildOptions(), $log, $checkInterval);
        if ($options === null) {
            $saved = $project->savedMeta()['options'] ?? null;
            if (\is_array($saved)) {
                $project->options = BuildOptions::fromArray($saved);
            }
        }

        return $project;
    }

    public function graphPath(): string
    {
        return rtrim($this->outputDirectory, '/') . '/graph.json';
    }

    public function reportPath(): string
    {
        return rtrim($this->outputDirectory, '/') . '/GRAPH_REPORT.md';
    }

    public function options(): BuildOptions
    {
        return $this->options;
    }

    /**
     * For the MCP server: keep the extraction cache in memory, write it every $seconds and when the process ends.
     */
    public function deferCacheWrites(float $seconds = 300.0): void
    {
        $this->cacheSaveInterval = $seconds;
    }

    /**
     * @param ?string $fingerprint of the sources, when the caller computed it already
     */
    public function build(?string $fingerprint = null): BuildResult
    {
        $start = hrtime(true);
        $this->say(sprintf('building the graph of %s', $this->root));

        // Taken before reading: a file edited during the build makes the next refresh build again.
        $fingerprint ??= SourceFiles::fingerprint($this->root, $this->options->excludes);
        // Files unchanged since the last build are not parsed again: their extraction is cached next to the graph,
        // and kept in memory by a long-running process (the MCP server) between its rebuilds.
        if ($this->cache) {
            $this->extractor ??= new CachingExtractor(new PhpFileExtractor(), rtrim($this->outputDirectory, '/') . '/cache.bin');
            $this->extractor->beginBuild();
        }
        $extractor = $this->extractor ?? new PhpFileExtractor();
        // The last build of this process: unchanged files are not read again, and their calls not resolved again
        // when no declaration changed.
        $result = (new GraphBuilder($extractor, $this->options->readVendor))
            ->build($this->root, $this->options->excludes, ProjectConfig::load($this->root)->services, $this->state);
        $this->state = $result->state;
        if ($extractor instanceof CachingExtractor) {
            $this->saveCache($extractor, array_keys($result->state->files ?? []));
            $reuse = $extractor->stats();
        }
        $summary = ProjectSummary::fromBuild(
            $result,
            $this->options,
            (new StackDetector())->detect($this->root, $result->composerFiles),
            SourceFiles::otherLanguages($this->root, $this->options->excludes),
        );

        $this->storage->save($result->graph, $this->graphPath(), [
            'root' => $this->root,
            'generatedAt' => $summary->generatedAt,
            'filesParsed' => $result->filesParsed,
            'fingerprint' => $fingerprint,
            'phpgraph' => ['version' => Version::get(), 'builder' => Version::builder()],
            'options' => $this->options->toArray(),
            'summary' => $summary->toArray(),
        ]);
        file_put_contents($this->reportPath(), (new ReportGenerator())->generate($result, $this->options->depth, $summary));

        $this->builtFingerprint = $fingerprint;
        $this->lastBuild = new GraphQuery($result->graph, $summary, $this->root);
        $this->checkedAt = microtime(true);
        $this->say(sprintf(
            'graph built: %d files%s, %d nodes, %d edges in %.1f s',
            $result->filesParsed,
            isset($reuse) ? sprintf(' (%d parsed, the others unchanged)', $reuse['parsed']) : '',
            $result->graph->nodeCount(),
            $result->graph->edgeCount(),
            (hrtime(true) - $start) / 1e9,
        ));
        $unread = TextPresenter::unreadSources($result->phpFilesNotRead, $this->root);
        if ($unread !== null) {
            $this->say($unread);
        }

        return $result;
    }

    /**
     * Builds the graph when it is missing or when the sources changed since it was built.
     *
     * @return bool whether the graph was built
     */
    /**
     * @param list<string> $files
     */
    private function saveCache(CachingExtractor $extractor, array $files): void
    {
        $now = microtime(true);
        if ($this->cacheSaveInterval === null || $this->cacheSavedAt === null || $now - $this->cacheSavedAt >= $this->cacheSaveInterval) {
            $extractor->save($files);
            $this->cacheSavedAt = $now;

            return;
        }

        if (!$this->saveOnShutdown) {
            $this->saveOnShutdown = true;
            register_shutdown_function(function () use ($extractor): void {
                if ($extractor->isDirty()) {
                    $extractor->save(array_keys($this->state->files ?? []));
                }
            });
        }
    }

    /**
     * The graph this object built last, in memory: no need to read graph.json back.
     */
    public function lastBuild(): ?GraphQuery
    {
        return $this->lastBuild;
    }

    public function refresh(): bool
    {
        if ($this->checkedAt !== null && microtime(true) - $this->checkedAt < $this->checkInterval) {
            return false;
        }

        if (!is_file($this->graphPath())) {
            $this->build();

            return true;
        }

        if ($this->builtFingerprint === null) {
            $meta = $this->savedMeta();
            // Built by another phpgraph, or in another format: rebuilt, whatever the sources.
            if (($meta['phpgraph']['builder'] ?? null) !== Version::builder() || $this->storage->format($this->graphPath()) !== JsonGraphStorage::FORMAT) {
                $this->say('the graph was built by another version of phpgraph');
                $this->build();

                return true;
            }
            $saved = $meta['fingerprint'] ?? null;
            $this->builtFingerprint = \is_string($saved) ? $saved : '';
        }
        $current = SourceFiles::fingerprint($this->root, $this->options->excludes);
        $this->checkedAt = microtime(true);
        if ($current === $this->builtFingerprint) {
            return false;
        }

        $this->say('sources changed');
        $this->build($current);

        return true;
    }

    /**
     * @return array<mixed>
     */
    private function savedMeta(): array
    {
        return is_file($this->graphPath()) ? $this->storage->loadMeta($this->graphPath()) : [];
    }

    private function say(string $message): void
    {
        if ($this->log !== null) {
            ($this->log)('phpgraph: ' . $message);
        }
    }
}
