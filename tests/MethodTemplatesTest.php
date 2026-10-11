<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Relation;
use PhpGraph\Tests\Support\TemporaryProject;
use PHPUnit\Framework\TestCase;

/**
 * The templates of a method are bound by the arguments of each call: the class an argument names (`class-string<T>`),
 * the type of a value (`T $value`), what a closure returns (`callable(): T`).
 */
final class MethodTemplatesTest extends TestCase
{
    use TemporaryProject;

    private const MANAGER = [
        'src/Repository.php' => 'namespace Lib; /** @template T of object */ class Repository { /** @return T|null */ public function find(int $id): ?object { return null; } }',
        'src/Manager.php' => 'namespace Lib; class Manager {'
            . ' /** @template T of object @param class-string<T> $class @return Repository<T> */ public function getRepository(string $class): Repository { return new Repository(); }'
            . "\n /** @template T of object\n * @param string \$class\n * @psalm-param class-string<T> \$class\n * @return object\n * @psalm-return T */ public function make(string \$class): object { return new \$class(); }"
            . "\n /** @template TReturn\n * @param (\\Closure(static): TReturn) \$callback\n * @return TReturn */ public function transaction(\\Closure \$callback): mixed { return \$callback(\$this); } }",
        'src/Order.php' => 'namespace App; class Order { public function total(): int { return 1; } public function ship(): void {} }',
    ];

    public function testTheClassAnArgumentNamesBindsTheTemplate(): void
    {
        $result = $this->buildProject(self::MANAGER + [
            'src/Orders.php' => 'namespace App; use Lib\Manager; class Orders { public function __construct(private Manager $manager) {}'
                . ' public function total(int $id): int { return $this->manager->getRepository(Order::class)->find($id)->total(); }'
                . ' public function ship(): void { $order = $this->manager->make(Order::class); $order->ship(); } }',
        ]);

        self::assertTrue($this->hasEdge($result->graph, 'App\Orders::total', 'App\Order::total', Relation::Calls, Confidence::Inferred));
        self::assertTrue($this->hasEdge($result->graph, 'App\Orders::ship', 'App\Order::ship', Relation::Calls, Confidence::Inferred), '@psalm-return over @return');
    }

    public function testWhatAClosureReturnsBindsTheTemplate(): void
    {
        $result = $this->buildProject(self::MANAGER + [
            'src/Checkout.php' => 'namespace App; use Lib\Manager; class Checkout { public function __construct(private Manager $manager) {}'
                . ' public function place(): int { $order = $this->manager->transaction(function () { $order = new Order(); return $order; }); return $order->total(); }'
                . ' public function ship(): void { $this->manager->transaction(fn (): Order => new Order())->ship(); }'
                . ' public function either(bool $paid): void { $order = $this->manager->transaction(function () use ($paid) { if ($paid) { return new Order(); } return $this->manager; }); $order->ship(); } }',
        ]);
        $graph = $result->graph;

        self::assertTrue($this->hasEdge($graph, 'App\Checkout::place', 'App\Order::total', Relation::Calls, Confidence::Inferred), 'its return statement');
        self::assertTrue($this->hasEdge($graph, 'App\Checkout::ship', 'App\Order::ship', Relation::Calls, Confidence::Inferred), 'its native return type');
        self::assertFalse($this->hasEdge($graph, 'App\Checkout::either', 'App\Order::ship', Relation::Calls, Confidence::Inferred), 'returns of different types: unknown');
    }

    public function testTheTypeOfAValueBindsTheTemplateOfAFunction(): void
    {
        $result = $this->buildProject([
            'src/helpers.php' => '/** @template TValue @param TValue $value @return TValue */ function tap_it($value) { return $value; }',
            'src/Order.php' => 'namespace App; class Order { public function ship(): void {} }',
            'src/Shipping.php' => 'namespace App; class Shipping { public function run(Order $order): void { tap_it($order)->ship(); } }',
        ]);

        self::assertTrue($this->hasEdge($result->graph, 'App\Shipping::run', 'App\Order::ship', Relation::Calls, Confidence::Inferred));
    }

    public function testAnUnboundTemplateKeepsTheDeclaredType(): void
    {
        $result = $this->buildProject(self::MANAGER + [
            'src/Orders.php' => 'namespace App; class Orders { public function first(\Lib\Manager $manager, string $class): void { $manager->getRepository($class)->find(1); } }',
        ]);

        self::assertTrue($this->hasEdge($result->graph, 'App\Orders::first', 'Lib\Repository::find', Relation::Calls, Confidence::Inferred));
    }

    public function testAVariablePassedToItselfDoesNotNestWithoutEnd(): void
    {
        $result = $this->buildProject([
            'src/Page.php' => 'namespace App; class Page { public function render(string $html): string {'
                . str_repeat(' $html = filter_it($html, $html);', 60) . ' return $html; } }',
        ]);

        self::assertSame(0, $result->calls->inferred);
    }

    public function testAClassNamedByAStringAndTheDocumentedClassWhenUnbound(): void
    {
        $result = $this->buildProject([
            'src/tables.php' => "/**\n * @return WP_List_Table|false\n * @phpstan-template T of WP_List_Table\n * @phpstan-param class-string<T> \$class\n * @phpstan-return T|false\n */ function get_table(\$class) { return new \$class(); }",
            'src/WP_List_Table.php' => 'class WP_List_Table { public function display(): void {} } class WP_Posts_List_Table extends WP_List_Table { public function prepare(): void {} }',
            'src/admin.php' => 'function show_posts(string $name): void { get_table("WP_Posts_List_Table")->prepare(); get_table($name)->display(); }',
        ]);

        self::assertTrue($this->hasEdge($result->graph, 'show_posts', 'WP_Posts_List_Table::prepare', Relation::Calls, Confidence::Inferred), 'the string names the class');
        self::assertTrue($this->hasEdge($result->graph, 'show_posts', 'WP_List_Table::display', Relation::Calls, Confidence::Inferred), '@return when T is unbound');
    }
}
