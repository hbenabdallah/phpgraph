<?php

declare(strict_types=1);

namespace PhpGraph\Graph;

final class Graph
{
    /** @var array<string, Node> */
    private array $nodes = [];

    /** @var array<string, Edge> */
    private array $edges = [];

    /** @var array<string, list<array{edge: Edge, other: string, forward: bool}>> */
    private array $incidence = [];

    public function addNode(Node $node): void
    {
        if (!isset($this->nodes[$node->id])) {
            $this->nodes[$node->id] = $node;
        }
    }

    public function addEdge(Edge $edge): void
    {
        if ($edge->source === $edge->target) {
            return;
        }

        $key = $edge->key();
        if (isset($this->edges[$key])) {
            $this->edges[$key]->addLines($edge);

            return;
        }

        $this->edges[$key] = $edge;
        $this->incidence[$edge->source][] = ['edge' => $edge, 'other' => $edge->target, 'forward' => true];
        $this->incidence[$edge->target][] = ['edge' => $edge, 'other' => $edge->source, 'forward' => false];
    }

    public function node(string $id): ?Node
    {
        return $this->nodes[$id] ?? null;
    }

    public function hasNode(string $id): bool
    {
        return isset($this->nodes[$id]);
    }

    /**
     * @return array<string, Node>
     */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /**
     * @return list<Edge>
     */
    public function edges(): array
    {
        return array_values($this->edges);
    }

    /**
     * @return list<array{edge: Edge, other: string, forward: bool}>
     */
    public function incident(string $id): array
    {
        return $this->incidence[$id] ?? [];
    }

    public function degree(string $id): int
    {
        return \count($this->incidence[$id] ?? []);
    }

    public function nodeCount(): int
    {
        return \count($this->nodes);
    }

    public function edgeCount(): int
    {
        return \count($this->edges);
    }

    /**
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, string>>}
     */
    public function toArray(): array
    {
        return [
            'nodes' => array_map(static fn (Node $node): array => $node->toArray(), array_values($this->nodes)),
            'edges' => array_map(static fn (Edge $edge): array => $edge->toArray(), array_values($this->edges)),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $graph = new self();

        foreach ($data['nodes'] ?? [] as $node) {
            $graph->addNode(Node::fromArray($node));
        }

        foreach ($data['edges'] ?? [] as $edge) {
            $graph->addEdge(Edge::fromArray($edge));
        }

        return $graph;
    }
}
