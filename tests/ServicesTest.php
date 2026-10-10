<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Builder\ServiceMap;
use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\NodeKind;
use PhpGraph\Graph\Relation;
use PhpGraph\Project\ProjectConfig;
use PhpGraph\Project\ProjectGraph;
use PhpGraph\Query\GraphQuery;
use PhpGraph\Storage\JsonGraphStorage;
use PhpGraph\Tests\Support\Dig;
use PhpGraph\Tests\Support\TemporaryProject;
use PHPUnit\Framework\TestCase;

/**
 * Multi-service repositories: one id space per service, and links between services through contracts only.
 */
final class ServicesTest extends TestCase
{
    use TemporaryProject;

    private const TWO_SERVICES = [
        'composer.json' => '{"name": "acme/platform"}',
        'billing/composer.json' => '{"name": "acme/billing", "autoload": {"psr-4": {"App\\\\": "src/"}}}',
        'billing/src/PlaceOrder.php' => 'namespace App; class PlaceOrder {}',
        'billing/src/Checkout.php' => 'namespace App; class Checkout { public function __construct(private \Symfony\Component\Messenger\MessageBusInterface $bus) {} public function run(): void { $this->bus->dispatch(new PlaceOrder()); } }',
        'shipping/composer.json' => '{"name": "acme/shipping", "autoload": {"psr-4": {"App\\\\": "src/"}}}',
        'shipping/src/PlaceOrder.php' => 'namespace App; class PlaceOrder {}',
        'shipping/src/PlaceOrderHandler.php' => 'namespace App; #[\Symfony\Component\Messenger\Attribute\AsMessageHandler] class PlaceOrderHandler { public function __invoke(PlaceOrder $command): void { new Clock(); } }',
        'shared/Clock.php' => 'namespace App; class Clock {}',
    ];

    public function testDetectsIndependentApplicationsButNotMonorepoPackages(): void
    {
        $this->write([
            'composer.json' => '{"autoload": {"psr-4": {"Shop\\\\Bundle\\\\": "src/Bundle/", "Shop\\\\StateMachine\\\\": "src/StateMachine/src"}}}',
            'src/Bundle/CoreBundle/composer.json' => '{}',
            'src/StateMachine/composer.json' => '{}',
            'tests/Fixtures/app/composer.json' => '{}',
            'tools/build/composer.json' => '{}',
        ]);
        $files = ['composer.json', 'src/Bundle/CoreBundle/composer.json', 'src/StateMachine/composer.json', 'tests/Fixtures/app/composer.json', 'tools/build/composer.json'];

        self::assertFalse(ServiceMap::detect($this->root, $files)->isMultiService(), 'Packages autoloaded by the root, test fixtures and one lone tool: one service');

        $this->write([
            'composer.json' => '{"replace": {"acme/cache": "self.version", "acme/coroutine": "self.version"}}',
            'src/cache/composer.json' => '{"name": "acme/cache", "autoload": {"psr-4": {"Acme\\\\Cache\\\\": "src/"}}}',
            'src/helper/composer.json' => '{"name": "acme/helper", "require": {"acme/coroutine": "*"}, "autoload": {"files": ["src/Functions.php"]}}',
        ]);
        self::assertFalse(
            ServiceMap::detect($this->root, ['composer.json', 'src/cache/composer.json', 'src/helper/composer.json', 'tools/build/composer.json'])->isMultiService(),
            'A package the root replaces, or one requiring a package the root replaces, is a part of the monorepo',
        );

        $this->write([
            'composer.json' => '{"autoload": {"psr-4": {"Shop\\\\Bundle\\\\": "src/Bundle/", "Shop\\\\StateMachine\\\\": "src/StateMachine/src"}}}',
            'services/billing/composer.json' => '{"autoload": {"psr-4": {"Billing\\\\": "src/"}}}',
            'services/shipping/composer.json' => '{"autoload": {"psr-4": {"Shipping\\\\": "src/"}}}',
            'tools/build/composer.json' => '{"autoload": {"psr-4": {"Build\\\\": "src/"}}}',
        ]);
        $map = ServiceMap::detect($this->root, [...$files, 'services/billing/composer.json', 'services/shipping/composer.json']);

        self::assertSame(['services/billing', 'services/shipping', 'tools/build', ServiceMap::ROOT], $map->names());
        self::assertSame('services/billing', $map->serviceOf('services/billing/src/Order.php'));
        self::assertSame(ServiceMap::ROOT, $map->serviceOf('src/Bundle/CoreBundle/Kernel.php'));
    }

    public function testEachServiceHasItsOwnClasses(): void
    {
        $result = $this->buildProject(self::TWO_SERVICES);
        $graph = $result->graph;

        self::assertSame([], $result->duplicates, 'PlaceOrder in two services is two classes, not a duplicate');
        self::assertSame(NodeKind::PhpClass, $graph->node('billing@App\PlaceOrder')?->kind);
        self::assertSame('shipping', $graph->node('shipping@App\PlaceOrder')?->service);
        self::assertTrue($this->hasEdge($graph, 'shipping@App\PlaceOrderHandler::__invoke', 'root@App\Clock', Relation::Instantiates), 'A name not in the service resolves in the shared root code');
        self::assertSame(['billing', 'shipping', ServiceMap::ROOT], $result->services);
    }

    public function testServicesMeetThroughTheMessageContract(): void
    {
        $result = $this->buildProject(self::TWO_SERVICES);
        $graph = $result->graph;

        self::assertTrue($this->hasEdge($graph, 'billing@App\Checkout::run', 'billing@App\PlaceOrder', Relation::Dispatches, Confidence::Inferred));
        self::assertTrue($this->hasEdge($graph, 'billing@App\PlaceOrder', 'shipping@App\PlaceOrder', Relation::Contract, Confidence::Inferred));
        self::assertTrue($this->hasEdge($graph, 'shipping@App\PlaceOrder', 'shipping@App\PlaceOrderHandler::__invoke', Relation::HandledBy, Confidence::Extracted));
        self::assertSame(1, $result->bus->contracts);
        self::assertSame(0, $result->bus->messagesWithoutHandlerCount, 'Handled in the other service');
        self::assertSame(0, $result->bus->messagesNeverSentCount, 'Sent by the other service');

        $path = (new \PhpGraph\Presentation\TextPresenter(new GraphQuery($graph)))->path('billing@Checkout::run', 'shipping@PlaceOrderHandler::__invoke');
        self::assertStringContainsString('--contract-->', $path);
    }

    public function testALaravelJobConsumedByAnotherServiceIsAContract(): void
    {
        $graph = $this->buildProject([
            'admin/composer.json' => '{"autoload": {"psr-4": {"App\\\\\\\\": "app/"}}}',
            'admin/app/ProductCreatedJob.php' => 'namespace App; class ProductCreatedJob implements \Illuminate\Contracts\Queue\ShouldQueue { use \Illuminate\Foundation\Bus\Dispatchable; public function handle(): void {} }',
            'admin/app/ProductController.php' => 'namespace App; class ProductController { public function store(): void { ProductCreatedJob::dispatch([])->onQueue("main_queue"); } }',
            'main/composer.json' => '{"autoload": {"psr-4": {"App\\\\\\\\": "app/"}}}',
            'main/app/ProductCreatedJob.php' => 'namespace App; class ProductCreatedJob implements \Illuminate\Contracts\Queue\ShouldQueue { public function handle(): void {} }',
            'shared/Dispatchable.php' => 'namespace Illuminate\Foundation\Bus; trait Dispatchable {}',
        ])->graph;

        self::assertTrue($this->hasEdge($graph, 'admin@App\ProductController::store', 'admin@App\ProductCreatedJob', Relation::Dispatches, Confidence::Inferred));
        self::assertTrue($this->hasEdge($graph, 'main@App\ProductCreatedJob', 'main@App\ProductCreatedJob::handle', Relation::HandledBy, Confidence::Inferred), 'A queued job handles itself, though this service never sends it');
        self::assertTrue($this->hasEdge($graph, 'admin@App\ProductCreatedJob', 'main@App\ProductCreatedJob', Relation::Contract, Confidence::Inferred));
    }

    public function testRoutingKeysNameChannelsSharedByAllServices(): void
    {
        $graph = $this->buildProject([
            'customer/composer.json' => '{"autoload": {"psr-4": {"App\\\\\\\\": "src/"}}}',
            'customer/src/IssueSubscriber.php' => 'namespace App; class IssueSubscriber { public function onIssue(\Ecotone\Modelling\DistributedBus $bus): void { $bus->convertAndSendCommand("backoffice", "ticket.prepare", ["id" => 1]); } }',
            'backoffice/composer.json' => '{"autoload": {"psr-4": {"App\\\\\\\\": "src/"}}}',
            'backoffice/src/Ticket.php' => 'namespace App; use Ecotone\Modelling\Attribute\CommandHandler; class Ticket { const PREPARE = "ticket.prepare"; #[CommandHandler(self::PREPARE)] public static function prepare(array $data): self { return new self(); } }',
        ])->graph;

        self::assertSame(NodeKind::Channel, $graph->node('channel:ticket.prepare')?->kind);
        self::assertTrue($this->hasEdge($graph, 'customer@App\IssueSubscriber::onIssue', 'channel:ticket.prepare', Relation::Dispatches, Confidence::Inferred));
        self::assertTrue($this->hasEdge($graph, 'channel:ticket.prepare', 'backoffice@App\Ticket::prepare', Relation::HandledBy, Confidence::Extracted), 'Routing key read from a class constant');
    }

    public function testQueriesNameAService(): void
    {
        $query = new GraphQuery($this->buildProject(self::TWO_SERVICES)->graph);

        self::assertSame('shipping@App\PlaceOrder', $query->candidates('shipping@PlaceOrder')[0]->id ?? null);
        self::assertSame('billing@App\PlaceOrder', $query->candidates('billing@App\PlaceOrder')[0]->id ?? null);
        self::assertCount(2, array_filter($query->candidates('PlaceOrder'), static fn ($node): bool => $node->label === 'PlaceOrder'));
    }

    public function testPhpgraphYamlNamesTheServices(): void
    {
        $this->write(self::TWO_SERVICES + ['phpgraph.yaml' => "services:\n  - billing\n"]);
        $result = (new ProjectGraph($this->root, $this->root . '/phpgraph-out'))->build();

        self::assertSame(['billing', ServiceMap::ROOT], $result->services, 'shipping is root code now');
        self::assertNotNull($result->graph->node('root@App\PlaceOrderHandler'));
    }

    public function testPhpgraphYamlIsValidated(): void
    {
        $this->write(['phpgraph.yaml' => "servics:\n  - billing\n"]);

        $this->expectExceptionMessage('unknown setting "servics"');
        ProjectConfig::load($this->root);
    }

    public function testTheGraphFileKeepsTheServices(): void
    {
        $path = $this->root . '/graph.json';
        (new JsonGraphStorage())->save($this->buildProject(self::TWO_SERVICES)->graph, $path);

        self::assertSame('billing', (new JsonGraphStorage())->load($path)->node('billing@App\Checkout')?->service);
        self::assertSame(2, Dig::at(json_decode((string) file_get_contents($path), true), 'version'));
    }
}
