<?php

declare(strict_types=1);

namespace PhpGraph\Query;

use PhpGraph\Builder\SourceFiles;
use PhpGraph\Presentation\TextPresenter;
use PhpGraph\Project\ProjectGraph;
use PhpGraph\Project\ProjectSummary;
use PhpGraph\Storage\JsonGraphStorage;

/**
 * Hands the MCP server an up-to-date graph: reloaded when graph.json changes, and, given the project, rebuilt
 * first when the sources changed.
 */
final class GraphQueryProvider
{
    private ?GraphQuery $query = null;

    private int|false|null $loadedAt = null;

    public function __construct(
        private readonly string $graphPath,
        private readonly JsonGraphStorage $storage = new JsonGraphStorage(),
        private readonly ?ProjectGraph $project = null,
    ) {
    }

    public static function forProject(ProjectGraph $project): self
    {
        return new self($project->graphPath(), new JsonGraphStorage(), $project);
    }

    /**
     * The project's directory, for the sources an outline reads: the one the graph was built from, or the one
     * holding `phpgraph-out/` when the graph was built elsewhere (in a container).
     *
     * @param array<mixed> $meta
     */
    private function root(array $meta): ?string
    {
        $root = $meta['root'] ?? null;
        if (\is_string($root) && is_dir($root)) {
            return $root;
        }
        $output = \dirname($this->graphPath);

        return basename($output) === 'phpgraph-out' && is_dir(\dirname($output)) ? \dirname($output) : null;
    }

    /**
     * What an agent must know before calling any tool, told in the MCP instructions: a project without PHP code.
     * Found without building the graph: the server answers `initialize` at once.
     */
    public function projectNote(): ?string
    {
        if ($this->project === null || SourceFiles::countPhpFiles($this->project->root) > 0) {
            return null;
        }

        return TextPresenter::notPhp(SourceFiles::otherLanguages($this->project->root))
            ?? 'No PHP code in this directory: phpgraph only reads PHP, its tools cannot help here.';
    }

    public function get(): GraphQuery
    {
        $rebuilt = $this->project?->refresh() ?? false;

        clearstatcache(true, $this->graphPath);
        $modifiedAt = @filemtime($this->graphPath);

        // Just rebuilt: the graph is in memory already. Reading graph.json back would take longer than parsing.
        $built = $rebuilt ? $this->project->lastBuild() : null;
        if ($built !== null) {
            $this->query = $built;
            $this->loadedAt = $modifiedAt;

            return $built;
        }

        if ($this->query === null || $modifiedAt !== $this->loadedAt) {
            [$graph, $meta] = $this->storage->loadWithMeta($this->graphPath);
            $this->query = new GraphQuery($graph, \is_array($meta['summary'] ?? null) ? ProjectSummary::fromArray($meta['summary']) : null, $this->root($meta));
            $this->loadedAt = $modifiedAt;
        }

        return $this->query;
    }
}
