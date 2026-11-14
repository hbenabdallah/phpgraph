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

    public function testAServiceNamedByIdGivesItsOutermostDecoratorAsWell(): void
    {
        $graph = $this->buildProject([
            'src/Context.php' => 'namespace App; interface Context {}',
            'src/FixedContext.php' => 'namespace App; class FixedContext implements Context {}',
            'src/CompositeContext.php' => 'namespace App; class CompositeContext implements Context {}',
            'src/CachedContext.php' => 'namespace App; class CachedContext implements Context { public function __construct(Context $inner) {} }',
            'src/Consumer.php' => 'namespace App; class Consumer { public function __construct(Context $context) {} }',
            'src/Mailer.php' => 'namespace App; class Mailer {}',
            'src/TracedMailer.php' => 'namespace App; use Symfony\Component\DependencyInjection\Attribute\AsDecorator; use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;'
                . ' #[AsDecorator(decorates: "app.mailer")] class TracedMailer { public function __construct(#[AutowireDecorated] Mailer $inner) {} }',
            'src/Newsletter.php' => 'namespace App; class Newsletter {}',
            'src/Gateway.php' => 'namespace App; class Gateway {}',
            'src/RetryingGateway.php' => 'namespace App; class RetryingGateway {}',
            'src/Shop.php' => 'namespace App; class Shop {}',
            'src/Clock.php' => 'namespace App; class Clock {}',
            'src/Scheduler.php' => 'namespace App; class Scheduler {}',
            'tests/FrozenClock.php' => 'namespace App\Tests; use Symfony\Component\DependencyInjection\Attribute\AsDecorator; #[AsDecorator("app.clock")] class FrozenClock {}',
            'config/services.php' => 'use function Symfony\Component\DependencyInjection\Loader\Configurator\service; return static function ($container): void { $services = $container->services();'
                . ' $services->set("app.context", App\FixedContext::class);'
                . ' $services->set("app.context.composite", App\CompositeContext::class)->decorate("app.context", null, 256);'
                . ' $services->set("app.context.cached", App\CachedContext::class)->decorate("app.context")->args([service(".inner")]);'
                . ' $services->set(App\Consumer::class)->args([service("app.context")]);'
                . ' $services->set("app.mailer", App\Mailer::class); $services->set(App\Newsletter::class)->args([service("app.mailer")]);'
                . ' $services->set("app.clock", App\Clock::class); $services->set(App\Scheduler::class)->args([service("app.clock")]); };',
            'config/services.yaml' => "services:\n    app.gateway:\n        class: App\\Gateway\n"
                . "    app.gateway.retrying:\n        class: App\\RetryingGateway\n        decorates: app.gateway\n        decoration_priority: -5\n",
            'config/shop.xml' => '<?xml version="1.0" ?><container xmlns="http://symfony.com/schema/dic/services"><services>'
                . '<service id="App\Shop"><argument type="service" id="app.gateway"/></service></services></container>',
        ])->graph;

        self::assertTrue($this->hasEdge($graph, 'App\Consumer', 'App\CachedContext', Relation::Receives), 'the decorator of the lowest priority is the outermost: the one injected');
        self::assertTrue($this->hasEdge($graph, 'App\Consumer', 'App\FixedContext', Relation::Receives), 'the decorated class stays: a decorator may hold it without saying so');
        self::assertFalse($this->hasEdge($graph, 'App\Consumer', 'App\CompositeContext', Relation::Receives), 'an inner decorator is not injected');
        self::assertTrue($this->hasEdge($graph, 'App\CachedContext', 'App\CompositeContext', Relation::Receives), '.inner of the outermost decorator is the next one inside');
        self::assertTrue($this->hasEdge($graph, 'App\Newsletter', 'App\TracedMailer', Relation::Receives), '#[AsDecorator]');
        self::assertTrue($this->hasEdge($graph, 'App\TracedMailer', 'App\Mailer', Relation::Receives), '#[AutowireDecorated] is the decorated service');
        self::assertTrue($this->hasEdge($graph, 'App\Shop', 'App\RetryingGateway', Relation::Receives), 'YAML decorates: and an XML consumer');
        self::assertTrue($this->hasEdge($graph, 'App\Scheduler', 'App\Clock', Relation::Receives));
        self::assertFalse($this->hasEdge($graph, 'App\Scheduler', 'App\Tests\FrozenClock', Relation::Receives), 'a decorator in test code decorates in the test environment only');
    }

    public function testADecoratorDecoratedInTurnIsWrappedByItsOwnDecorator(): void
    {
        $graph = $this->buildProject([
            'src/Base.php' => 'namespace App; class Base {}',
            'src/First.php' => 'namespace App; class First {}',
            'src/Second.php' => 'namespace App; class Second {}',
            'src/Consumer.php' => 'namespace App; class Consumer {}',
            'config/services.php' => 'use function Symfony\Component\DependencyInjection\Loader\Configurator\service; return static function ($container): void { $services = $container->services();'
                . ' $services->set("app.base", App\Base::class);'
                . ' $services->set("app.first", App\First::class)->decorate("app.base")->args([service(".inner")]);'
                . ' $services->set("app.second", App\Second::class)->decorate("app.first")->args([service(".inner")]);'
                . ' $services->set(App\Consumer::class)->args([service("app.base")]); };',
        ])->graph;

        self::assertTrue($this->hasEdge($graph, 'App\Consumer', 'App\Second', Relation::Receives), 'the decorator of the decorator is what the container injects');
        self::assertFalse($this->hasEdge($graph, 'App\Consumer', 'App\First', Relation::Receives), 'the first decorator is inside the second');
        self::assertTrue($this->hasEdge($graph, 'App\Second', 'App\First', Relation::Receives), '.inner of the second decorator');
        self::assertTrue($this->hasEdge($graph, 'App\First', 'App\Base', Relation::Receives), '.inner of the first decorator');
    }

    public function testApplicationCodeIsGivenTheProductionDecoratorInsideATestOne(): void
    {
        $graph = $this->buildProject([
            'src/Clock.php' => 'namespace App; class Clock {}',
            'src/CachedClock.php' => 'namespace App; class CachedClock {}',
            'src/Scheduler.php' => 'namespace App; class Scheduler {}',
            'tests/FrozenClock.php' => 'namespace App\Tests; class FrozenClock {}',
            'config/services.php' => 'use function Symfony\Component\DependencyInjection\Loader\Configurator\service; return static function ($container): void { $services = $container->services();'
                . ' $services->set("app.clock", App\Clock::class);'
                . ' $services->set("app.clock.cached", App\CachedClock::class)->decorate("app.clock", null, 10);'
                . ' $services->set("app.clock.frozen", App\Tests\FrozenClock::class)->decorate("app.clock");'
                . ' $services->set(App\Scheduler::class)->args([service("app.clock")]); };',
        ])->graph;

        self::assertTrue($this->hasEdge($graph, 'App\Scheduler', 'App\CachedClock', Relation::Receives), 'outside the test environment, the production decorator is the outermost');
        self::assertFalse($this->hasEdge($graph, 'App\Scheduler', 'App\Tests\FrozenClock', Relation::Receives));
    }

    public function testAutowireDecoratedReadsTheDecorationTheConfigurationDeclares(): void
    {
        $graph = $this->buildProject([
            'src/Mailer.php' => 'namespace App; class Mailer {}',
            'src/TracedMailer.php' => 'namespace App; use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;'
                . ' class TracedMailer { public function __construct(#[AutowireDecorated] Mailer $inner) {} }',
            'config/services.yaml' => "services:\n    app.mailer:\n        class: App\\Mailer\n"
                . "    App\\TracedMailer:\n        decorates: app.mailer\n",
        ])->graph;

        self::assertTrue($this->hasEdge($graph, 'App\TracedMailer', 'App\Mailer', Relation::Receives), 'the decoration is in YAML, the attribute in the class');
    }

    public function testAutowiresReadsTheInheritedConstructorAndLeavesSettersOut(): void
    {
        $graph = $this->buildProject([
            'src/Processor.php' => 'namespace App; interface Processor {}',
            'src/PaymentProcessor.php' => 'namespace App; class PaymentProcessor implements Processor {}',
            'src/BaseListener.php' => 'namespace App; abstract class BaseListener { public function __construct(Processor $audit, Processor $processor) {} }',
            'src/Listener.php' => 'namespace App; class Listener extends BaseListener {}',
            'src/Worker.php' => 'namespace App; class Worker { public function __construct(Processor $audit, Processor $processor) {} public function setFallback(Processor $fallback): void {} }',
            'src/Notifier.php' => 'namespace App; class Notifier { public function __construct(Processor $processor) {} public function setFallback(Processor $fallback): void {} }',
            'src/Batch.php' => 'namespace App; class Batch { public function __construct(Processor $audit, Processor $processor) {} public function setAll(array $processors): void {} }',
            'config/batch.xml' => '<?xml version="1.0" ?><container xmlns="http://symfony.com/schema/dic/services"><services>'
                . '<service id="App\Batch"><argument type="service" id="app.payment"/><call method="setAll"><argument type="collection"><argument type="service" id="app.payment"/></argument></call></service></services></container>',
            'config/services.php' => 'use function Symfony\Component\DependencyInjection\Loader\Configurator\service; return static function ($container): void { $services = $container->services();'
                . ' $services->set("app.payment", App\PaymentProcessor::class);'
                . ' $services->set(App\Listener::class)->arg("$audit", service("app.payment"));'
                . ' $services->set(App\Worker::class)->arg("$audit", service("app.payment"))->call("setFallback", [service("app.payment")]);'
                . ' $services->set(App\\Notifier::class)->call("setFallback", [service("app.payment")]); };',
        ])->graph;

        self::assertTrue($this->hasEdge($graph, 'App\Listener', 'App\Processor', Relation::Autowires), 'the constructor it inherits takes two processors, one is configured');
        self::assertTrue($this->hasEdge($graph, 'App\Worker', 'App\Processor', Relation::Autowires), 'the service given to a setter fills no constructor parameter');
        self::assertTrue($this->hasEdge($graph, 'App\Notifier', 'App\Processor', Relation::Autowires), 'only its setter is configured: the constructor one is autowired');
        self::assertTrue($this->hasEdge($graph, 'App\Batch', 'App\Processor', Relation::Autowires), 'a service in a collection given to a setter fills no constructor parameter either');
    }

    public function testAutowireDecoratedReadsADecoratorRegisteredUnderAnId(): void
    {
        $graph = $this->buildProject([
            'src/Mailer.php' => 'namespace App; class Mailer {}',
            'src/TracedMailer.php' => 'namespace App; use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;'
                . ' class TracedMailer { public function __construct(#[AutowireDecorated] Mailer $inner) {} }',
            'config/services.yaml' => "services:\n    app.mailer:\n        class: App\\Mailer\n"
                . "    app.traced:\n        class: App\\TracedMailer\n        decorates: app.mailer\n",
        ])->graph;

        self::assertTrue($this->hasEdge($graph, 'App\TracedMailer', 'App\Mailer', Relation::Receives), 'the attribute names the class, the configuration its id');
    }

    public function testAnAliasToAClassTheConfigurationDoesNotDefineGivesThatClass(): void
    {
        $graph = $this->buildProject([
            'src/Provider.php' => 'namespace App; class Provider {}',
            'src/Form.php' => 'namespace App; class Form {}',
            'config/services.yaml' => "services:\n    app.provider:\n        alias: 'App\\Provider'\n"
                . "    App\\Form:\n        arguments: ['@app.provider']\n",
        ])->graph;

        self::assertTrue($this->hasEdge($graph, 'App\\Form', 'App\\Provider', Relation::Receives), 'the class is autowired from a resource: its id is its name');
    }
}
