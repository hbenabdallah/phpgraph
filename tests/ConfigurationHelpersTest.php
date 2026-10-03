<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Relation;
use PhpGraph\Tests\Support\TemporaryProject;
use PHPUnit\Framework\TestCase;

/**
 * Service configuration written in helpers called with literal arguments: the ids, tags and namespaces they compute
 * are evaluated, never run.
 */
final class ConfigurationHelpersTest extends TestCase
{
    use TemporaryProject;

    private const WIRING = 'namespace App\Shared; use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;'
        . ' use function Symfony\Component\DependencyInjection\Loader\Configurator\service; use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;'
        . ' final class Wiring { public const SUFFIX = "validation_pipeline";'
        . ' public static function wire(ServicesConfigurator $services, string $prefix, string $dir, string $namespace): void {'
        . ' self::loadRules($services, $dir, $namespace, "/Rules/", $prefix . ".rule");'
        . ' $services->set($prefix . ".validator", Validator::class)->args([tagged_iterator($prefix . ".rule")]);'
        . ' $services->set(sprintf("%s.%s", $prefix, self::SUFFIX), Pipeline::class)->args([service($prefix . ".validator")]); }'
        . ' private static function loadRules(ServicesConfigurator $services, string $dir, string $namespace, string $subPath, string $tag): void {'
        . ' if (!is_dir($dir . $subPath)) { return; }'
        . ' $services->load($namespace . str_replace("/", "\\\\", $subPath), $dir . $subPath)->tag($tag); } }';

    public function testAHelperCalledWithLiteralArgumentsDeclaresItsServices(): void
    {
        $result = $this->buildProject([
            'src/Shared/Wiring.php' => self::WIRING,
            'src/Shared/Validator.php' => 'namespace App\Shared; class Validator { public function __construct(iterable $rules) {} }',
            'src/Shared/Pipeline.php' => 'namespace App\Shared; class Pipeline { public function __construct(Validator $validator) {} }',
            'src/Sales/Rules/StockRule.php' => 'namespace App\Sales\Rules; class StockRule {}',
            'src/Sales/Rules/NormRule.php' => 'namespace App\Sales\Rules; class NormRule {}',
            'src/Billing/Rules/VatRule.php' => 'namespace App\Billing\Rules; class VatRule {}',
            'src/Sales/CreateOrder.php' => 'namespace App\Sales; class CreateOrder { public function __construct(\App\Shared\Pipeline $pipeline) {} }',
            'config/services.php' => 'use App\Shared\Wiring; use function Symfony\Component\DependencyInjection\Loader\Configurator\service;'
                . ' return static function ($container): void { $services = $container->services();'
                . ' Wiring::wire($services, "sales", __DIR__ . "/../src/Sales", "App\\\\Sales");'
                . ' $services->set(App\Sales\CreateOrder::class)->arg("$pipeline", service("sales.validation_pipeline")); };',
        ]);
        $graph = $result->graph;

        self::assertTrue($this->hasEdge($graph, 'App\Shared\Validator', 'App\Sales\Rules\StockRule', Relation::Receives, Confidence::Extracted), 'tags computed by a nested helper');
        self::assertTrue($this->hasEdge($graph, 'App\Shared\Validator', 'App\Sales\Rules\NormRule', Relation::Receives));
        self::assertFalse($this->hasEdge($graph, 'App\Shared\Validator', 'App\Billing\Rules\VatRule', Relation::Receives), 'another namespace');
        self::assertTrue($this->hasEdge($graph, 'App\Shared\Pipeline', 'App\Shared\Validator', Relation::Receives), 'service() of a computed id');
        self::assertTrue($this->hasEdge($graph, 'App\Sales\CreateOrder', 'App\Shared\Pipeline', Relation::Receives), 'an id computed with sprintf and a constant');
        self::assertSame(0, $result->injections->unlinkedCount);
    }

    public function testArgumentsThatCannotBeEvaluatedDeclareNothing(): void
    {
        $result = $this->buildProject([
            'src/Shared/Wiring.php' => self::WIRING,
            'src/Shared/Validator.php' => 'namespace App\Shared; class Validator {}',
            'src/Shared/Pipeline.php' => 'namespace App\Shared; class Pipeline {}',
            'src/Sales/Rules/StockRule.php' => 'namespace App\Sales\Rules; class StockRule {}',
            'config/services.php' => 'use App\Shared\Wiring; return static function ($container): void { $services = $container->services();'
                . ' Wiring::wire($services, getenv("PREFIX"), __DIR__, "App\\\\Sales"); };',
        ]);

        self::assertFalse($this->hasEdge($result->graph, 'App\Shared\Validator', 'App\Sales\Rules\StockRule', Relation::Receives), 'the prefix is unknown');
    }
}
