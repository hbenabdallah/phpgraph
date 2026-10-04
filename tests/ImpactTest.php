<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Relation;
use PhpGraph\Presentation\TextPresenter;
use PhpGraph\Query\GraphQuery;
use PhpGraph\Query\Result\ImpactedClass;
use PhpGraph\Tests\Support\TemporaryProject;
use PHPUnit\Framework\TestCase;

/**
 * What impact follows and where it stops: interfaces, the state a method writes, tagged collections, inherited code,
 * test helpers.
 */
final class ImpactTest extends TestCase
{
    use TemporaryProject;

    private const PROJECT = [
        // A notification recording violations, read back through all() and hasErrors().
        'src/Validation/Notification.php' => 'namespace App\Validation; final class Notification { private array $violations = []; public function __construct(private Clock $clock) {}'
            . ' public function add(string $v): void { $this->violations[] = $v; }'
            . ' public function all(): array { return $this->violations; }'
            . ' public function hasErrors(): bool { return count($this->violations) > 0; }'
            . ' public function now(): string { return $this->clock->now(); } }',
        'src/Validation/Clock.php' => 'namespace App\Validation; class Clock { public function now(): string { return ""; } }',
        'src/Validation/Rule.php' => 'namespace App\Validation; interface Rule { public function apply(Notification $n): void; }',
        'src/Validation/StockRule.php' => 'namespace App\Validation; final class StockRule implements Rule { public function apply(Notification $n): void { $n->add("stock"); } }',
        'src/Validation/NormRule.php' => 'namespace App\Validation; final class NormRule implements Rule { public function apply(Notification $n): void { $n->add("norm"); } }',
        'src/Validation/Validator.php' => 'namespace App\Validation; final class Validator { /** @param iterable<Rule> $rules */ public function __construct(private iterable $rules, private Rule $rule) {}'
            . ' public function validate(Notification $n): void { $this->rule->apply($n); } }',
        'src/Validation/PromiseCheck.php' => 'namespace App\Validation; final class PromiseCheck { public function check(Notification $n): bool { return count($n->all()) > 0; } }',
        'src/Validation/Report.php' => 'namespace App\Validation; final class Report { public function __construct(private PromiseCheck $check) {} public function render(Notification $n): string { return $this->check->check($n) ? "ok" : "ko"; } }',
        'src/Validation/Dispatcher.php' => 'namespace App\Validation; final class Dispatcher { public function __construct(private iterable $rules) {} }',
        'src/Validation/Endpoint.php' => 'namespace App\Validation; final class Endpoint { public function __construct(private Dispatcher $dispatcher) {} }',
        // Use cases sharing a template method.
        'src/UseCase/AbstractUseCase.php' => 'namespace App\UseCase; abstract class AbstractUseCase { public function __construct() {} public function __invoke(): void { $this->handle(); } abstract protected function handle(): void; }',
        'src/UseCase/Preview.php' => 'namespace App\UseCase; final class Preview extends AbstractUseCase { public function __construct() { parent::__construct(); } protected function handle(): void {} }',
        'src/UseCase/Archive.php' => 'namespace App\UseCase; final class Archive extends AbstractUseCase { public function __construct() { parent::__construct(); } protected function handle(): void {} }',
        'src/UseCase/ArchiveController.php' => 'namespace App\UseCase; final class ArchiveController { public function __construct(private Archive $archive) {} public function run(): void { ($this->archive)(); } }',
        // Tests reaching the change through a faker.
        'tests/Faker/FakeStockRule.php' => 'namespace App\Tests\Faker; use App\Validation\StockRule; final class FakeStockRule { public static function create(): StockRule { return new StockRule(); } }',
        'tests/OrderTest.php' => 'namespace App\Tests; use App\Tests\Faker\FakeStockRule; final class OrderTest { public function testIt(): void { FakeStockRule::create(); } }',
        'config/services.php' => 'use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator; return static function ($container): void { $services = $container->services();'
            . ' $services->instanceof(App\Validation\Rule::class)->tag("app.rule"); $services->set(App\Validation\Dispatcher::class)->args([tagged_iterator("app.rule")]); };',
    ];

    public function testMethodsReadingTheStateAMethodWritesDependOnIt(): void
    {
        $graph = $this->buildProject(self::PROJECT)->graph;

        self::assertTrue($this->hasEdge($graph, 'App\Validation\Notification::all', 'App\Validation\Notification::add', Relation::ReadsStateOf, Confidence::Inferred));
        self::assertTrue($this->hasEdge($graph, 'App\Validation\Notification::hasErrors', 'App\Validation\Notification::add', Relation::ReadsStateOf));
        self::assertFalse($this->hasEdge($graph, 'App\Validation\Notification::now', 'App\Validation\Notification::__construct', Relation::ReadsStateOf), 'a property set by the constructor only links nothing');

        $impact = $this->impact($graph, 'App\Validation\Notification::add');
        self::assertTrue($impact['App\Validation\PromiseCheck']->throughState, 'it reads what add() records, through all()');
        self::assertFalse($impact['App\Validation\StockRule']->throughState);
        self::assertTrue($impact['App\Validation\Report']->throughState, 'one step further for a reader filtering on what it writes (INFERRED)');
    }

    public function testListsCanBeCompleteOrAlone(): void
    {
        $presenter = new TextPresenter(new GraphQuery($this->buildProject(self::PROJECT)->graph));

        $cut = $presenter->impact('App\Validation\Notification::add', 3, 1);
        self::assertStringContainsString('... 1 more', $cut);
        self::assertStringNotContainsString('  - ... ', $presenter->impact('App\Validation\Notification::add', 3, 0));

        $state = $presenter->impact('App\Validation\Notification::add', 3, 0, 'state');
        self::assertStringContainsString('Through the state it changes', $state);
        self::assertStringNotContainsString('Direct dependents', $state);
    }

    public function testCallsThroughAnInterfaceReachTheImplementation(): void
    {
        $graph = $this->buildProject(self::PROJECT)->graph;
        $impact = $this->impact($graph, 'App\Validation\StockRule::apply');

        self::assertSame(Confidence::Inferred, $impact['App\Validation\Validator']->confidence);
        self::assertSame('App\Validation\Rule::apply', $impact['App\Validation\Validator']->through);
        self::assertStringContainsString(
            'Called through Rule::apply(), which it implements',
            (new TextPresenter(new GraphQuery($graph)))->explain('App\Validation\StockRule::apply'),
        );
    }

    public function testATaggedCollectionIsListedNotFollowed(): void
    {
        $impact = $this->impact($this->buildProject(self::PROJECT)->graph, 'App\Validation\StockRule');

        self::assertFalse($impact['App\Validation\Dispatcher']->followed);
        self::assertArrayNotHasKey('App\Validation\Endpoint', $impact, 'the users of the dispatcher do not depend on one of its rules');
    }

    public function testTestsAreFollowedThroughTheirHelpers(): void
    {
        $impact = $this->impact($this->buildProject(self::PROJECT)->graph, 'App\Validation\StockRule', 1);

        self::assertArrayHasKey('App\Tests\Faker\FakeStockRule', $impact);
        self::assertArrayHasKey('App\Tests\OrderTest', $impact, 'beyond the depth limit, through the faker');
    }

    public function testInheritedCodeIsListedButNotFollowedAndSiblingsAreLeftOut(): void
    {
        $impact = $this->impact($this->buildProject(self::PROJECT)->graph, 'App\UseCase\Preview');

        self::assertFalse($impact['App\UseCase\AbstractUseCase']->followed, 'the template method calling handle() is shown');
        self::assertArrayNotHasKey('App\UseCase\Archive', $impact, 'parent::__construct() in a sibling runs the parent');
        self::assertArrayNotHasKey('App\UseCase\ArchiveController', $impact, 'it calls the template method on another subclass');
    }

    public function testStateReadersAreWeighedByTheConstantTheyTest(): void
    {
        $graph = $this->buildProject([
            'src/Type.php' => 'namespace App; enum Type { case CONTEXT; case SURFACE; }',
            'src/Log.php' => 'namespace App; final class Log { private array $entries = [];'
                . ' public function addContext(): void { $this->entries[] = Type::CONTEXT; }'
                . ' public function addSurface(): void { $this->entries[] = Type::SURFACE; }'
                . ' public function hasContext(): bool { return in_array(Type::CONTEXT, $this->entries, true); }'
                . ' public function hasSurface(): bool { return in_array(Type::SURFACE, $this->entries, true); }'
                . ' public function count(): int { return count($this->entries); } }',
        ])->graph;

        self::assertTrue($this->hasEdge($graph, 'App\Log::hasContext', 'App\Log::addContext', Relation::ReadsStateOf, Confidence::Inferred), 'it tests what addContext() writes');
        self::assertFalse($this->hasEdge($graph, 'App\Log::hasSurface', 'App\Log::addContext', Relation::ReadsStateOf), 'it tests what another writer writes');
        self::assertTrue($this->hasEdge($graph, 'App\Log::count', 'App\Log::addContext', Relation::ReadsStateOf, Confidence::Ambiguous), 'it reads everything');
    }

    public function testImpactListsEveryCallSiteAndPrefersACallToTheState(): void
    {
        $graph = $this->buildProject(self::PROJECT + [
            'src/Validation/Checker.php' => 'namespace App\Validation; final class Checker { public function check(Notification $n): void {'
                . ' $n->hasErrors(); } public function record(Notification $n): void { $n->add("x"); $n->add("y"); } }',
        ])->graph;
        $impact = $this->impact($graph, 'App\Validation\Notification::add');

        self::assertFalse($impact['App\Validation\Checker']->throughState, 'it calls add() itself');
        $sites = array_map(static fn ($edge): string => $edge->source . ' -> ' . $edge->target, $impact['App\Validation\Checker']->sites);
        self::assertContains('App\Validation\Checker::record -> App\Validation\Notification::add', $sites);
        self::assertContains('App\Validation\Checker::check -> App\Validation\Notification::hasErrors', $sites);
    }

    public function testAStrategyIsHandledByWhatItSupports(): void
    {
        $graph = $this->buildProject([
            'src/LineQuery.php' => 'namespace App; class LineQuery {}',
            'src/LineRule.php' => 'namespace App; class LineRule { public function supports(object $input): bool { return $input instanceof LineQuery; } }',
        ])->graph;

        self::assertTrue($this->hasEdge($graph, 'App\LineQuery', 'App\LineRule', Relation::HandledBy, Confidence::Inferred));
    }

    public function testExplainCountsFileLinksAndPathFindsTheDependencyBackwards(): void
    {
        $presenter = new TextPresenter(new GraphQuery($this->buildProject(self::PROJECT)->graph));

        self::assertMatchesRegularExpression('/<-- imports: \d+ files/', $presenter->explain('App\Validation\StockRule'));
        $path = $presenter->path('App\Validation\StockRule', 'App\Validation\Validator');
        self::assertStringContainsString('Validator depends on StockRule', $path);
    }

    public function testRoutesReachingAChangedHandlerAndConstructorInjection(): void
    {
        $graph = $this->buildProject([
            'src/CreateOrder.php' => 'namespace App; class CreateOrder { public function handle(): void {} }',
            'src/CreateOrderProcessor.php' => 'namespace App; class CreateOrderProcessor { public function __construct(private CreateOrder $useCase) {} public function process(): void {} }',
            'src/OrderResource.php' => 'namespace App; use ApiPlatform\Metadata\ApiResource; use ApiPlatform\Metadata\Post;'
                . ' #[ApiResource(operations: [new Post(uriTemplate: "/orders", processor: CreateOrderProcessor::class)])] class OrderResource {}',
            'config/services.php' => 'return static function ($container): void { App\Wiring::wire($container->services(), "orders"); };',
            'src/Wiring.php' => 'namespace App; class Wiring { public static function wire($services, string $prefix): void {} }',
        ])->graph;
        $impact = $this->impact($graph, 'App\CreateOrder');

        self::assertArrayHasKey('route:src/OrderResource.php#POST /orders', $impact, 'the processor holding it in its constructor serves the route');
        self::assertTrue($this->hasEdge($graph, 'file:config/services.php', 'App\Wiring::wire', Relation::Calls), 'a static call outside any method: the file calls it');
    }

    public function testTheCompactReport(): void
    {
        $presenter = new TextPresenter(new GraphQuery($this->buildProject([
            'src/Notification.php' => 'namespace App; final class Notification { private const CONTEXT = "c"; private const SURFACE = "s"; private array $items = [];'
                . ' public function add(string $code, string $message = ""): void { $this->items[] = [self::CONTEXT, $code]; }'
                . ' public function addSurface(string $code): void { $this->items[] = [self::SURFACE, $code]; }'
                . ' public function all(): array { return $this->items; } }',
            'src/Rule.php' => 'namespace App; final class Rule { public function apply(Notification $n): void { $n->add(code: "x", message: "y"); } }',
            'src/Untested.php' => 'namespace App; final class Untested { public function run(Notification $n): void { $n->add("z"); } }',
            'src/Runner.php' => 'namespace App; final class Runner { public function run(Rule $rule, Notification $n): bool {'
                . "\n \$before = count(\$n->all());\n \$rule->apply(\$n);\n return count(\$n->all()) > \$before; } }",
            'tests/RuleTest.php' => 'namespace App\Tests; use App\Rule; use App\Notification; final class RuleTest { public function testIt(): void { (new Rule())->apply(new Notification()); } }',
            'tests/Faker/FakeNotification.php' => 'namespace App\Tests\Faker; use App\Notification; final class FakeNotification { public static function unused(): Notification { $n = new Notification(); $n->add("a"); return $n; } }',
            'tests/ReadsAllTest.php' => 'namespace App\Tests; use App\Notification; final class ReadsAllTest { public function testIt(): void { (new Notification())->all(); } }',
        ])->graph));

        $text = $presenter->impact('App\Notification::add');
        self::assertStringContainsString('Rule (', $text);
        self::assertStringContainsString('apply L2 (named: code, message)', $text, 'a call with named arguments breaks on a rename');
        self::assertStringContainsString('compares all() before and after', $text);
        self::assertStringContainsString('No test touches: Untested', $text);
        self::assertStringContainsString('Unused (no caller): FakeNotification::unused()', $text);
        self::assertStringNotContainsString('ReadsAllTest', $text, 'a test reading all of the state: counted, not listed');
        self::assertStringContainsString('ReadsAllTest', $presenter->impact('App\Notification::add', 3, 40, 'state-tests'));

        $json = json_decode($presenter->impact('App\Notification::add', 3, 40, null, 'json'), true);
        self::assertIsArray($json);
        self::assertSame('App\Notification::add', $json['changed']['id'] ?? null);
        self::assertContains('Untested', $json['uncovered'] ?? []);
    }

    public function testReadersUsingWhatTheChangeWritesComeFirst(): void
    {
        $presenter = new TextPresenter(new GraphQuery($this->buildProject([
            'src/Kind.php' => 'namespace App; enum Kind: string { case Context = "context"; }',
            'src/Violation.php' => 'namespace App; final class Violation { public function __construct(public Kind $type) {} }',
            'src/Notification.php' => 'namespace App; final class Notification { private array $items = [];'
                . ' public function add(): void { $this->items[] = new Violation(Kind::Context); }'
                . ' /** @return list<Violation> */ public function all(): array { return $this->items; } }',
            'src/Response.php' => 'namespace App; final class Response { public static function create(Notification $n): array { $types = [];'
                . ' foreach ($n->all() as $violation) { $types[] = $violation->type->value; } return $types; } }',
            'src/Logger.php' => 'namespace App; final class Logger { public function log(Notification $n): int { return \\count($n->all()); } }',
            'src/Controller.php' => 'namespace App; final class Controller { public function __construct(private Logger $logger) {} public function show(Notification $n): array { $this->logger->log($n); return []; } }',
            'tests/ResponseTest.php' => 'namespace App\Tests; use App\Response; use App\Notification; final class ResponseTest { public function testIt(): void { Response::create(new Notification()); } }',
        ])->graph));

        $text = $presenter->impact('App\Notification::add');
        self::assertStringContainsString('Response [uses Kind]', $text, 'it serializes the type the change writes');
        self::assertStringNotContainsString('Logger', explode('Nothing in', $text)[0], 'reading all of it, AMBIGUOUS: counted, not listed');
        self::assertStringContainsString('+1 reading all of it: section state', $text);
        self::assertStringContainsString('Logger', $presenter->impact('App\Notification::add', 3, 40, 'state'));
        self::assertStringContainsString('Nothing in the application uses: Response (tests only)', $text);
        self::assertStringNotContainsString('uses: Response (tests only), Logger', $text, 'the controller uses the logger');
    }

    public function testARouteReachingTheChangeTwiceShowsItsOtherChains(): void
    {
        $presenter = new TextPresenter(new GraphQuery($this->buildProject([
            'src/Ledger.php' => 'namespace App; class Ledger { public function record(): void {} }',
            'src/Pricing.php' => 'namespace App; class Pricing { public function __construct(private Ledger $ledger) {} public function price(): void { $this->ledger->record(); } }',
            'src/Stock.php' => 'namespace App; class Stock { public function __construct(private Ledger $ledger) {} public function reserve(): void { $this->ledger->record(); } }',
            'src/OrderProcessor.php' => 'namespace App; class OrderProcessor { public function __construct(private Pricing $pricing, private Stock $stock) {}'
                . ' public function process(): void { $this->pricing->price(); $this->stock->reserve(); } }',
            'src/OrderResource.php' => 'namespace App; use ApiPlatform\Metadata\ApiResource; use ApiPlatform\Metadata\Post;'
                . ' #[ApiResource(operations: [new Post(uriTemplate: "/orders", processor: OrderProcessor::class)])] class OrderResource {}',
        ])->graph));

        $routes = $presenter->impact('App\Ledger::record', 3, 40, 'routes');
        self::assertStringContainsString('    also ← OrderProcessor::process() ← ', $routes, 'shown by default, up to 2');
        self::assertStringNotContainsString('other chain', $routes);
        self::assertStringContainsString('    also ← ', $presenter->impact('App\Ledger::record', 3, 0, 'routes'));
    }

    public function testCallEdgesCarryTheLinesOfTheirCallSites(): void
    {
        $graph = $this->buildProject([
            'src/A.php' => "namespace App; class A { public function run(B \$b): void {\n\$b->go();\n\n\$b->go(); } }",
            'src/B.php' => 'namespace App; class B { public function go(): void {} }',
        ])->graph;

        $edges = array_values(array_filter($graph->edges(), static fn ($edge): bool => $edge->relation === Relation::Calls));
        self::assertSame([3, 5], $edges[0]->lines());
        self::assertSame('3,5', $edges[0]->toArray()['lines'] ?? null);
        self::assertSame([3, 5], \PhpGraph\Graph\Edge::fromArray($edges[0]->toArray())->lines());
    }

    /**
     * @return array<string, ImpactedClass>
     */
    private function impact(\PhpGraph\Graph\Graph $graph, string $name, int $depth = 3): array
    {
        $query = new GraphQuery($graph);
        $classes = [];
        foreach ($query->impactOf($query->resolve($name) ?? self::fail('No node ' . $name), $depth)->classes as $class) {
            $classes[$class->class] = $class;
        }

        return $classes;
    }
}
