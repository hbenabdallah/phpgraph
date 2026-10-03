<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Relation;
use PhpGraph\Tests\Support\TemporaryProject;
use PHPUnit\Framework\TestCase;

/**
 * What the container configuration injects by tag or by id: `receives`, from PHP, YAML and XML configuration and from
 * attributes alike.
 */
final class ServiceInjectionsTest extends TestCase
{
    use TemporaryProject;

    private const RULES = [
        'src/Rule/RuleInterface.php' => 'namespace App\Rule; interface RuleInterface {}',
        'src/Rule/StockRule.php' => 'namespace App\Rule; class StockRule implements RuleInterface {}',
        'src/Rule/NormRule.php' => 'namespace App\Rule; class NormRule implements RuleInterface {}',
        'src/Validator.php' => 'namespace App; class Validator { public function __construct(iterable $rules) {} }',
    ];

    public function testATaggedIteratorInPhpConfigurationLinksTheConsumerToEveryTaggedClass(): void
    {
        $result = $this->buildProject(self::RULES + [
            'src/Repository/Repository.php' => 'namespace App\Repository; interface Repository {}',
            'src/Repository/DbRepository.php' => 'namespace App\Repository; class DbRepository implements Repository {}',
            'src/Repository/CachedRepository.php' => 'namespace App\Repository; class CachedRepository implements Repository { public function __construct(Repository $inner) {} }',
            'src/Mailer.php' => 'namespace App; class Mailer {}',
            'src/Notifier.php' => 'namespace App; class Notifier {}',
            'config/services.php' => 'use App\Rule\RuleInterface; use App\Validator; use App\Repository\Repository; use App\Repository\DbRepository; use App\Repository\CachedRepository;'
                . ' use function Symfony\Component\DependencyInjection\Loader\Configurator\service; use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;'
                . ' return static function ($container): void { $services = $container->services();'
                . ' $services->instanceof(RuleInterface::class)->tag("app.rule");'
                . ' $services->set(Validator::class)->args([tagged_iterator("app.rule")]);'
                . ' $services->set("app.mailer", App\Mailer::class);'
                . ' $services->set(App\Notifier::class)->arg("$mailer", service("app.mailer"))->arg("$computed", service("app." . "x"));'
                . ' $services->set(Repository::class, DbRepository::class);'
                . ' $services->set(CachedRepository::class)->decorate(Repository::class)->args([service(".inner")]);'
                . ' $services->set(App\Notifier::class)->arg("$generated", service("app.generated_by_a_bundle")); };',
        ]);
        $graph = $result->graph;

        self::assertTrue($this->hasEdge($graph, 'App\Validator', 'App\Rule\StockRule', Relation::Receives, Confidence::Extracted));
        self::assertTrue($this->hasEdge($graph, 'App\Validator', 'App\Rule\NormRule', Relation::Receives, Confidence::Extracted));
        self::assertTrue($this->hasEdge($graph, 'App\Notifier', 'App\Mailer', Relation::Receives), 'service() by id');
        self::assertTrue($this->hasEdge($graph, 'App\Repository\CachedRepository', 'App\Repository\DbRepository', Relation::Receives), 'a decorator receives the decorated service');
        self::assertSame(3, $result->injections->linked);
        self::assertSame(1, $result->injections->unlinkedCount, 'the id of a bundle service is reported, the computed one is not read');
        self::assertStringContainsString('service app.generated_by_a_bundle into App\Notifier', $result->injections->unlinked[0]);
    }

    public function testYamlXmlAndAttributesDeclareTheSameLinks(): void
    {
        $graph = $this->buildProject(self::RULES + [
            'src/Report/ReportBuilder.php' => 'namespace App\Report; use Symfony\Component\DependencyInjection\Attribute\AutowireIterator; class ReportBuilder { public function __construct(#[AutowireIterator("app.section")] iterable $sections) {} }',
            'src/Report/Section.php' => 'namespace App\Report; use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag; #[AutoconfigureTag("app.section")] interface Section {}',
            'src/Report/Summary.php' => 'namespace App\Report; class Summary implements Section {}',
            'src/Export/Exporter.php' => 'namespace App\Export; class Exporter {}',
            'src/Export/Format/Csv.php' => 'namespace App\Export\Format; class Csv {}',
            'src/Export/Format/Json.php' => 'namespace App\Export\Format; class Json {}',
            'src/Legacy/Gateway.php' => 'namespace App\Legacy; class Gateway {}',
            'src/Legacy/Client.php' => 'namespace App\Legacy; class Client {}',
            'config/services.yaml' => "services:\n    _instanceof:\n        App\\Rule\\RuleInterface:\n            tags: [app.rule]\n"
                . "    App\\Validator:\n        arguments: [!tagged_iterator app.rule]\n"
                . "    App\\Export\\Format\\:\n        resource: '../src/Export/Format/'\n        tags: [app.format]\n"
                . "    App\\Export\\Exporter:\n        arguments:\n            \$formats: !tagged_iterator { tag: app.format }\n",
            'config/legacy.xml' => '<?xml version="1.0" ?><container xmlns="http://symfony.com/schema/dic/services"><services>'
                . '<service id="app.client" class="App\Legacy\Client"/>'
                . '<service id="App\Legacy\Gateway"><argument type="service" id="app.client"/></service></services></container>',
        ])->graph;

        self::assertTrue($this->hasEdge($graph, 'App\Validator', 'App\Rule\StockRule', Relation::Receives), 'YAML _instanceof and !tagged_iterator');
        self::assertTrue($this->hasEdge($graph, 'App\Export\Exporter', 'App\Export\Format\Csv', Relation::Receives), 'a tagged namespace resource');
        self::assertTrue($this->hasEdge($graph, 'App\Export\Exporter', 'App\Export\Format\Json', Relation::Receives));
        self::assertTrue($this->hasEdge($graph, 'App\Legacy\Gateway', 'App\Legacy\Client', Relation::Receives), 'XML');
        self::assertTrue($this->hasEdge($graph, 'App\Report\ReportBuilder', 'App\Report\Summary', Relation::Receives), '#[AutowireIterator] and #[AutoconfigureTag]');
    }
}
