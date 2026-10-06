<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Builder\BuildResult;
use PhpGraph\Builder\GraphBuilder;
use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Graph;
use PhpGraph\Graph\NodeKind;
use PhpGraph\Graph\Relation;
use PhpGraph\Storage\JsonGraphStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One case per PHP construct: source files in, expected edge out.
 */
final class ExtractionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/phpgraph-extraction-' . bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->root);
    }

    /**
     * @return iterable<string, array{array<string, string>, string, string, Relation, Confidence}>
     */
    public static function constructs(): iterable
    {
        yield 'interface extends interface' => [
            ['a.php' => 'namespace App; interface A {} interface B extends A {}'],
            'App\B', 'App\A', Relation::Extends, Confidence::Extracted,
        ];
        yield 'enum implements interface' => [
            ['a.php' => 'namespace App; interface HasLabel {} enum Status: string implements HasLabel { case On = "on"; }'],
            'App\Status', 'App\HasLabel', Relation::Implements, Confidence::Extracted,
        ];
        yield 'trait use' => [
            ['a.php' => 'namespace App; trait Timestamps {} class Post { use Timestamps; }'],
            'App\Post', 'App\Timestamps', Relation::UsesTrait, Confidence::Extracted,
        ];
        yield 'call to a trait method through $this' => [
            ['a.php' => 'namespace App; trait Timestamps { public function touch(): void {} } class Post { use Timestamps; public function save(): void { $this->touch(); } }'],
            'App\Post::save', 'App\Timestamps::touch', Relation::Calls, Confidence::Inferred,
        ];
        yield 'group use' => [
            ['a.php' => 'namespace App; use Domain\{Order, Invoice};'],
            'file:a.php', 'Domain\Invoice', Relation::Imports, Confidence::Extracted,
        ];
        yield 'static call' => [
            ['a.php' => 'namespace App; class Id { public static function generate(): self { return new self(); } } class Order { public function __construct() { Id::generate(); } }'],
            'App\Order::__construct', 'App\Id::generate', Relation::Calls, Confidence::Inferred,
        ];
        yield 'parent call' => [
            ['a.php' => 'namespace App; class Base { public function boot(): void {} } class Child extends Base { public function boot(): void { parent::boot(); } }'],
            'App\Child::boot', 'App\Base::boot', Relation::Calls, Confidence::Inferred,
        ];
        yield 'nullsafe call on a promoted property' => [
            ['a.php' => 'namespace App; class Mailer { public function send(): void {} } class Notifier { public function __construct(private ?Mailer $mailer) {} public function notify(): void { $this->mailer?->send(); } }'],
            'App\Notifier::notify', 'App\Mailer::send', Relation::Calls, Confidence::Inferred,
        ];
        yield 'union parameter type' => [
            ['a.php' => 'namespace App; class Card {} class Cash {} class Checkout { public function pay(Card|Cash $means): void {} }'],
            'App\Checkout::pay', 'App\Cash', Relation::References, Confidence::Extracted,
        ];
        yield 'return type' => [
            ['a.php' => 'namespace App; class Order {} interface Orders { public function find(): ?Order; }'],
            'App\Orders::find', 'App\Order', Relation::References, Confidence::Extracted,
        ];
        yield 'attribute' => [
            ['a.php' => 'namespace App; #[\Attribute] class AsHandler {} #[AsHandler] class PlaceOrderHandler {}'],
            'App\PlaceOrderHandler', 'App\AsHandler', Relation::References, Confidence::Extracted,
        ];
        yield 'catch' => [
            ['a.php' => 'namespace App; class Failed extends \Exception {} class Job { public function run(): void { try {} catch (Failed $e) {} } }'],
            'App\Job::run', 'App\Failed', Relation::References, Confidence::Extracted,
        ];
        yield 'instanceof' => [
            ['a.php' => 'namespace App; class Paid {} class Policy { public function allows(object $o): bool { return $o instanceof Paid; } }'],
            'App\Policy::allows', 'App\Paid', Relation::References, Confidence::Extracted,
        ];
        yield 'class constant' => [
            ['a.php' => 'namespace App; class Status { const OPEN = 1; } class Ticket { public function open(): int { return Status::OPEN; } }'],
            'App\Ticket::open', 'App\Status', Relation::References, Confidence::Extracted,
        ];
        yield 'instantiation' => [
            ['a.php' => 'namespace App; class Order {} class Factory { public function make(): object { return new Order(); } }'],
            'App\Factory::make', 'App\Order', Relation::Instantiates, Confidence::Extracted,
        ];
        yield 'call on an untyped receiver with a unique method name' => [
            ['a.php' => 'namespace App; class Ledger { public function reconcile(): void {} } class Job { public function run($ledger): void { $ledger->reconcile(); } }'],
            'App\Job::run', 'App\Ledger::reconcile', Relation::Calls, Confidence::Ambiguous,
        ];
        yield 'class name written with another case' => [
            ['a.php' => 'namespace App; class Order {}', 'b.php' => 'namespace App; class Factory { public function make(): object { return new order(); } }'],
            'App\Factory::make', 'App\Order', Relation::Instantiates, Confidence::Extracted,
        ];
        yield 'method name written with another case' => [
            ['a.php' => 'namespace App; class Order { public function total(): int { return 0; } } class Cart { public function sum(Order $o): int { return $o->TOTAL(); } }'],
            'App\Cart::sum', 'App\Order::total', Relation::Calls, Confidence::Inferred,
        ];
        yield 'chained call through a native return type' => [
            ['a.php' => 'namespace App; class Customer { public function name(): string { return ""; } } class Order { public function customer(): Customer { return new Customer(); } } class Mailer { public function send(Order $o): void { $o->customer()->name(); } }'],
            'App\Mailer::send', 'App\Customer::name', Relation::Calls, Confidence::Inferred,
        ];
        yield 'chained call across files' => [
            ['a.php' => 'namespace App; class Customer { public function name(): string { return ""; } }', 'b.php' => 'namespace App; class Order { public function customer(): Customer { return new Customer(); } }', 'c.php' => 'namespace App; class Mailer { public function send(Order $o): void { $o->customer()->name(); } }'],
            'App\Mailer::send', 'App\Customer::name', Relation::Calls, Confidence::Inferred,
        ];
        yield 'chained call through a static return type keeps the receiver type' => [
            ['a.php' => 'namespace App; class Query { public function where(): static { return $this; } } class OrderQuery extends Query { public function paid(): void {} } class Report { public function run(OrderQuery $q): void { $q->where()->paid(); } }'],
            'App\Report::run', 'App\OrderQuery::paid', Relation::Calls, Confidence::Inferred,
        ];
        yield 'chained call through a self return type' => [
            ['a.php' => 'namespace App; class Money { public function add(): self { return $this; } public function amount(): int { return 0; } } class Cart { public function total(Money $m): int { return $m->add()->amount(); } }'],
            'App\Cart::total', 'App\Money::amount', Relation::Calls, Confidence::Inferred,
        ];
        yield 'chained nullsafe call through a nullable return type' => [
            ['a.php' => 'namespace App; class Customer { public function name(): string { return ""; } } class Order { public function customer(): ?Customer { return null; } } class Mailer { public function send(Order $o): void { $o->customer()?->name(); } }'],
            'App\Mailer::send', 'App\Customer::name', Relation::Calls, Confidence::Inferred,
        ];
        yield 'chained call on a static call' => [
            ['a.php' => 'namespace App; class Clock { public function now(): int { return 0; } } class Clocks { public static function system(): Clock { return new Clock(); } } class Job { public function run(): void { Clocks::system()->now(); } }'],
            'App\Job::run', 'App\Clock::now', Relation::Calls, Confidence::Inferred,
        ];
        yield 'chained call through a docblock @return' => [
            ['a.php' => 'namespace App\Domain; class Customer { public function name(): string { return ""; } }', 'b.php' => 'namespace App; use App\Domain\Customer as Client; class Order { /** @return Client|null */ public function customer() { return null; } } class Mailer { public function send(Order $o): void { $o->customer()->name(); } }'],
            'App\Mailer::send', 'App\Domain\Customer::name', Relation::Calls, Confidence::Inferred,
        ];
        yield 'chained call through a docblock @return $this' => [
            ['a.php' => 'namespace App; class Order { /** @return $this */ public function setPaid() { return $this; } public function save(): void {} } class Job { public function run(Order $o): void { $o->setPaid()->save(); } }'],
            'App\Job::run', 'App\Order::save', Relation::Calls, Confidence::Inferred,
        ];
        yield 'local assigned from a call' => [
            ['a.php' => 'namespace App; class Customer { public function name(): string { return ""; } } class Order { public function customer(): Customer { return new Customer(); } } class Mailer { public function send(Order $o): void { $c = $o->customer(); $c->name(); } }'],
            'App\Mailer::send', 'App\Customer::name', Relation::Calls, Confidence::Inferred,
        ];
        yield 'local assigned from a call on itself' => [
            ['a.php' => 'namespace App; class Item { public function label(): string { return ""; } } class Node { public function item(): Item { return new Item(); } } class Tree { public function walk(Node $n): void { $n = $n->item(); $n->label(); } }'],
            'App\Tree::walk', 'App\Item::label', Relation::Calls, Confidence::Inferred,
        ];
        yield 'local assigned from new' => [
            ['a.php' => 'namespace App; class Order { public function total(): int { return 0; } } class Cart { public function sum(): int { $o = new Order(); return $o->total(); } }'],
            'App\Cart::sum', 'App\Order::total', Relation::Calls, Confidence::Inferred,
        ];
        yield 'local assigned from a typed property' => [
            ['a.php' => 'namespace App; class Orders { public function save(): void {} } class Handler { public function __construct(private Orders $orders) {} public function handle(): void { $r = $this->orders; $r->save(); } }'],
            'App\Handler::handle', 'App\Orders::save', Relation::Calls, Confidence::Inferred,
        ];
        yield 'property of another object' => [
            ['a.php' => 'namespace App; class Customer { public function name(): string { return ""; } } class Order { public Customer $customer; } class Mailer { public function send(Order $o): void { $o->customer->name(); } }'],
            'App\Mailer::send', 'App\Customer::name', Relation::Calls, Confidence::Inferred,
        ];
        yield 'property inherited from a parent class' => [
            ['a.php' => 'namespace App; class Orders { public function save(): void {} } abstract class Base { protected Orders $orders; } class Handler extends Base { public function handle(): void { $this->orders->save(); } }'],
            'App\Handler::handle', 'App\Orders::save', Relation::Calls, Confidence::Inferred,
        ];
        yield 'property typed by a docblock @var' => [
            ['a.php' => 'namespace App; class Orders { public function save(): void {} } class Handler { /** @var Orders */ private $orders; public function handle(): void { $this->orders->save(); } }'],
            'App\Handler::handle', 'App\Orders::save', Relation::Calls, Confidence::Inferred,
        ];
        yield 'local typed by an inline @var' => [
            ['a.php' => 'namespace App; class Customer { public function name(): string { return ""; } } class Mailer { public function send(object $locator): void { /** @var Customer $c */ $c = $locator->get(); $c->name(); } }'],
            'App\Mailer::send', 'App\Customer::name', Relation::Calls, Confidence::Inferred,
        ];
        yield 'local typed by an unnamed inline @var' => [
            ['a.php' => 'namespace App; class Customer { public function name(): string { return ""; } } class Mailer { public function send(object $locator): void { /** @var Customer */ $c = $locator->get(); $c->name(); } }'],
            'App\Mailer::send', 'App\Customer::name', Relation::Calls, Confidence::Inferred,
        ];
        yield 'caught exception' => [
            ['a.php' => 'namespace App; class Failed extends \Exception { public function order(): int { return 0; } } class Job { public function run(): void { try {} catch (Failed $e) { $e->order(); } } }'],
            'App\Job::run', 'App\Failed::order', Relation::Calls, Confidence::Inferred,
        ];
        yield 'invocation of an invokable object' => [
            ['a.php' => 'namespace App; class PlaceOrder { public function __invoke(): void {} } class Controller { public function __construct(private PlaceOrder $placeOrder) {} public function post(): void { ($this->placeOrder)(); } }'],
            'App\Controller::post', 'App\PlaceOrder::__invoke', Relation::Calls, Confidence::Inferred,
        ];
        yield 'typed closure parameter' => [
            ['a.php' => 'namespace App; class Order { public function total(): int { return 0; } } class Cart { public function sum(array $orders): void { array_map(fn (Order $o) => $o->total(), $orders); } }'],
            'App\Cart::sum', 'App\Order::total', Relation::Calls, Confidence::Inferred,
        ];
    }

    /**
     * @param array<string, string> $files
     */
    #[DataProvider('constructs')]
    public function testExtractsConstruct(array $files, string $source, string $target, Relation $relation, Confidence $confidence): void
    {
        $graph = $this->build($files)->graph;

        self::assertTrue(
            $this->hasEdge($graph, $source, $target, $relation, $confidence),
            \sprintf("Missing %s --%s--> %s [%s]. Edges:\n%s", $source, $relation->value, $target, $confidence->value, $this->dump($graph)),
        );
    }

    /**
     * Calls whose receiver type is unknown or uncertain: no INFERRED edge, so precision does not drop.
     *
     * @return iterable<string, array{array<string, string>, string, string}>
     */
    public static function untypedCalls(): iterable
    {
        yield 'union return type' => [
            ['a.php' => 'namespace App; class Customer { public function name(): string { return ""; } } class Company { public function name(): string { return ""; } } class Order { public function owner(): Customer|Company { return new Customer(); } } class Mailer { public function send(Order $o): void { $o->owner()->name(); } }'],
            'App\Mailer::send', 'App\Customer::name',
        ];
        yield 'local reassigned from an untyped value' => [
            ['a.php' => 'namespace App; class Customer { public function name(): string { return ""; } } class Mailer { public function send($any): void { $c = new Customer(); $c = $any->load(); $c->name(); } }'],
            'App\Mailer::send', 'App\Customer::name',
        ];
        yield 'local overwritten by foreach' => [
            ['a.php' => 'namespace App; class Customer { public function name(): string { return ""; } } class Mailer { public function send(array $all): void { $c = new Customer(); foreach ($all as $c) { $c->name(); } } }'],
            'App\Mailer::send', 'App\Customer::name',
        ];
        yield 'local assigned inside a closure' => [
            ['a.php' => 'namespace App; class Customer { public function name(): string { return ""; } } class Mailer { public function send($c): void { $f = function () { $c = new Customer(); }; $c->name(); } }'],
            'App\Mailer::send', 'App\Customer::name',
        ];
        yield 'docblock @return of a template parameter' => [
            ['a.php' => 'namespace App; class T { public function name(): string { return ""; } } class Box { /** @template T @return T */ public function get() { return null; } } class Mailer { public function send(Box $b): void { $b->get()->name(); } }'],
            'App\Mailer::send', 'App\T::name',
        ];
    }

    /**
     * @param array<string, string> $files
     */
    #[DataProvider('untypedCalls')]
    public function testInfersNoCallWhenTheReceiverTypeIsUncertain(array $files, string $source, string $target): void
    {
        $graph = $this->build($files)->graph;

        self::assertFalse(
            $this->hasEdge($graph, $source, $target, Relation::Calls, Confidence::Inferred),
            \sprintf("Unexpected %s --calls--> %s [INFERRED]. Edges:\n%s", $source, $target, $this->dump($graph)),
        );
    }

    public function testCountsChainsThatLeaveTheProject(): void
    {
        $calls = $this->build(['a.php' => 'namespace App; class Repo { public function __construct(private \Doctrine\EntityManager $em) {} public function find(): void { $this->em->getRepository()->findAll(); $this->em->clear(); } }'])->calls;

        self::assertSame(['total' => 3, 'inferred' => 0, 'ambiguous' => 0, 'outsideProject' => 2, 'unknownReceiver' => 1, 'chainOutsideProject' => 1], $calls->toArray());
    }

    public function testCountsTestCodeCallsApart(): void
    {
        $result = $this->build([
            'a.php' => 'namespace App; class Order { public function total(): int { return 0; } } class Cart { public function sum(Order $o): int { return $o->total(); } }',
            'OrderTest.php' => 'namespace App; class OrderTest { public function testTotal(Order $o, $mock): void { $o->total(); $mock->expects(); } }',
        ]);

        self::assertSame(['total' => 3, 'inferred' => 2, 'ambiguous' => 0, 'outsideProject' => 0, 'unknownReceiver' => 1, 'chainOutsideProject' => 0], $result->calls->toArray());
        self::assertSame(['total' => 2, 'inferred' => 1, 'ambiguous' => 0, 'outsideProject' => 0, 'unknownReceiver' => 1, 'chainOutsideProject' => 0], $result->testCalls->toArray());
        self::assertSame(['total' => 1, 'inferred' => 1, 'ambiguous' => 0, 'outsideProject' => 0, 'unknownReceiver' => 0, 'chainOutsideProject' => 0], $result->applicationCalls()->toArray());
    }

    public function testIgnoresAnonymousClassBodies(): void
    {
        $graph = $this->build(['a.php' => 'namespace App; class Order {} $handler = new class { public function run(): void { new Order(); } };'])->graph;

        self::assertSame(['file:a.php', 'App\Order'], array_keys($graph->nodes()));
    }

    public function testNameWrittenWithAnotherCaseCreatesNoExternalNode(): void
    {
        $graph = $this->build(['a.php' => 'namespace App; class Order {}', 'b.php' => 'namespace App; function make(): Order { return new ORDER(); }'])->graph;

        self::assertNull($graph->node('App\ORDER'));
        self::assertSame(NodeKind::PhpClass, $graph->node('App\Order')?->kind);
    }

    public function testReportsDuplicateDeclarations(): void
    {
        $result = $this->build(['a.php' => 'namespace App; class Order {}', 'b.php' => 'namespace App; class Order {}']);

        self::assertSame(['App\Order' => ['a.php', 'b.php']], $result->duplicates);
    }

    public function testRecordsParseFailuresWithoutStopping(): void
    {
        $result = $this->build(['a.php' => 'namespace App; class Order {}', 'b.php' => 'namespace App; class {']);

        self::assertSame(1, $result->filesParsed);
        self::assertArrayHasKey('b.php', $result->failures);
    }

    public function testSkipsBladeTemplates(): void
    {
        $result = $this->build(['a.php' => 'namespace App; class Order {}', 'page.blade.php' => '?><?xml version="1.0"?>{{ $title }}']);

        self::assertSame(1, $result->filesParsed);
        self::assertSame([], $result->failures);
    }

    public function testSavingLeavesNoTemporaryFile(): void
    {
        $path = $this->root . '/graph.json';
        (new JsonGraphStorage())->save($this->build(['a.php' => 'namespace App; class Order {}'])->graph, $path);

        self::assertSame([$path], glob($this->root . '/*.json*'));
        self::assertTrue((new JsonGraphStorage())->load($path)->hasNode('App\Order'));
    }

    public function testAParameterPassedOnUntouchedIsRecordedButNotOneShadowedOrReassigned(): void
    {
        $extraction = (new \PhpGraph\Extractor\PhpFileExtractor())->extract('<?php namespace App; class UseCase {'
            . ' public function __construct(private Pipeline $pipeline, private Builder $builder) {}'
            . ' public function a(Query $query): void { $this->pipeline->run($query, function () use ($query) { $this->builder->setUp(query: $query); }); }'
            . ' public function b(Query $query): void { $this->pipeline->run($query, function (Query $query) { $this->builder->setUp($query); }); }'
            . ' public function c(Query $query): void { $this->pipeline->run($query, function () { $this->builder->setUp($query); }); }'
            . ' public function d(Query $query): void { $this->pipeline->run($query); $query = new Query(); $this->builder->setUp($query); }'
            . ' public function e(Query $query): void { $this->builder->setUp(1, $query); $this->pipeline->run($query); }'
            . ' public function f(Query $query): void { Factory::make($query); } }', 'a.php');

        $passes = array_map(static fn (array $pass): string => $pass[0] . ' ' . $pass[1] . ' ' . $pass[3] . ' ' . $pass[4], $extraction->parameterPasses);
        self::assertSame(['App\UseCase::a query run 0', 'App\UseCase::a query setUp query', 'App\UseCase::b query run 0', 'App\UseCase::c query run 0', 'App\UseCase::d query run 0', 'App\UseCase::e query setUp 1', 'App\UseCase::e query run 0'], $passes, 'captured by use; not a closure parameter, not uncaptured, not reassigned; only a parameter given to a held service (not f)');
        self::assertSame('pipeline:App\Pipeline,builder:App\Builder', $extraction->methodParameters['App\UseCase::__construct']);
    }

    /**
     * @param array<string, string> $files
     */
    private function build(array $files): BuildResult
    {
        foreach ($files as $name => $code) {
            file_put_contents($this->root . '/' . $name, "<?php\n" . $code . "\n");
        }

        return (new GraphBuilder())->build($this->root);
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

    private function dump(Graph $graph): string
    {
        return implode("\n", array_map(
            static fn ($edge): string => \sprintf('  %s --%s--> %s [%s]', $edge->source, $edge->relation->value, $edge->target, $edge->confidence->value),
            $graph->edges(),
        ));
    }
}
