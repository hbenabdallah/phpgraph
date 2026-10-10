<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Relation;
use PhpGraph\Tests\Support\TemporaryProject;
use PHPUnit\Framework\TestCase;

/**
 * Call chains through PHP's own classes, without any vendor/ directory: their signatures are generated once.
 */
final class InternalClassesTest extends TestCase
{
    use TemporaryProject;

    public function testChainsGoThroughPhpClasses(): void
    {
        $result = $this->buildProject([
            'src/Clock.php' => 'namespace App; class Clock { public function now(): \DateTimeImmutable { return new \DateTimeImmutable(); } }',
            'src/Schedule.php' => 'namespace App; class Schedule { public function at(Clock $clock): string { return $clock->now()->modify("+1 day")->setTime(8, 0)->getTimezone()->getName(); } }',
        ]);

        self::assertSame(0, $result->calls->unknownReceiver, 'modify() returns DateTimeImmutable (its false is left out), getTimezone() a DateTimeZone');
        self::assertSame(4, $result->calls->outsideProject);
    }

    public function testElementsOfPhpCollections(): void
    {
        $result = $this->buildProject([
            'src/Item.php' => 'namespace App; class Item { public function price(): int { return 1; } }',
            'src/Items.php' => 'namespace App; /** @extends \ArrayIterator<int, Item> */ class Items extends \ArrayIterator {}',
            'src/Cart.php' => 'namespace App; class Cart {
                /** @var \SplObjectStorage<Item, mixed> */
                private \SplObjectStorage $chosen;
                public function __construct(private Items $items) { $this->chosen = new \SplObjectStorage(); }
                public function chosen(): void { foreach ($this->chosen as $item) { $item->price(); } }
                public function all(): void { foreach ($this->items as $item) { $item->price(); } $this->items->current()->price(); }
            }',
        ]);

        self::assertTrue($this->hasEdge($result->graph, 'App\Cart::chosen', 'App\Item::price', Relation::Calls, Confidence::Inferred), 'SplObjectStorage walks its objects');
        self::assertTrue($this->hasEdge($result->graph, 'App\Cart::all', 'App\Item::price', Relation::Calls, Confidence::Inferred), 'through @extends ArrayIterator<int, Item>');
        self::assertSame(0, $result->calls->unknownReceiver);
    }
}
