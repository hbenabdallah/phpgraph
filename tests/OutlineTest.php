<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Builder\GraphBuilder;
use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Graph;
use PhpGraph\Graph\Relation;
use PhpGraph\Presentation\TextPresenter;
use PhpGraph\Query\GraphQuery;
use PHPUnit\Framework\TestCase;

/**
 * The outline of a feature, the closures a method runs, and the answers about a feature: query and explain.
 */
final class OutlineTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/Fixtures/feature-outline';

    private static ?Graph $graph = null;

    public function testTheOutlineListsTheClassesOfTheFeatureWithWhatTheyDeclare(): void
    {
        $text = $this->presenter()->outline('notification validation');

        foreach (['Notification', 'Violation', 'ContextValidator', 'PipelineRunner', 'Outcome', 'MutationValidators', 'Wiring', 'ProblemDetails'] as $class) {
            self::assertMatchesRegularExpression('/^    ' . $class . ' \(/m', $text, $class . ' is part of the feature');
        }
        self::assertDoesNotMatchRegularExpression('/^    (StockRule|PlaceOrder|Order) \(/m', $text, 'a rule is its family\'s, a use case and an aggregate are clients');
        self::assertStringContainsString('Notification (final class) — Collects the violations of one validation run.', $text);
        self::assertStringContainsString('all(): list<Violation>', $text, 'the documented collection');
        self::assertStringContainsString('cases Context = \'context\', Invariant = \'invariant\'', $text);
        self::assertStringContainsString('const MAX_CYCLES = 10', $text);
    }

    public function testTheOutlineCountsTheFamiliesEmptyOnesIncluded(): void
    {
        $text = $this->presenter()->outline('notification validation');

        self::assertStringContainsString('ContextRuleInterface: 3 in Sales/Rules 2, Billing/Rules 1; received by ContextValidator (tagged_iterator {billing,sales}.context_rule)', $text);
        self::assertStringContainsString('PolicyRuleInterface: 0 implementations', $text);
    }

    public function testTheOutlineFollowsTheRoutesIntoTheFeatureThroughTheClosureTheRunnerRuns(): void
    {
        $text = $this->presenter()->outline('notification validation');

        self::assertStringContainsString(
            'POST /orders → PlaceOrderProcessor::process() → PlaceOrder::handle() → PipelineRunner::run() runs the closure → OrderBuilder::build() → Order::create() → MutationValidators::validate()',
            $text,
        );
        self::assertStringContainsString('MutationValidators::validate() ← Order::create()', $text);
        self::assertStringContainsString('PipelineRunner::run() → ContextValidator::validate(), Notification::hasErrors(), Outcome::{halted,completed}()', $text);
    }

    public function testTheOutlineReadsTheBehaviourInTheBodies(): void
    {
        $text = $this->presenter()->outline('notification validation');

        self::assertStringContainsString('ContextValidator::validate L24: throws LogicException "The rules exceeded the cycles', $text);
        self::assertStringContainsString('if $cycle++ >= self::MAX_CYCLES (10)', $text, 'the cap, with the constant\'s value');
        self::assertStringContainsString('compares count($notification->all()) before and after', $text);
        self::assertStringContainsString('PipelineRunner::run L17: returns Outcome::halted() if $notification->hasErrors()', $text);
        self::assertStringContainsString('Order::create L13: returns null if $notification->hasErrors()', $text, 'what the code running into the feature checks of it');
        self::assertStringContainsString('$status = self::UNPROCESSABLE (422) if $notification->hasErrors(), else self::OK (200)', $text);
    }

    public function testTheOutlineShowsTheWiringWithTheServiceIdsItBuilds(): void
    {
        $text = $this->presenter()->outline('notification validation');

        self::assertStringContainsString("L12 Wiring::wire(\$services, 'sales', 'App\\Sales')\n      sales.validation_pipeline (PipelineRunner) → PlaceOrder", $text);
        self::assertStringContainsString('billing.validation_pipeline (PipelineRunner) → IssueInvoice', $text);
    }

    public function testTheOutlineCountsTheTestsAndNamesTheGaps(): void
    {
        $presenter = $this->presenter();
        $text = $presenter->outline('notification validation');

        self::assertStringContainsString('Tests touching the core: 2 files in 2 modules', $text);
        self::assertStringNotContainsString('tests/Validation/NotificationTest.php', $text, 'tests are counted by module, not listed');
        self::assertMatchesRegularExpression('/^No test touches: .*ContextValidator.*PipelineRunner/m', $text);
        self::assertStringContainsString('Nothing in the application uses: ViolationPrinter.', $text);

        $json = json_decode($presenter->outline('notification validation', 'json'), true);
        self::assertIsArray($json);
        self::assertContains('App\Validation\ViolationPrinter', $json['unused'] ?? []);
        self::assertSame('No class matches "zzz": try query_graph, or other words.', $presenter->outline('zzz'));
    }

    public function testACallInAClosureRunsInTheMethodCallingIt(): void
    {
        $graph = self::graph();
        $edge = null;
        foreach ($graph->incident('App\Validation\PipelineRunner::run') as $item) {
            if ($item['forward'] && $item['other'] === 'App\Sales\OrderBuilder::build') {
                $edge = $item['edge'];
            }
        }
        self::assertNotNull($edge, 'run() calls $apply, the closure PlaceOrder::handle() passes it');
        self::assertSame(Relation::Calls, $edge->relation);
        self::assertSame(Confidence::Inferred, $edge->confidence);
        self::assertSame('closure of App\Sales\PlaceOrder::handle', $edge->via());

        $query = new GraphQuery($graph);
        $impacted = array_map(static fn ($class): string => $class->class, $query->impactOf($query->resolve('App\Sales\OrderBuilder::build') ?? self::fail('No node'))->classes);
        self::assertContains('App\Sales\PlaceOrder', $impacted, 'the use case writing the closure');
        self::assertNotContains('App\Billing\IssueInvoice', $impacted, 'not every use case passing the runner a closure');
    }

    public function testQueryAddsTheFeatureAroundTheNamedClassAndSumsUpInjectedLists(): void
    {
        $text = $this->presenter()->query('notification validation');

        self::assertMatchesRegularExpression('/^Seeds: Notification, .*ContextValidator.*PipelineRunner/m', $text);
        self::assertStringContainsString('ContextValidator receives 3 ContextRuleInterface (tagged_iterator {billing,sales}.context_rule)', $text);
        self::assertStringNotContainsString('[receives', $text, 'no edge per member of the list');
        self::assertStringNotContainsString('StockRule', $text, 'a rule stands in its family');
    }

    public function testExplainCountsTheTestsByModule(): void
    {
        $text = $this->presenter()->explain('Notification');

        self::assertStringContainsString('<-- tests: 2 files in 2 modules (', $text);
        self::assertStringNotContainsString('NotificationTest', $text);
    }

    private function presenter(): TextPresenter
    {
        return new TextPresenter(new GraphQuery(self::graph(), null, self::FIXTURE));
    }

    private static function graph(): Graph
    {
        return self::$graph ??= (new GraphBuilder())->build(self::FIXTURE)->graph;
    }
}
