<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Graph\Edge;
use PhpGraph\Graph\Graph;
use PhpGraph\Graph\Node;
use PhpGraph\Graph\NodeKind;
use PhpGraph\Graph\Relation;
use PhpGraph\Query\GraphQuery;
use PhpGraph\Query\Result\Path;
use PhpGraph\Query\Result\PathMode;
use PHPUnit\Framework\TestCase;

final class GraphQueryTest extends TestCase
{
    public function testDependencyPathGoesFromPortToAdapter(): void
    {
        $graph = $this->graph(
            ['Handler', 'Port', 'Adapter'],
            [['Handler', 'Port', Relation::References], ['Adapter', 'Port', Relation::Implements]],
        );

        $path = $this->path($graph, 'Handler', 'Adapter');

        self::assertSame(PathMode::Dependency, $path->mode);
        self::assertSame(['Port', 'Adapter'], array_map(static fn ($hop): string => $hop->other, $path->hops));
    }

    public function testDependencyPathDoesNotWalkDependenciesBackwards(): void
    {
        $graph = $this->graph(
            ['A', 'B', 'C'],
            [['A', 'B', Relation::Calls], ['C', 'B', Relation::Calls]],
        );

        self::assertSame(PathMode::Undirected, $this->path($graph, 'A', 'C')->mode);
    }

    public function testPathAvoidsExternalHubs(): void
    {
        $graph = $this->graph(
            ['A', 'B', 'C', 'D'],
            [
                ['A', 'Psr\Log\LoggerInterface', Relation::References],
                ['D', 'Psr\Log\LoggerInterface', Relation::References],
                ['A', 'B', Relation::Calls],
                ['B', 'C', Relation::Calls],
                ['C', 'D', Relation::Calls],
            ],
        );
        $graph->addNode(new Node('Psr\Log\LoggerInterface', 'LoggerInterface', NodeKind::External));

        $path = $this->path($graph, 'A', 'D');

        self::assertSame(PathMode::Dependency, $path->mode);
        self::assertCount(3, $path->hops);
    }

    public function testPathFallsBackToHubsWhenNothingElseConnects(): void
    {
        $graph = $this->graph(
            ['A', 'D'],
            [['A', 'Psr\Log\LoggerInterface', Relation::References], ['D', 'Psr\Log\LoggerInterface', Relation::References]],
        );
        $graph->addNode(new Node('Psr\Log\LoggerInterface', 'LoggerInterface', NodeKind::External));

        self::assertSame(PathMode::Any, $this->path($graph, 'A', 'D')->mode);
    }

    /**
     * @param list<string>                         $classes
     * @param list<array{string, string, Relation}> $edges
     */
    private function graph(array $classes, array $edges): Graph
    {
        $graph = new Graph();
        foreach ($classes as $class) {
            $graph->addNode(new Node($class, $class, NodeKind::PhpClass, $class . '.php', 1));
        }
        foreach ($edges as [$source, $target, $relation]) {
            $graph->addEdge(new Edge($source, $target, $relation));
        }

        return $graph;
    }

    private function path(Graph $graph, string $from, string $to): Path
    {
        $query = new GraphQuery($graph);
        $start = $graph->node($from);
        $end = $graph->node($to);
        self::assertNotNull($start);
        self::assertNotNull($end);

        $path = $query->shortestPath($start, $end);
        self::assertNotNull($path);

        return $path;
    }
}
