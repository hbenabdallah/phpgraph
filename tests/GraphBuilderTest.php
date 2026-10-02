<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Builder\GraphBuilder;
use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Graph;
use PhpGraph\Graph\Relation;
use PhpGraph\Presentation\TextPresenter;
use PhpGraph\Query\GraphQuery;
use PhpGraph\Query\ReportGenerator;
use PHPUnit\Framework\TestCase;

final class GraphBuilderTest extends TestCase
{
    private const HANDLER = 'App\Sales\Application\PlaceOrderHandler';

    public function testBuildsNodesAndExtractedEdges(): void
    {
        $result = $this->build();

        self::assertSame(5, $result->filesParsed);
        self::assertSame([], $result->failures);
        self::assertTrue($result->graph->hasNode(self::HANDLER));
        self::assertTrue($this->hasEdge($result->graph, 'App\Sales\Infrastructure\DbalOrderRepository', 'App\Sales\Domain\OrderRepository', Relation::Implements, Confidence::Extracted));
        self::assertTrue($this->hasEdge($result->graph, self::HANDLER . '::handle', 'App\Sales\Domain\Order', Relation::Instantiates, Confidence::Extracted));
    }

    public function testResolvesCallsThroughPromotedPropertyTypes(): void
    {
        $graph = $this->build()->graph;

        self::assertTrue($this->hasEdge($graph, self::HANDLER . '::handle', 'App\Sales\Domain\OrderRepository::save', Relation::Calls, Confidence::Inferred));
        self::assertTrue($this->hasEdge($graph, self::HANDLER . '::handle', 'App\Stock\Domain\StockChecker::check', Relation::Calls, Confidence::Inferred));
        self::assertTrue($this->hasEdge($graph, self::HANDLER . '::handle', self::HANDLER . '::log', Relation::Calls, Confidence::Inferred));
    }

    public function testResolvesCallsThroughParameterTypes(): void
    {
        $graph = $this->build()->graph;

        self::assertTrue($this->hasEdge($graph, 'App\Stock\Domain\StockChecker::check', 'App\Sales\Domain\Order::total', Relation::Calls, Confidence::Inferred));
    }

    public function testLinksImplementationMethodsToInterfaceMethods(): void
    {
        $graph = $this->build()->graph;

        self::assertTrue($this->hasEdge(
            $graph,
            'App\Sales\Infrastructure\DbalOrderRepository::save',
            'App\Sales\Domain\OrderRepository::save',
            Relation::Overrides,
            Confidence::Inferred,
        ));
    }

    public function testCountsHowEachCallSiteWasResolved(): void
    {
        $calls = $this->build()->calls;

        self::assertSame(
            ['total' => 5, 'inferred' => 4, 'ambiguous' => 0, 'outsideProject' => 1, 'unknownReceiver' => 0, 'chainOutsideProject' => 0],
            $calls->toArray(),
        );
    }

    public function testRegistersExternalDependenciesAsExternalNodes(): void
    {
        $graph = $this->build()->graph;

        self::assertSame('external', $graph->node('Doctrine\DBAL\Connection')?->kind->value);
    }

    public function testShortestPathAvoidsFileNodes(): void
    {
        $path = (new TextPresenter(new GraphQuery($this->build()->graph)))->path('PlaceOrderHandler', 'DbalOrderRepository');

        self::assertStringContainsString('Shortest path (3 hops)', $path);
        self::assertStringNotContainsString('.php', $path);
    }

    public function testReportListsCrossBoundaryDependencies(): void
    {
        $report = (new ReportGenerator())->generate($this->build());

        self::assertStringContainsString('App\Stock -> App\Sales', $report);
        self::assertStringContainsString('App\Sales -> App\Stock', $report);
    }

    private function build(): \PhpGraph\Builder\BuildResult
    {
        return (new GraphBuilder())->build(__DIR__ . '/Fixtures/src');
    }

    private function hasEdge(Graph $graph, string $source, string $target, Relation $relation, Confidence $confidence): bool
    {
        foreach ($graph->edges() as $edge) {
            if ($edge->source === $source && $edge->target === $target && $edge->relation === $relation && $edge->confidence === $confidence) {
                return true;
            }
        }

        return false;
    }
}
