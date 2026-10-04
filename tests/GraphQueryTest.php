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

    public function testQueryFindsTheCodeAboutTheQuestionNotItsHubs(): void
    {
        $root = sys_get_temp_dir() . '/phpgraph-query-' . bin2hex(random_bytes(4));
        $files = [
            'src/Order.php' => 'namespace App; class Order { public function id(): int { return 1; } public function total(): int { return 1; } public function lines(): array { return []; } public function customer(): string { return ""; } }',
            'src/OrderRepository.php' => 'namespace App; class OrderRepository { public function find(): Order { return new Order(); } public function save(Order $o): void {} }',
            'src/OrderController.php' => 'namespace App; class OrderController { public function show(Order $o): void {} }',
            'src/Validation/OrderValidator.php' => 'namespace App\Validation; class OrderValidator { public function __construct(private iterable $rules) {} public function validate(\App\Order $o): void {} }',
            'src/Validation/StockAvailabilityRule.php' => 'namespace App\Validation; class StockAvailabilityRule { public function supports(object $input): bool { return $input instanceof \App\PlaceOrder; } }',
            'src/PlaceOrder.php' => 'namespace App; class PlaceOrder { public function __construct(private Validation\OrderValidator $validator) {} public function handle(Order $o): void { $this->validator->validate($o); } }',
            'tests/OrderValidatorTest.php' => 'namespace App\Tests; class OrderValidatorTest { public function testValidates(): void {} }',
        ];
        foreach ($files as $path => $code) {
            @mkdir(\dirname($root . '/' . $path), 0777, true);
            file_put_contents($root . '/' . $path, "<?php\n" . $code . "\n");
        }
        $query = new GraphQuery((new \PhpGraph\Builder\GraphBuilder())->build($root)->graph);
        $subgraph = $query->subgraph('how is an order validated before placing it');
        $labels = array_map(static fn ($node): string => $node->label, $subgraph->nodes);

        $seeds = array_map(static fn ($node): string => $node->label, $subgraph->seeds);
        self::assertContains('OrderValidator', $seeds, 'validated meets Validator; order alone names every class');
        self::assertContains('PlaceOrder', $seeds, 'placing meets PlaceOrder');
        self::assertNotContains('Order', $seeds);
        self::assertNotContains('Order::customer()', $labels, 'the getters of a hub say nothing of the question');
        self::assertNotContains('OrderValidatorTest', $labels, 'tests only when the question is about tests');

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($root);
    }

    public function testStemsMakeTheFormsOfAWordMeet(): void
    {
        foreach ([['validated', 'validation', 'validator', 'validate'], ['create', 'creation', 'created'], ['prices', 'price'], ['resolver', 'resolve']] as $forms) {
            self::assertCount(1, array_unique(array_map([\PhpGraph\Query\Relevance::class, 'stem'], $forms)), implode(', ', $forms));
        }
        self::assertSame(['stock', 'eligibil', 'resolv'], \PhpGraph\Query\Relevance::stems('StockEligibilityResolver'));
    }

    public function testAQuestionAboutASystemGetsItsModule(): void
    {
        $root = sys_get_temp_dir() . '/phpgraph-module-' . bin2hex(random_bytes(4));
        $files = [
            'src/Validation/Notification.php' => 'namespace App\Validation; class Notification { public function add(): void {} public function all(): array { return []; } public function count(): int { return 0; } }',
            'src/Validation/Violation.php' => 'namespace App\Validation; class Violation {}',
            'src/Validation/InvariantValidator.php' => 'namespace App\Validation; class InvariantValidator { public function validate(Notification $n): void { $n->add(); } }',
            'src/Validation/PathContext.php' => 'namespace App\Validation; class PathContext {}',
            'src/Measure/MeasuringSystemRule.php' => 'namespace App\Measure; class MeasuringSystemRule { public function validateSystem(): void {} }',
            'src/Measure/MeasuringSystem.php' => 'namespace App\Measure; class MeasuringSystem {}',
        ];
        foreach ($files as $path => $code) {
            @mkdir(\dirname($root . '/' . $path), 0777, true);
            file_put_contents($root . '/' . $path, "<?php\n" . $code . "\n");
        }
        $subgraph = (new GraphQuery((new \PhpGraph\Builder\GraphBuilder())->build($root)->graph))->subgraph('explain for me notification validation system');
        $labels = array_map(static fn ($node): string => $node->label, $subgraph->nodes);

        $seeds = array_map(static fn ($node): string => $node->label, $subgraph->seeds);
        self::assertSame('Notification', $seeds[0], '"explain" and "system" name no code');
        self::assertContains('InvariantValidator', $seeds, 'a class of the feature around it: about the question, working with it');
        self::assertNotContains('MeasuringSystemRule', $seeds);
        foreach (['InvariantValidator', 'Violation', 'PathContext'] as $sibling) {
            self::assertContains($sibling, $labels, 'the module of the seed');
        }
        self::assertNotContains('MeasuringSystemRule', $labels);
        self::assertNotContains('Notification::count()', $labels, 'a method of the seed only by its own name');

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($root);
    }

    public function testImpactReachesTheRoutesThroughATaggedListOfRulesScopedToItsContext(): void
    {
        $query = new GraphQuery((new \PhpGraph\Builder\GraphBuilder())->build(__DIR__ . '/Fixtures/validation-pipeline')->graph);
        $routes = static function (GraphQuery $query, string $name): array {
            $routes = [];
            foreach ($query->impactOf($query->resolve($name) ?? self::fail('No node ' . $name))->classes as $class) {
                if (str_starts_with($class->class, 'route:')) {
                    $routes[$query->label($class->class)] = $class;
                }
            }
            ksort($routes);

            return $routes;
        };

        $fromRule = $routes($query, 'App\Sales\Rules\StockRule::apply');
        self::assertSame(['POST /orders'], array_keys($fromRule), 'a sales rule reaches the sales use case only, not billing');
        self::assertSame(\PhpGraph\Graph\Confidence::Inferred, $fromRule['POST /orders']->confidence, 'a hop through an injected list');

        $fromNotification = $routes($query, 'App\Validation\Notification::addContextViolation');
        self::assertSame(['POST /invoices', 'POST /orders'], array_keys($fromNotification), 'eight calls away, beyond the depth limit');

        $text = (new \PhpGraph\Presentation\TextPresenter($query))->impact('App\Sales\Rules\StockRule::apply', 3, 0, 'routes');
        self::assertStringContainsString('PlaceOrderProcessor::process() ← PlaceOrder::handle() (service sales.pipeline) ← PipelineRunner::run() ← ContextValidator::validate()', $text);
        self::assertStringContainsString('ContextValidator::executeRules() (tagged_iterator sales.context_rule)', $text);
    }

    public function testForeachOverADocumentedCollectionTypesItsElements(): void
    {
        $graph = (new \PhpGraph\Builder\GraphBuilder())->build(__DIR__ . '/Fixtures/validation-pipeline')->graph;

        $calls = [];
        foreach ($graph->edges() as $edge) {
            if ($edge->relation === \PhpGraph\Graph\Relation::Calls && $edge->source === 'App\Validation\ContextValidator::executeRules') {
                $calls[] = $edge->target;
            }
        }
        self::assertContains('App\Validation\ContextRuleInterface::apply', $calls, '@param RuleExecution[] $executions, then $execution->rule');
    }

    public function testServicesOfOneClassAndFamiliesOfRules(): void
    {
        $presenter = new \PhpGraph\Presentation\TextPresenter(new GraphQuery((new \PhpGraph\Builder\GraphBuilder())->build(__DIR__ . '/Fixtures/validation-pipeline')->graph));

        $explain = $presenter->explain('App\Validation\PipelineRunner');
        self::assertStringContainsString('<-- PlaceOrder [receives service sales.pipeline] [EXTRACTED]', $explain, 'which pipeline of a shared class a use case gets');
        self::assertStringContainsString('<-- Invoice [receives service billing.pipeline] [EXTRACTED]', $explain);
        self::assertStringNotContainsString('--> ContextValidator [receives service', $explain, 'one class for several pipelines: the edge cannot name one');

        self::assertStringContainsString('ContextRuleInterface: 2 in src/{Billing/Rules 1, Sales/Rules 1}', $presenter->query('validation pipeline runner'));
    }
}
