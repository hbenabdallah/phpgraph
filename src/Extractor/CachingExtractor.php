<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Edge;
use PhpGraph\Graph\Node;
use PhpGraph\Graph\NodeKind;
use PhpGraph\Graph\Relation;

/**
 * Keeps the extraction of every file between builds, keyed by its path and content: a rebuild parses only the files
 * that changed. Resolution across files still runs in full, since one change may affect any other file.
 *
 * The cache is invalidated as a whole when the extraction code changes. It is read back with unserialize()
 * restricted to the extraction value classes: a tampered cache file cannot instantiate anything else.
 */
final class CachingExtractor implements FileExtractor
{
    private const ALLOWED_CLASSES = [
        FileExtraction::class, Node::class, Edge::class, PendingCall::class, TypeExpr::class, HandlerFact::class,
        PendingDispatch::class, RouteFact::class, PendingRequest::class, NodeKind::class, Relation::class, Confidence::class,
    ];

    /** @var array<string, FileExtraction> loaded from the previous build */
    private array $previous = [];

    /** @var array<string, FileExtraction> made or reused by this process */
    private array $current = [];

    /** @var array<string, string> file => key of its latest extraction: what is saved */
    private array $latest = [];

    private bool $dirty = false;

    private int $hits = 0;

    private int $misses = 0;

    public function __construct(
        private readonly FileExtractor $extractor,
        private readonly string $path,
    ) {
        $this->previous = $this->load();
    }

    /**
     * Starts another build in the same process (the MCP server): the extractions made since it started are reused,
     * without reading the cache file again.
     */
    public function beginBuild(): void
    {
        $this->hits = $this->misses = 0;
    }

    public function extract(string $code, string $relativePath): FileExtraction
    {
        $key = hash('xxh128', $relativePath . "\0" . $code);
        $extraction = $this->current[$key] ?? $this->previous[$key] ?? null;

        if ($extraction === null) {
            ++$this->misses;
            $extraction = $this->extractor->extract($code, $relativePath);
        } else {
            ++$this->hits;
        }

        // Behind the cache file only for an extraction it does not hold; files gone are checked when saving.
        if (!isset($this->previous[$key])) {
            $this->dirty = true;
        }
        $previousKey = $this->latest[$relativePath] ?? null;
        if ($previousKey !== null && $previousKey !== $key) {
            unset($this->current[$previousKey]);
        }
        $this->latest[$relativePath] = $key;

        return $this->current[$key] = $extraction;
    }

    /**
     * Whether the cache file is behind what this process extracted.
     */
    public function isDirty(): bool
    {
        return $this->dirty;
    }

    /**
     * Writes the latest extraction of every file, or of the given files only: files gone since are forgotten.
     *
     * @param list<string>|null $files files of the last build, relative paths
     */
    public function save(?array $files = null): void
    {
        $latest = $files === null ? $this->latest : array_intersect_key($this->latest, array_flip($files));
        // Nothing parsed and nothing gone: the file on disk is already right.
        if (!$this->dirty && \count($latest) === \count($this->previous)) {
            return;
        }
        $entries = [];
        foreach ($latest as $key) {
            if (isset($this->current[$key])) {
                $entries[$key] = $this->current[$key];
            }
        }

        $directory = \dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $temporary = $this->path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temporary, self::version() . "\n" . serialize($entries)) === false || !rename($temporary, $this->path)) {
            @unlink($temporary);

            return;
        }
        $this->previous = $entries;
        $this->dirty = false;
    }

    /**
     * Files parsed by this build, and files whose previous extraction was reused.
     *
     * @return array{parsed: int, reused: int}
     */
    public function stats(): array
    {
        return ['parsed' => $this->misses, 'reused' => $this->hits];
    }

    /**
     * @return array<string, FileExtraction>
     */
    private function load(): array
    {
        $content = is_file($this->path) ? @file_get_contents($this->path) : false;
        if (!\is_string($content)) {
            return [];
        }

        [$version, $payload] = explode("\n", $content, 2) + ['', ''];
        if ($version !== self::version()) {
            return [];
        }

        $entries = @unserialize($payload, ['allowed_classes' => self::ALLOWED_CLASSES]);

        $extractions = [];
        foreach (\is_array($entries) ? $entries : [] as $key => $entry) {
            if ($entry instanceof FileExtraction) {
                $extractions[(string) $key] = $entry;
            }
        }

        return $extractions;
    }

    /**
     * Changes with the extraction code and the PHP parser: an extraction made by another version is not reused.
     */
    private static function version(): string
    {
        static $version = null;
        if (\is_string($version)) {
            return $version;
        }

        $hash = hash_init('xxh128');
        foreach ([__DIR__, \dirname(__DIR__) . '/Graph'] as $directory) {
            $files = scandir($directory) ?: [];
            sort($files);
            foreach ($files as $file) {
                if (str_ends_with($file, '.php')) {
                    hash_update($hash, $file . ':' . (string) @file_get_contents($directory . '/' . $file));
                }
            }
        }
        hash_update($hash, \PhpParser\Parser::class . PHP_VERSION);

        return $version = 'phpgraph-cache:' . hash_final($hash);
    }
}
