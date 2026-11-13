<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Relation;
use PhpGraph\Presentation\TextPresenter;
use PhpGraph\Query\GraphQuery;
use PhpGraph\Query\Result\ImpactedClass;
use PhpGraph\Tests\Support\Dig;
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

    public function testACallerTheContainerGivesAnotherImplementationIsNotReachedThroughTheInterface(): void
    {
        $graph = $this->buildProject([
            'src/Processor.php' => 'namespace App; interface Processor { public function process(): void; }',
            'src/PromotionProcessor.php' => 'namespace App; final class PromotionProcessor implements Processor { public function process(): void {} }',
            'src/PaymentProcessor.php' => 'namespace App; final class PaymentProcessor implements Processor { public function process(): void {} }',
            'src/LoggingProcessor.php' => 'namespace App; final class LoggingProcessor implements Processor { public function __construct(private Processor $inner) {} public function process(): void { $this->inner->process(); } }',
            'src/Autowired.php' => 'namespace App; final class Autowired { public function __construct(private Processor $processor) {} public function run(): void { $this->processor->process(); } }',
            'src/PaymentListener.php' => 'namespace App; final class PaymentListener { public function __construct(private Processor $processor) {} public function run(): void { $this->processor->process(); } }',
            'src/PromotionListener.php' => 'namespace App; final class PromotionListener { public function __construct(private Processor $processor) {} public function run(): void { $this->processor->process(); } }',
            'src/LoggedListener.php' => 'namespace App; final class LoggedListener { public function __construct(private Processor $processor) {} public function run(): void { $this->processor->process(); } }',
            'src/ChainProcessor.php' => 'namespace App; final class ChainProcessor implements Processor { /** @var list<Processor> */ private array $processors = []; public function add(Processor $p): void { $this->processors[] = $p; } public function process(): void { foreach ($this->processors as $p) { $p->process(); } } }',
            'src/ChainListener.php' => 'namespace App; final class ChainListener { public function __construct(private Processor $processor) {} public function run(): void { $this->processor->process(); } }',
            'config/services.php' => 'use function Symfony\Component\DependencyInjection\Loader\Configurator\service; return static function ($container): void { $services = $container->services();'
                . ' $services->set("app.promotion", App\PromotionProcessor::class); $services->set("app.payment", App\PaymentProcessor::class);'
                . ' $services->set("app.logged", App\LoggingProcessor::class)->args([service("app.promotion")]);'
                . ' $services->set(App\PaymentListener::class)->args([service("app.payment")]);'
                . ' $services->set(App\PromotionListener::class)->args([service("app.promotion")]);'
                . ' $services->set(App\LoggedListener::class)->args([service("app.logged")]);'
                . ' $services->set("app.chain", App\ChainProcessor::class); $services->set(App\ChainListener::class)->args([service("app.chain")]); };',
        ])->graph;
        $impact = $this->impact($graph, 'App\PromotionProcessor::process');

        self::assertArrayHasKey('App\Autowired', $impact, 'nothing in the configuration says which processor it holds');
        self::assertArrayHasKey('App\PromotionListener', $impact, 'the configuration gives it the changed processor');
        self::assertArrayHasKey('App\LoggedListener', $impact, 'the processor it is given wraps the changed one');
        self::assertArrayHasKey('App\ChainListener', $impact, 'its chain calls processors the configuration does not name (a compiler pass adds them)');
        self::assertArrayNotHasKey('App\PaymentListener', $impact, 'the configuration gives it another processor, which never runs the changed one');
    }

    public function testACallerAlsoAutowiredWithTheInterfaceIsReachedThroughIt(): void
    {
        $graph = $this->buildProject([
            'src/Processor.php' => 'namespace App; interface Processor { public function process(): void; }',
            'src/PromotionProcessor.php' => 'namespace App; final class PromotionProcessor implements Processor { public function process(): void {} }',
            'src/PaymentProcessor.php' => 'namespace App; final class PaymentProcessor implements Processor { public function process(): void {} }',
            'src/Listener.php' => 'namespace App; final class Listener { public function __construct(private Processor $audit, private Processor $processor) {} public function run(): void { $this->processor->process(); } }',
            'config/services.php' => 'use function Symfony\Component\DependencyInjection\Loader\Configurator\service; return static function ($container): void { $services = $container->services();'
                . ' $services->set("app.payment", App\PaymentProcessor::class);'
                . ' $services->set(App\Listener::class)->arg("$audit", service("app.payment")); };',
        ])->graph;

        self::assertTrue($this->hasEdge($graph, 'App\Listener', 'App\Processor', Relation::Autowires), 'one of its two processors is not named by the configuration');
        self::assertArrayHasKey('App\Listener', $this->impact($graph, 'App\PromotionProcessor::process'), 'the autowired processor may be the changed one');
    }

    public function testACallerGivenADecoratedServiceIsReachedThroughTheCompositeDecoratingIt(): void
    {
        $graph = $this->buildProject([
            'src/LocaleContext.php' => 'namespace App; interface LocaleContext { public function locale(): string; }',
            'src/FixedLocaleContext.php' => 'namespace App; final class FixedLocaleContext implements LocaleContext { public function locale(): string { return "en"; } }',
            'src/RequestLocaleContext.php' => 'namespace App; final class RequestLocaleContext implements LocaleContext { public function locale(): string { return "fr"; } }',
            'src/CompositeLocaleContext.php' => 'namespace App; final class CompositeLocaleContext implements LocaleContext { /** @var list<LocaleContext> */ private array $contexts = []; public function add(LocaleContext $c): void { $this->contexts[] = $c; } public function locale(): string { foreach ($this->contexts as $c) { return $c->locale(); } return ""; } }',
            'src/LocaleExtension.php' => 'namespace App; final class LocaleExtension { public function __construct(private LocaleContext $context) {} public function run(): string { return $this->context->locale(); } }',
            'config/services.php' => 'use function Symfony\Component\DependencyInjection\Loader\Configurator\service; return static function ($container): void { $services = $container->services();'
                . ' $services->set("app.locale", App\FixedLocaleContext::class);'
                . ' $services->set("app.locale.composite", App\CompositeLocaleContext::class)->decorate("app.locale", null, 256);'
                . ' $services->set(App\LocaleExtension::class)->args([service("app.locale")]); };',
        ])->graph;

        self::assertArrayHasKey('App\LocaleExtension', $this->impact($graph, 'App\RequestLocaleContext::locale'), 'it is given the composite decorating the service, which a compiler pass fills with any context');
    }

    public function testAChangedConstructorReachesWhatInstantiatesItsClassAndTheSubclassesInheritingIt(): void
    {
        $graph = $this->buildProject([
            'src/OrderPlaced.php' => 'namespace App; class OrderPlaced { public function __construct(private string $id) {} public function id(): string { return $this->id; } }',
            'src/UrgentOrderPlaced.php' => 'namespace App; final class UrgentOrderPlaced extends OrderPlaced {}',
            'src/LateOrderPlaced.php' => 'namespace App; final class LateOrderPlaced extends OrderPlaced { public function __construct() { parent::__construct("late"); } }',
            'src/PlaceOrder.php' => 'namespace App; final class PlaceOrder { public function handle(): OrderPlaced { return new OrderPlaced("1"); } }',
            'src/PlaceUrgentOrder.php' => 'namespace App; final class PlaceUrgentOrder { public function handle(): OrderPlaced { return new UrgentOrderPlaced("2"); } }',
            'src/PlaceLateOrder.php' => 'namespace App; final class PlaceLateOrder { public function handle(): OrderPlaced { return new LateOrderPlaced(); } }',
            'src/ReadOrder.php' => 'namespace App; final class ReadOrder { public function handle(OrderPlaced $event): string { return $event->id(); } }',
        ])->graph;
        $impact = $this->impact($graph, 'App\OrderPlaced::__construct');

        self::assertArrayHasKey('App\PlaceOrder', $impact, 'new OrderPlaced() runs the constructor');
        self::assertArrayHasKey('App\PlaceUrgentOrder', $impact, 'a subclass without a constructor runs its parent\'s');
        self::assertArrayHasKey('App\LateOrderPlaced', $impact, 'parent::__construct() calls it');
        self::assertArrayNotHasKey('App\ReadOrder', $impact, 'reading the object does not run its constructor');
    }

    public function testAChangedHandlerReachesWhatSendsItsMessageAndItsRoutes(): void
    {
        $graph = $this->buildProject([
            'src/PlaceOrder.php' => 'namespace App; final class PlaceOrder {}',
            'src/PlaceOrderHandler.php' => 'namespace App; use Symfony\Component\Messenger\Attribute\AsMessageHandler; #[AsMessageHandler] final class PlaceOrderHandler { public function __invoke(PlaceOrder $command): void {} }',
            'src/OrderController.php' => 'namespace App; use Symfony\Component\Routing\Attribute\Route; final class OrderController { public function __construct(private \Symfony\Component\Messenger\MessageBusInterface $bus) {}'
                . ' #[Route("/orders", methods: ["POST"])] public function place(): void { $this->bus->dispatch(new PlaceOrder()); } }',
            'src/OrderFactory.php' => 'namespace App; final class OrderFactory { public function make(): PlaceOrder { return new PlaceOrder(); } }',
        ])->graph;
        $impact = $this->impact($graph, 'App\PlaceOrderHandler::__invoke');

        self::assertArrayHasKey('App\OrderController', $impact, 'it sends the message the handler handles');
        self::assertSame(Confidence::Inferred, $impact['App\OrderController']->confidence);
        self::assertArrayHasKey('route:src/OrderController.php#POST /orders', $impact);
        self::assertArrayNotHasKey('App\PlaceOrder', $impact, 'the message does not depend on its handler');
        self::assertArrayNotHasKey('App\OrderFactory', $impact, 'building the message sends nothing');
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
        self::assertSame('App\Notification::add', Dig::at($json, 'changed', 'id') ?? null);
        self::assertContains('Untested', Dig::list($json, 'uncovered'));
    }

    public function testALargeImpactIsCompactUnlessTheFullListsAreAskedFor(): void
    {
        $files = [
            'src/Logger.php' => 'namespace App; final class Logger { public function add(string $type): void {} }',
            'src/Http/Controller.php' => 'namespace App\Http; use App\Service0; final class Controller { public function store(Service0 $s): void { $s->run(); } }',
            'tests/LoggerTest.php' => 'namespace App\Tests; use App\Logger; final class LoggerTest { public function testIt(): void { (new Logger())->add("a"); } }',
            'tests/ServiceTest.php' => 'namespace App\Tests; use App\Service0; use App\Logger; final class ServiceTest { public function testIt(): void { (new Service0(new Logger()))->run(); } }',
        ];
        for ($i = 0; $i <= 20; ++$i) {
            $files["src/Service{$i}.php"] = "namespace App; final class Service{$i} { public function __construct(private Logger \$logger) {} public function run(): void { \$this->logger->add('x'); } }";
        }
        $presenter = new TextPresenter(new GraphQuery($this->buildProject($files)->graph));

        $compact = $presenter->impact('App\Logger::add');
        self::assertStringContainsString('Large impact, compact', $compact, '21 direct dependents');
        self::assertStringContainsString('Service20 (App): run L2', $compact, 'the classes calling the change keep their call sites');
        self::assertStringContainsString("  App\\Http: Controller\n", $compact, 'a class farther away: its name only');
        self::assertStringContainsString('LoggerTest.php', $compact, 'a test calling the change is listed');
        self::assertStringContainsString('no ←: the test uses the change itself', $compact, 'a listed test without a chain may need changing, not only running');
        self::assertStringNotContainsString('ServiceTest.php', $compact, 'a test reaching it through another class is counted');
        self::assertStringContainsString('+1 through other classes', $compact);
        self::assertStringContainsString('Service18 and 11 more', $compact, 'the lists of names are cut too');

        $full = $presenter->impact('App\Logger::add', 3, 0);
        self::assertStringNotContainsString('Large impact', $full, 'limit 0: the full lists');
        self::assertStringContainsString('ServiceTest.php', $full);
        self::assertStringContainsString('Controller (App\\Http): store', $full);
    }

    public function testADependentShowsItsNamespaceAndTheRelationThatReachedIt(): void
    {
        $presenter = new TextPresenter(new GraphQuery($this->buildProject([
            'src/Storage/Save/Query/Widget.php' => 'namespace App\Storage\Save\Query; abstract class Widget {}',
            'src/Storage/Factory.php' => 'namespace App\Storage; use App\Storage\Save\Query\Widget; final class Factory { public function make(): Widget { return new Gadget(); } }',
            'src/Storage/Gadget.php' => 'namespace App\Storage; final class Gadget extends Save\Query\Widget {}',
        ])->graph));

        $text = $presenter->impact('App\Storage\Save\Query\Widget');

        self::assertStringContainsString('Gadget (App\Storage) [EXTRACTED]: extends it', $text, 'its whole namespace, and a relation of the class itself said once');
        self::assertStringNotContainsString('extends (extends)', $text);
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
        self::assertStringContainsString('Response (App) [uses Kind]', $text, 'it serializes the type the change writes');
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
