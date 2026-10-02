<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Builder\BuildResult;
use PhpGraph\Builder\GraphBuilder;
use PhpGraph\Extractor\PhpFileExtractor;
use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Relation;
use PHPUnit\Framework\TestCase;

/**
 * Call chains going through dependency classes, read from a minimal vendor/ directory.
 */
final class VendorSignaturesTest extends TestCase
{
    private const VENDOR = [
        'composer.json' => '{}',
        'vendor/composer/installed.json' => '{"packages": [{"name": "acme/orm", "install-path": "../acme/orm", "autoload": {"psr-4": {"Acme\\\\Orm\\\\": "src/"}}}]}',
        'vendor/composer/autoload_classmap.php' => "<?php\n\$vendorDir = dirname(__DIR__);\n\$baseDir = dirname(\$vendorDir);\nreturn array(\n    'Legacy_Mailer' => \$vendorDir . '/legacy/Mailer.php',\n    'App\\\\Ignored' => \$baseDir . '/src/Ignored.php',\n);\n",
        'vendor/acme/orm/src/Model.php' => "<?php\nnamespace Acme\\Orm;\nabstract class Model { public function fresh(): static { return \$this; } }\n",
        'vendor/acme/orm/src/Repository.php' => "<?php\nnamespace Acme\\Orm;\nclass Repository { public function query(): Query { return new Query(); } }\n",
        'vendor/acme/orm/src/Query.php' => "<?php\nnamespace Acme\\Orm;\nclass Query { /** @return \$this */ public function where() { return \$this; } public function rows(): array { return []; } }\n",
        'vendor/legacy/Mailer.php' => "<?php\nclass Legacy_Mailer { public function message(): Legacy_Message { return new Legacy_Message(); } }\nclass Legacy_Message { public function send(): void {} }\n",
    ];

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/phpgraph-vendor-' . bin2hex(random_bytes(4));
        mkdir($this->root);
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

    public function testChainReturnsToTheProjectThroughAVendorStaticReturnType(): void
    {
        $result = $this->build('namespace App; class Order extends \Acme\Orm\Model { public function total(): int { return 0; } } class Cart { public function sum(Order $o): int { return $o->fresh()->total(); } }');

        self::assertTrue($this->hasCall($result, 'App\Cart::sum', 'App\Order::total'));
    }

    public function testCallsInheritedFromAVendorClassAreOutsideTheProject(): void
    {
        $result = $this->build('namespace App; class Orders extends \Acme\Orm\Repository { public function paid(): array { return $this->query()->where()->rows(); } }');

        self::assertSame(['total' => 3, 'inferred' => 0, 'ambiguous' => 0, 'outsideProject' => 3, 'unknownReceiver' => 0, 'chainOutsideProject' => 0], $result->calls->toArray());
        self::assertSame(2, $result->vendorFilesRead);
        self::assertNull($result->graph->node('Acme\Orm\Repository::query'), 'Vendor methods never become nodes');
    }

    public function testClassmapEntriesAreFound(): void
    {
        $result = $this->build('namespace App; class Notifier { public function __construct(private \Legacy_Mailer $mailer) {} public function notify(): void { $this->mailer->message()->send(); } }');

        self::assertSame(2, $result->calls->outsideProject);
        self::assertSame(0, $result->calls->unknownReceiver);
    }

    public function testChainsStopAtVendorWhenSignaturesAreNotRead(): void
    {
        $result = $this->build('namespace App; class Orders extends \Acme\Orm\Repository { public function paid(): array { return $this->query()->where()->rows(); } }', readVendor: false);

        self::assertSame(['total' => 3, 'inferred' => 0, 'ambiguous' => 0, 'outsideProject' => 1, 'unknownReceiver' => 2, 'chainOutsideProject' => 2], $result->calls->toArray());
    }

    public function testAProjectInterfaceWinsOverAVendorParent(): void
    {
        $result = $this->build('namespace App; interface OrderQueries { public function query(): \Acme\Orm\Query; } class Orders extends \Acme\Orm\Repository implements OrderQueries {} class Report { public function run(Orders $o): void { $o->query(); } }');

        self::assertTrue($this->hasCall($result, 'App\Report::run', 'App\OrderQueries::query'));
    }

    public function testAProjectClassShadowsAVendorClassOfTheSameName(): void
    {
        $result = $this->build('namespace Acme\Orm; class Query { public function where(): self { return $this; } public function count(): int { return 0; } } class Report { public function run(Query $q): int { return $q->where()->count(); } }');

        self::assertTrue($this->hasCall($result, 'Acme\Orm\Report::run', 'Acme\Orm\Query::count'));
    }

    private function build(string $code, bool $readVendor = true): BuildResult
    {
        foreach (self::VENDOR + ['src/a.php' => "<?php\n" . $code . "\n"] as $path => $content) {
            @mkdir(\dirname($this->root . '/' . $path), 0777, true);
            file_put_contents($this->root . '/' . $path, $content);
        }

        return (new GraphBuilder(new PhpFileExtractor(), $readVendor))->build($this->root);
    }

    private function hasCall(BuildResult $result, string $source, string $target): bool
    {
        foreach ($result->graph->edges() as $edge) {
            if ($edge->source === $source && $edge->target === $target && $edge->relation === Relation::Calls && $edge->confidence === Confidence::Inferred) {
                return true;
            }
        }

        return false;
    }
}
