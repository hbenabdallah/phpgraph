<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Builder\BuildResult;
use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Relation;
use PhpGraph\Tests\Support\TemporaryProject;
use PHPUnit\Framework\TestCase;

/**
 * Generic types read in docblocks: templates bound by the receiver's arguments, through the parents passing them on,
 * and the elements of collections walked by foreach.
 */
final class GenericsTest extends TestCase
{
    use TemporaryProject;

    /**
     * A collection as Doctrine writes it, in vendor/: its elements reached through `@template-extends`.
     */
    private const VENDOR = [
        'composer.json' => '{}',
        'vendor/composer/installed.json' => '{"packages": [{"name": "acme/collections", "install-path": "../acme/collections", "autoload": {"psr-4": {"Acme\\\\Collections\\\\": "src/"}}}]}',
        'vendor/acme/collections/src/ReadableCollection.php' => "<?php\nnamespace Acme\\Collections;\nuse IteratorAggregate;\n/**\n * @phpstan-template TKey of array-key\n * @psalm-template TKey of array-key\n * @template-covariant T\n * @template-extends IteratorAggregate<TKey, T>\n */\ninterface ReadableCollection extends \\Countable, IteratorAggregate\n{\n    /** @return T|false */\n    public function first();\n}\n",
        'vendor/acme/collections/src/Collection.php' => "<?php\nnamespace Acme\\Collections;\n/**\n * @template TKey of array-key\n * @template T\n * @template-extends ReadableCollection<TKey, T>\n */\ninterface Collection extends ReadableCollection\n{\n}\n",
    ];

    /**
     * Symfony's configuration builders: `end()` returns the parent the chain started from.
     */
    private const BUILDERS = [
        'src/Config/NodeParent.php' => 'namespace App\Config; interface NodeParent {}',
        'src/Config/NodeDefinition.php' => 'namespace App\Config; /** @template TParent of NodeParent|null */ abstract class NodeDefinition { /** @return TParent */ public function end(): ?NodeParent { return null; } /** @return $this */ public function info(string $info): static { return $this; } }',
        'src/Config/ScalarNodeDefinition.php' => 'namespace App\Config; /** @template TParent of NodeParent|null @extends NodeDefinition<TParent> */ class ScalarNodeDefinition extends NodeDefinition { public function defaultValue(mixed $value): static { return $this; } }',
        'src/Config/ArrayNodeDefinition.php' => 'namespace App\Config; /** @template TParent of NodeParent|null @extends NodeDefinition<TParent> */ class ArrayNodeDefinition extends NodeDefinition implements NodeParent { /** @return NodeBuilder<static> */ public function children(): NodeBuilder { return new NodeBuilder(); } }',
        'src/Config/NodeBuilder.php' => 'namespace App\Config; /** @template TParent of NodeDefinition|null */ class NodeBuilder implements NodeParent { /** @return ScalarNodeDefinition<$this> */ public function scalarNode(string $name): ScalarNodeDefinition { return new ScalarNodeDefinition(); } /** @return ArrayNodeDefinition<$this> */ public function arrayNode(string $name): ArrayNodeDefinition { return new ArrayNodeDefinition(); } /** @return TParent */ public function end(): ?NodeDefinition { return null; } }',
    ];

    public function testTemplatesAreBoundByTheReceiverThroughTheParentsPassingThemOn(): void
    {
        $result = $this->buildProject(self::BUILDERS + [
            'src/Configuration.php' => 'namespace App; use App\Config\ArrayNodeDefinition; class Configuration { public function tree(ArrayNodeDefinition $root): void { $root->children()->scalarNode("a")->defaultValue(1)->end()->arrayNode("b")->children()->scalarNode("c")->info("x")->end()->end()->end()->scalarNode("d")->end(); } }',
        ]);

        self::assertTrue($this->hasEdge($result->graph, 'App\Configuration::tree', 'App\Config\NodeBuilder::arrayNode', Relation::Calls, Confidence::Inferred), 'end() of a scalar node returns its NodeBuilder');
        self::assertTrue($this->hasEdge($result->graph, 'App\Configuration::tree', 'App\Config\ArrayNodeDefinition::children', Relation::Calls, Confidence::Inferred));
        self::assertSame(0, $result->calls->unknownReceiver, 'every call of the chain, down to the last end()');
    }

    public function testForeachWalksTheElementsOfAGenericCollection(): void
    {
        $result = $this->buildWithVendor([
            'src/Item.php' => 'namespace App; class Item { public function price(): int { return 1; } }',
            'src/Order.php' => 'namespace App; use Acme\Collections\Collection; class Order {
                /** @var Collection<int, Item> */
                private Collection $items;
                /** @return Collection<array-key, Item> */
                public function getItems(): Collection { return $this->items; }
                public function total(): int { $total = 0; foreach ($this->items as $item) { $total += $item->price(); } return $total; }
                public function first(): int { return $this->getItems()->first()->price(); }
            }',
            'src/Cart.php' => 'namespace App; class Cart {
                public function sum(Order $order): int { $sum = 0; foreach ($order->getItems() as $item) { $sum += $item->price(); } return $sum; }
                /** @param iterable<Item> $items */
                public function each(iterable $items): void { foreach ($items as $item) { $item->price(); } }
                public function local(Order $order): void { $items = $order->getItems(); foreach ($items as $item) { $item->price(); } }
            }',
        ]);

        foreach (['App\Order::total', 'App\Order::first', 'App\Cart::sum', 'App\Cart::each', 'App\Cart::local'] as $method) {
            self::assertTrue($this->hasEdge($result->graph, $method, 'App\Item::price', Relation::Calls, Confidence::Inferred), $method);
        }
    }

    public function testATemplateOfTheMethodIsUnknownNotGuessed(): void
    {
        $result = $this->buildProject([
            'src/Container.php' => 'namespace App; class Container { /** @template T @param class-string<T> $class @return T */ public function make(string $class): object { return new $class(); } }',
            'src/Mailer.php' => 'namespace App; class Mailer { public function send(): void {} }',
            'src/Box.php' => 'namespace App; /** @template T */ class Box { /** @return T */ public function get(): mixed { return null; } }',
            'src/Use.php' => 'namespace App; class UseIt { public function run(Container $c, Box $box): void { $c->make(Mailer::class)->send(); $box->get()->send(); } }',
        ]);

        self::assertFalse($this->hasEdge($result->graph, 'App\UseIt::run', 'App\Mailer::send', Relation::Calls, Confidence::Inferred));
        self::assertSame(0, $result->calls->inferred - 2, 'only make() and get() are typed');
    }

    public function testAClassGivingItsParentAConcreteArgument(): void
    {
        $result = $this->buildProject([
            'src/Repository.php' => 'namespace App; /** @template T of object */ abstract class Repository { /** @return T|null */ public function find(int $id): ?object { return null; } /** @return list<T> */ public function findAll(): array { return []; } }',
            'src/Order.php' => 'namespace App; class Order { public function pay(): void {} }',
            'src/OrderRepository.php' => 'namespace App; /** @extends Repository<Order> */ class OrderRepository extends Repository {}',
            'src/Payment.php' => 'namespace App; class Payment { public function __construct(private OrderRepository $orders) {} public function one(): void { $this->orders->find(1)->pay(); } public function all(): void { foreach ($this->orders->findAll() as $order) { $order->pay(); } } }',
        ]);

        self::assertTrue($this->hasEdge($result->graph, 'App\Payment::one', 'App\Order::pay', Relation::Calls, Confidence::Inferred));
        self::assertTrue($this->hasEdge($result->graph, 'App\Payment::all', 'App\Order::pay', Relation::Calls, Confidence::Inferred));
    }

    public function testAFalseInADocumentedReturnIsLeftOut(): void
    {
        $result = $this->buildProject([
            'src/Theme.php' => 'namespace App; class Theme { /** @return Theme|false */ public function parent() { return false; } public function errors(): bool { return false; } }',
            'src/Upgrader.php' => 'namespace App; class Upgrader { public function check(Theme $theme): bool { return $theme->parent()->errors(); } }',
        ]);

        self::assertTrue($this->hasEdge($result->graph, 'App\Upgrader::check', 'App\Theme::errors', Relation::Calls, Confidence::Inferred), 'WordPress writes it so');
    }

    /**
     * @param array<string, string> $files
     */
    private function buildWithVendor(array $files): BuildResult
    {
        foreach (self::VENDOR as $path => $content) {
            @mkdir(\dirname($this->root . '/' . $path), 0777, true);
            file_put_contents($this->root . '/' . $path, $content);
        }

        return $this->buildProject($files);
    }
}
