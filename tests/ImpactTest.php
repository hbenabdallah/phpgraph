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
        'src/Validation/Validator.php' => 'namespace App\Validation; final class Validator { /** @param iterable<Rule> $rules */ public function __construct(private iterable $rules, private Rule $rule) {}'
            . ' public function validate(Notification $n): void { $this->rule->apply($n); } }',
        'src/Validation/PromiseCheck.php' => 'namespace App\Validation; final class PromiseCheck { public function check(Notification $n): bool { return count($n->all()) > 0; } }',
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
