<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Builder\GraphBuilder;
use PhpGraph\Mcp\McpServer;
use PhpGraph\Presentation\TextPresenter;
use PhpGraph\Project\ProjectGraph;
use PhpGraph\Project\StackDetector;
use PhpGraph\Query\GraphQuery;
use PhpGraph\Query\GraphQueryProvider;
use PhpGraph\Tests\Support\Dig;
use PHPUnit\Framework\TestCase;

final class OverviewTest extends TestCase
{
    private const SOURCES = [
        'src/Sales/Domain/Order.php' => 'namespace Shop\Sales\Domain; class Order {}',
        'src/Sales/Domain/OrderRepository.php' => 'namespace Shop\Sales\Domain; interface OrderRepository {}',
        'src/Sales/Application/PlaceOrderHandler.php' => 'namespace Shop\Sales\Application; class PlaceOrderHandler {}',
        'src/Sales/Infrastructure/DbalOrderRepository.php' => 'namespace Shop\Sales\Infrastructure; class DbalOrderRepository {}',
        'src/Billing/Domain/Invoice.php' => 'namespace Shop\Billing\Domain; class Invoice {}',
        'src/Billing/UI/API/InvoiceController.php' => 'namespace Shop\Billing\UI\API; class InvoiceController {}',
        'tests/Sales/OrderTest.php' => 'namespace Shop\Tests\Sales; class OrderTest {}',
    ];

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/phpgraph-overview-' . bin2hex(random_bytes(4));
        foreach (self::SOURCES as $path => $code) {
            $this->write($path, "<?php\n" . $code . "\n");
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

    public function testDescribesTheNamespacesOfApplicationCodeWithTheirLayers(): void
    {
        $overview = (new GraphQuery((new GraphBuilder())->build($this->root)->graph))->overview();

        self::assertSame(6, $overview->applicationClasses);
        self::assertSame(1, $overview->testClasses);
        self::assertSame('Shop', $overview->root);
        self::assertSame(['Sales', 'Billing'], array_map(static fn ($group): string => $group->name, $overview->namespaces));
        self::assertSame(['Domain' => 2, 'Application' => 1, 'Infrastructure' => 1], $overview->namespaces[0]->layers);
        self::assertSame(['Domain' => 1, 'Ui' => 1], $overview->namespaces[1]->layers, 'Only the first layer segment counts, any case');
        self::assertSame(['Repository' => 2, 'Controller' => 1, 'Handler' => 1], $overview->suffixes);
    }

    public function testMergesNamespacesWithASingleBranch(): void
    {
        $this->write('lib/Vendorish/Core/Thing.php', "<?php\nnamespace Acme\\Lib\\Core; class Thing {}\n");
        $this->write('lib/Vendorish/Core/Other.php', "<?php\nnamespace Acme\\Lib\\Core\\Sub; class Other {}\n");

        $overview = (new GraphQuery((new GraphBuilder())->build($this->root)->graph))->overview();

        self::assertSame('', $overview->root, 'Shop and Acme share no root');
        self::assertContains('Acme\Lib\Core', array_map(static fn ($group): string => $group->name, $overview->namespaces));
    }

    public function testDetectsTheStackFromComposerFiles(): void
    {
        $this->write('composer.json', '{"require": {"php": "^8.2", "symfony/framework-bundle": "^7.0", "doctrine/orm": "^3.0", "acme/unknown": "^1.0"}}');
        $this->write('composer.lock', '{"packages": [{"name": "symfony/framework-bundle", "version": "v7.1.3"}, {"name": "doctrine/orm", "version": "3.2.0"}, {"name": "acme/unknown", "version": "1.0.0"}], "packages-dev": [{"name": "phpunit/phpunit", "version": "11.0.0"}]}');
        $this->write('packages/Billing/composer.json', '{"require": {"symfony/messenger": "^7.0"}}');
        $this->write('tests/Fixtures/app/composer.json', '{"require": {"laravel/framework": "^11.0"}}');
        $this->write('tests/Fixtures/app/composer.lock', '{"packages": []}');

        $stack = (new StackDetector())->detect($this->root, ['composer.json', 'packages/Billing/composer.json', 'tests/Fixtures/app/composer.json']);

        self::assertSame([[
            'directory' => '.',
            'php' => '^8.2',
            'locked' => true,
            'packages' => [
                'symfony/framework-bundle' => ['version' => 'v7.1.3', 'role' => 'framework'],
                'doctrine/orm' => ['version' => '3.2.0', 'role' => 'persistence'],
                'phpunit/phpunit' => ['version' => '11.0.0', 'role' => 'tests'],
            ],
        ]], $stack, 'A monorepo package without lock nor vendor/ and a test fixture are not applications');
    }

    public function testTheOverviewToolReportsStackStructureAndGaps(): void
    {
        $this->write('composer.json', '{"require": {"symfony/framework-bundle": "^7.0"}}');
        $this->write('src/Sales/Application/Checkout.php', "<?php\nnamespace Shop\\Sales\\Application; class Checkout { public function run(\$cart): void { \$cart->total(); } }\n");

        $server = new McpServer(GraphQueryProvider::forProject(new ProjectGraph($this->root, $this->root . '/phpgraph-out')));
        $response = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'overview']]);
        $text = Dig::at($response, 'result', 'content', 0, 'text') ?? '';

        self::assertIsString($text);
        self::assertStringContainsString('framework: symfony/framework-bundle ^7.0', $text);
        self::assertStringContainsString('(no composer.lock: constraints, not installed versions)', $text);
        self::assertStringContainsString('- Sales (5) [Application 2, Domain 2, Infrastructure 1]', $text);
        self::assertStringContainsString('receiver type unknown 100%', $text);
        self::assertStringContainsString('Most frequent unresolved calls: ->total() 1', $text);
        self::assertStringContainsString('No installed vendor/', $text);
    }

    public function testAGraphWithoutSummaryStillHasAnOverview(): void
    {
        $text = (new TextPresenter(new GraphQuery((new GraphBuilder())->build($this->root)->graph)))->overview();

        self::assertStringContainsString('built by an older phpgraph', $text);
        self::assertStringContainsString('Root namespace: Shop', $text);
    }

    private function write(string $path, string $content): void
    {
        @mkdir(\dirname($this->root . '/' . $path), 0777, true);
        file_put_contents($this->root . '/' . $path, $content);
    }
}
