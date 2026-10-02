<?php

declare(strict_types=1);

namespace PhpGraph\Storage;

use PhpGraph\Graph\Graph;

final class JsonGraphStorage
{
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
        $payload = ['version' => 2, 'meta' => $meta] + $graph->toArray();

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        // Write then rename: the MCP server reloads the file as soon as it changes and must never read it half written.
        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temporary, $json . "\n") === false || !rename($temporary, $path)) {
            @unlink($temporary);

            throw new \RuntimeException(sprintf('Cannot write graph to "%s".', $path));
        }
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

        return [Graph::fromArray($data), \is_array($data['meta'] ?? null) ? $data['meta'] : []];
    }
}
