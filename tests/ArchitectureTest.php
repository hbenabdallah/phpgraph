<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Builder\GraphBuilder;
use PhpGraph\Command\CheckCommand;
use PhpGraph\Graph\Graph;
use PhpGraph\Mcp\McpServer;
use PhpGraph\Project\ProjectGraph;
use PhpGraph\Query\GraphQuery;
use PhpGraph\Query\GraphQueryProvider;
use PhpGraph\Query\Layers;
use PhpGraph\Query\Result\LayerViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Layers, bounded contexts, layer rules (phpgraph check) and impact analysis (impact_of).
 */
final class ArchitectureTest extends TestCase
{
    private const SOURCES = [
        'src/Sales/Domain/Order.php' => 'namespace Shop\Sales\Domain; class Order { public function total(): int { return 0; } }',
        'src/Sales/Domain/OrderRepository.php' => 'namespace Shop\Sales\Domain; interface OrderRepository { public function save(Order $order): void; }',
        'src/Sales/Domain/Pricing.php' => 'namespace Shop\Sales\Domain; class Pricing { public function price(): void { new \Shop\Sales\Infrastructure\TaxApi(); } }',
        'src/Sales/Application/PlaceOrder.php' => 'namespace Shop\Sales\Application; class PlaceOrder { public function __construct(private \Shop\Sales\Domain\OrderRepository $orders) {} public function __invoke(): void { $this->orders->save(new \Shop\Sales\Domain\Order()); } public function unrelated(): void {} }',
        'src/Sales/Infrastructure/TaxApi.php' => 'namespace Shop\Sales\Infrastructure; class TaxApi {}',
        'src/Sales/Infrastructure/DbalOrderRepository.php' => 'namespace Shop\Sales\Infrastructure; class DbalOrderRepository implements \Shop\Sales\Domain\OrderRepository { public function save(\Shop\Sales\Domain\Order $order): void {} }',
        'src/Sales/UI/OrderController.php' => 'namespace Shop\Sales\UI; class OrderController { public function __construct(private \Shop\Sales\Application\PlaceOrder $placeOrder) {} public function post(): void { ($this->placeOrder)(); $this->placeOrder->unrelated(); } }',
        'src/Billing/Domain/Invoice.php' => 'namespace Shop\Billing\Domain; class Invoice { public function for(\Shop\Sales\Domain\Order $order): void { $order->total(); } }',
        'src/Billing/Api/InvoiceApi.php' => 'namespace Shop\Billing\Api; class InvoiceApi {}',
        'src/Billing/Domain/UsesPublishedApi.php' => 'namespace Shop\Billing\Domain; class UsesPublishedApi { public function run(\Shop\Billing\Api\InvoiceApi $api): void {} }',
        'tests/Sales/PricingTest.php' => 'namespace Shop\Tests\Sales; class PricingTest { public function test(): void { new \Shop\Sales\Infrastructure\TaxApi(); (new \Shop\Sales\Domain\Order())->total(); } }',
    ];

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/phpgraph-architecture-' . bin2hex(random_bytes(4));
        foreach (self::SOURCES as $path => $code) {
            @mkdir(\dirname($this->root . '/' . $path), 0777, true);
            file_put_contents($this->root . '/' . $path, "<?php\n" . $code . "\n");
        }
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function layers(): iterable
    {
        yield 'explicit layer' => ['Shop\Sales\Domain\Model\Order', 'domain'];
        yield 'explicit layer wins over a generic one' => ['Shop\Sales\Application\Model\OrderView', 'application'];
        yield 'last generic segment, the most specific' => ['BookStack\Entities\Controllers\BookController', 'interface'];
        yield 'all capitals' => ['Shop\Billing\UI\API\InvoiceController', 'interface'];
        yield 'no layer' => ['Shop\Support\Clock', null];
    }

    #[DataProvider('layers')]
    public function testReadsTheLayerFromTheNamespace(string $class, ?string $role): void
    {
        self::assertSame($role, Layers::of(Layers::namespaceOf($class))['role'] ?? null);
    }

    public function testReadsTheBoundedContextOnEitherSideOfTheLayer(): void
    {
        self::assertSame('CodelyTv\Mooc\Courses', Layers::context('CodelyTv\Mooc\Courses\Domain\Course', false));
        self::assertSame('Product', Layers::context('PrestaShop\PrestaShop\Core\Domain\Product\Command\AddProduct', true));
        self::assertSame('Product', Layers::context('PrestaShop\PrestaShop\Adapter\Product\ProductRepository', true));
        self::assertNull(Layers::context('BookStack\Entities\Models\Book', false), 'Contexts come from explicit layers only');
        self::assertTrue(Layers::isLayerFirst(['PrestaShop\PrestaShop\Core\Domain\Product\A', 'PrestaShop\PrestaShop\Core\Domain\Cart\B']));
        self::assertFalse(Layers::isLayerFirst(['App\Sales\Domain\A', 'App\Billing\Domain\B', 'App\Shipping\Domain\C']));
    }

    public function testFindsTheDependenciesBreakingTheLayerRules(): void
    {
        $architecture = (new GraphQuery($this->graph()))->architecture();

        self::assertSame(
            ['Shop\Sales\Domain\Pricing -> Shop\Sales\Infrastructure\TaxApi'],
            array_map(static fn (LayerViolation $violation): string => $violation->key(), $architecture->violations),
            'Infrastructure and UI may depend on anything; tests and Api (too vague a name) are not checked',
        );
        self::assertSame(['Shop\Billing -> Shop\Sales' => 2], $architecture->contextDependencies);
    }

    public function testCheckFailsOnNewViolationsOnly(): void
    {
        $tester = new CommandTester(new CheckCommand());

        self::assertSame(1, $tester->execute(['path' => $this->root]));
        self::assertStringContainsString('domain -> infrastructure (1):', $tester->getDisplay());

        self::assertSame(0, $tester->execute(['path' => $this->root, '--generate-baseline' => true]));
        self::assertFileExists($this->root . '/' . CheckCommand::DEFAULT_BASELINE);
        self::assertSame(0, $tester->execute(['path' => $this->root]));
        self::assertStringContainsString('No new dependency', $tester->getDisplay());
    }

    public function testImpactFollowsTheMethodsThatUseTheChange(): void
    {
        $query = new GraphQuery($this->graph());
        $impact = $query->impactOf($query->resolve('Shop\Sales\Domain\OrderRepository::save') ?? self::fail('No node'));

        $reached = [];
        foreach ($impact->classes as $class) {
            $reached[$class->class] = $class->depth;
        }
        ksort($reached);

        self::assertSame([
            'Shop\Sales\Application\PlaceOrder' => 1,
            'Shop\Sales\Infrastructure\DbalOrderRepository' => 1,
            'Shop\Sales\UI\OrderController' => 2,
        ], $reached, 'OrderController calls PlaceOrder::__invoke, which uses the change; PlaceOrder::unrelated() leads nowhere');
    }

    public function testImpactListsTheTestsToRunApart(): void
    {
        $query = new GraphQuery($this->graph());
        $impact = $query->impactOf($query->resolve('Shop\Sales\Domain\Order') ?? self::fail('No node'), 1);

        $tests = array_values(array_map(static fn ($class): string => $class->class, array_filter($impact->classes, static fn ($class): bool => $class->isTest)));
        self::assertSame(['Shop\Tests\Sales\PricingTest'], $tests);
    }

    public function testTheImpactToolAnswersOverMcp(): void
    {
        $server = new McpServer(GraphQueryProvider::forProject(new ProjectGraph($this->root, $this->root . '/phpgraph-out')));
        $response = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'impact_of', 'arguments' => ['name' => 'TaxApi']]]);
        $text = $response['result']['content'][0]['text'] ?? '';

        self::assertIsString($text);
        self::assertStringContainsString('Impact of changing TaxApi', $text);
        self::assertMatchesRegularExpression('/\n  Pricing \([^)]*\) \[EXTRACTED\]/', $text);
        self::assertMatchesRegularExpression('/Tests to run[^\n]*\n  [^\n]*PricingTest/', $text);

        $full = $server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'impact_of', 'arguments' => ['name' => 'TaxApi', 'format' => 'full']]]);
        self::assertStringContainsString("Tests to run:\n  - PricingTest", (string) ($full['result']['content'][0]['text'] ?? ''), 'every relation spelled out');
    }

    private function graph(): Graph
    {
        return (new GraphBuilder())->build($this->root)->graph;
    }
}
