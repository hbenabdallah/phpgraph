<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Command\CheckCommand;
use PhpGraph\Project\ProjectConfig;
use PhpGraph\Project\Rules\Pattern;
use PhpGraph\Query\GraphQuery;
use PhpGraph\Query\Result\RuleViolation;
use PhpGraph\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The project's own rules, `rules` in phpgraph.yaml, checked by `phpgraph check`: who may depend on what, what the
 * classes a pattern selects must be, what a message handler may dispatch.
 */
final class ProjectRulesTest extends TestCase
{
    use TemporaryProject;

    private const SOURCES = [
        'src/Sales/Domain/Order.php' => 'namespace Shop\Sales\Domain; #[\Doctrine\ORM\Mapping\Entity] class Order {}',
        'src/Sales/Domain/OrderPlaced.php' => 'namespace Shop\Sales\Domain; final class OrderPlaced {}',
        'src/Sales/Infrastructure/DoctrineOrders.php' => 'namespace Shop\Sales\Infrastructure; final class DoctrineOrders { public function __construct(private \Doctrine\ORM\EntityManagerInterface $em) {} }',
        'src/Sales/UI/OrderController.php' => 'namespace Shop\Sales\UI; class OrderController { public function __invoke(): void {} public function helper(): void {} protected function inner(): void {} }',
        'src/Sales/UI/CartController.php' => 'namespace Shop\Sales\UI; final class CartController implements \Shop\Shared\Http\Controller { public function __invoke(): void {} }',
        'src/Sales/UI/Basket.php' => 'namespace Shop\Sales\UI; final class Basket implements \Shop\Shared\Http\Controller { public function __invoke(): void {} }',
        'src/Shared/Http/Controller.php' => 'namespace Shop\Shared\Http; interface Controller {}',
        'src/Shared/Domain/AuditLogged.php' => 'namespace Shop\Shared\Domain; final class AuditLogged {}',
        'src/Shipping/Domain/ShipmentRequested.php' => 'namespace Shop\Shipping\Domain; final class ShipmentRequested {}',
        'src/Billing/Application/BillOrder.php' => 'namespace Shop\Billing\Application; use Symfony\Component\Messenger\Attribute\AsMessageHandler; use Symfony\Component\Messenger\MessageBusInterface;'
            . ' #[AsMessageHandler] final class BillOrder { public function __construct(private MessageBusInterface $bus) {}'
            . ' public function __invoke(\Shop\Sales\Domain\OrderPlaced $event): void { $this->bus->dispatch(new \Shop\Shipping\Domain\ShipmentRequested()); $this->bus->dispatch(new \Shop\Shared\Domain\AuditLogged()); } }',
        'tests/Sales/OrderTest.php' => 'namespace Shop\Tests\Sales; class OrderTest { public function test(\Doctrine\ORM\EntityManagerInterface $em): void {} }',
    ];

    private const RULES = <<<'YAML'
        rules:
          dependencies:
            - name: doctrine-in-infrastructure
              dependency: 'Doctrine\'
              allow-from: ['Shop\*\Infrastructure\']
              reason: Only infrastructure talks to the database.
          structure:
            - name: controllers
              on: 'Shop\*\UI\*Controller'
              target: class
              must-be-final: true
              must-implement: 'Shop\Shared\Http\Controller'
              only-public-methods: [__construct, __invoke]
            - name: controller-names
              on: 'Shop\**\UI\**'
              not-on: 'Shop\Sales\UI\OrderController'
              must-be-named: '*Controller'
          handlers:
            own-context: true
            shared: [Shared]
            reason: A context reacts to its own messages.
        YAML;

    /**
     * @return list<string>
     */
    private function breaches(string $rules = self::RULES): array
    {
        $this->write(self::SOURCES + ['phpgraph.yaml' => $rules]);
        $query = new GraphQuery((new \PhpGraph\Builder\GraphBuilder())->build($this->root)->graph);

        return array_map(static fn (RuleViolation $violation): string => $violation->key(), $query->ruleViolations(ProjectConfig::load($this->root)->rules));
    }

    public function testEachRuleFindsTheClassesBreakingIt(): void
    {
        self::assertSame([
            'controller-names: Shop\Sales\UI\Basket is not named *Controller',
            'controllers: Shop\Sales\UI\OrderController does not implement Shop\Shared\Http\Controller',
            'controllers: Shop\Sales\UI\OrderController has the public method helper()',
            'controllers: Shop\Sales\UI\OrderController is not final',
            'doctrine-in-infrastructure: Shop\Sales\Domain\Order depends on Doctrine\ORM\Mapping\Entity',
            'handlers: Shop\Billing\Application\BillOrder dispatches Shop\Shipping\Domain\ShipmentRequested, a message of Shop\Shipping, not Shop\Billing',
        ], $this->breaches(), 'tests are not application code; a shared context is allowed; a protected method is not public');
    }

    public function testDenyFromWinsOverAllowFrom(): void
    {
        $breaches = $this->breaches(<<<'YAML'
            rules:
              dependencies:
                - dependency: 'Doctrine\ORM\**'
                  allow-from: ['Shop\**']
                  deny-from: ['Shop\Sales\Domain\']
            YAML);

        self::assertSame(['dependencies[0]: Shop\Sales\Domain\Order depends on Doctrine\ORM\Mapping\Entity'], $breaches);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidRules(): iterable
    {
        yield 'unknown kind of rule' => ["rules:\n  layers: []", 'rules: unknown setting "layers"'];
        yield 'unknown constraint' => ["rules:\n  structure:\n    - on: 'App\\'\n      must-be-pure: true", 'rules.structure[0]: unknown setting "must-be-pure"'];
        yield 'missing selector' => ["rules:\n  structure:\n    - must-be-final: true", 'rules.structure[0]: on is required'];
        yield 'wrong target' => ["rules:\n  structure:\n    - on: 'App\\'\n      target: function", 'rules.structure[0].target: "function"'];
        yield 'flag not a boolean' => ["rules:\n  structure:\n    - on: 'App\\'\n      must-be-final: yes please", 'must-be-final must be true or false'];
    }

    #[DataProvider('invalidRules')]
    public function testAnInvalidRuleSaysWhereItIsWrong(string $yaml, string $message): void
    {
        $this->write(['phpgraph.yaml' => $yaml]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);
        ProjectConfig::load($this->root);
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function patterns(): iterable
    {
        yield 'a namespace, at any depth' => ['Doctrine\\', 'Doctrine\ORM\Mapping\Entity', true];
        yield 'a namespace is no prefix of a name' => ['Doctrine\\', 'DoctrineExtensions\Query', false];
        yield 'one segment' => ['App\*\Controller', 'App\Sales\Controller', true];
        yield 'one segment only' => ['App\*\Controller', 'App\Sales\UI\Controller', false];
        yield 'any segments' => ['App\**\Controller', 'App\Sales\UI\Controller', true];
        yield 'case does not matter' => ['app\sales\*', 'App\Sales\Order', true];
    }

    #[DataProvider('patterns')]
    public function testPatternsMatchNamespacesAndGlobs(string $pattern, string $name, bool $matches): void
    {
        self::assertSame($matches, Pattern::matches($pattern, $name));
    }

    public function testCheckFailsOnABreachUntilTheBaselineAcceptsIt(): void
    {
        $this->write(self::SOURCES + ['phpgraph.yaml' => self::RULES]);
        $tester = new CommandTester(new CheckCommand());

        self::assertSame(1, $tester->execute(['path' => $this->root]));
        self::assertStringContainsString("6 breaches of the project's rules:", $tester->getDisplay());
        self::assertStringContainsString('doctrine-in-infrastructure: Only infrastructure talks to the database.', $tester->getDisplay());

        self::assertSame(0, $tester->execute(['path' => $this->root, '--generate-baseline' => true]));
        self::assertSame(0, $tester->execute(['path' => $this->root]));
        self::assertStringContainsString("No new class breaks the project's rules.", $tester->getDisplay());

        $this->write(['src/Sales/Domain/Invoice.php' => 'namespace Shop\Sales\Domain; class Invoice { public function save(\Doctrine\ORM\EntityManagerInterface $em): void {} }']);
        self::assertSame(1, $tester->execute(['path' => $this->root]));
        self::assertStringContainsString('Shop\Sales\Domain\Invoice depends on Doctrine\ORM\EntityManagerInterface', $tester->getDisplay());
    }
}
