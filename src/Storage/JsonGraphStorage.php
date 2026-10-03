<?php

declare(strict_types=1);

namespace PhpGraph\Storage;

use PhpGraph\Graph\Graph;

final class JsonGraphStorage
{
    /**
     * The format of graph.json: 2 since nodes carry their service. Changes only with a major version of phpgraph.
     */
    public const FORMAT = 2;

    /**
     * @param array<string, mixed> $meta
     */
    public function save(Graph $graph, string $path, array $meta = []): void
    {
        $directory = \dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Cannot create directory "%s".', $directory));
        }

        // Version 2: nodes of a multi-service repository carry their service. Version 1 files still load.
        $payload = ['version' => self::FORMAT, 'meta' => $meta] + $graph->toArray();

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        // Write then rename: the MCP server reloads the file as soon as it changes and must never read it half written.
        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temporary, $json . "\n") === false || !rename($temporary, $path)) {
            @unlink($temporary);

            throw new \RuntimeException(sprintf('Cannot write graph to "%s".', $path));
        }
    }

    /**
     * The format of a graph file, read from its first bytes (`version` is written first), null when unreadable.
     */
    public function format(string $path): ?int
    {
        $head = (string) @file_get_contents($path, false, null, 0, 256);

        return preg_match('/^\s*\{\s*"version"\s*:\s*(\d+)/', $head, $match) === 1 ? (int) $match[1] : null;
    }

    /**
     * The meta data saved with the graph, or an empty array when the file cannot be read.
     *
     * @return array<mixed>
     */
    public function loadMeta(string $path): array
    {
        $data = json_decode((string) @file_get_contents($path), true);

        return \is_array($data) && \is_array($data['meta'] ?? null) ? $data['meta'] : [];
    }

    public function load(string $path): Graph
    {
        return $this->loadWithMeta($path)[0];
    }

    /**
     * @return array{Graph, array<mixed>} the graph and the meta data saved with it
     */
    public function loadWithMeta(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Graph file "%s" not found. Run "phpgraph build" first.', $path));
        }

        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $format = \is_array($data) ? $data['version'] ?? null : null;
        if ($format !== self::FORMAT) {
            throw new \RuntimeException(sprintf(
                'Graph file "%s" is in format %s, this phpgraph reads format %d. Rebuild it: phpgraph build.',
                $path,
                \is_scalar($format) ? (string) $format : 'unknown',
                self::FORMAT,
            ));
        }

        return [Graph::fromArray($data), \is_array($data['meta'] ?? null) ? $data['meta'] : []];
    }
}
