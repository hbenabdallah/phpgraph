<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Relation;
use PhpGraph\Tests\Support\TemporaryProject;
use PHPUnit\Framework\TestCase;

/**
 * Magic properties carry a chain on: documented (`@property`), and on an Eloquent model, the casts, the timestamps and
 * the relations read as properties.
 */
final class EloquentAttributesTest extends TestCase
{
    use TemporaryProject;

    private const ELOQUENT = [
        'src/Eloquent.php' => 'namespace Illuminate\Database\Eloquent; abstract class Model {'
            . ' /** @return static|mixed */ public function scopes(array $scopes) { return $this; } public function save(): bool { return true; } }',
        'src/Relations.php' => 'namespace Illuminate\Database\Eloquent\Relations;'
            . ' /** @template TRelatedModel @template TDeclaringModel @template TResult */ abstract class Relation { /** @return TResult */ abstract public function getResults(); }'
            . ' /** @template TRelatedModel @template TDeclaringModel @extends Relation<TRelatedModel, TDeclaringModel, ?TRelatedModel> */'
            . ' class BelongsTo extends Relation { /** @inheritDoc */ public function getResults() { return null; } }',
        'src/Carbon.php' => 'namespace Illuminate\Support; class Carbon { public function toDateString(): string { return ""; } }',
        'src/Casts.php' => 'namespace Illuminate\Contracts\Database\Eloquent; /** @template TGet @template TSet */ interface CastsAttributes {}',
        'src/Money.php' => 'namespace App; class Money { public function format(): string { return ""; } }'
            . ' /** @implements \Illuminate\Contracts\Database\Eloquent\CastsAttributes<Money, Money> */ class MoneyCast implements \Illuminate\Contracts\Database\Eloquent\CastsAttributes {}'
            . ' enum Status: string { case Paid = "paid"; public function label(): string { return ""; } }',
        'src/Hotel.php' => 'namespace App; class Hotel extends \Illuminate\Database\Eloquent\Model { public function name(): string { return ""; } }',
        'src/Booking.php' => 'namespace App; use Illuminate\Database\Eloquent\Relations\BelongsTo; class Booking extends \Illuminate\Database\Eloquent\Model {'
            . ' protected $casts = ["paid_at" => "immutable_datetime"];'
            . ' protected function casts(): array { return ["check_in" => "date:Y-m-d", "status" => Status::class, "total" => MoneyCast::class]; }'
            . ' /** @return BelongsTo<Hotel, $this> */ public function hotel(): BelongsTo { return new BelongsTo(); } }',
    ];

    public function testTheCastsTimestampsAndRelationsOfAModel(): void
    {
        $result = $this->buildProject(self::ELOQUENT + [
            'src/Bookings.php' => 'namespace App; class Bookings { public function show(Booking $booking): void {'
                . ' $booking->check_in->toDateString(); $booking->created_at->toDateString(); $booking->status->label();'
                . ' $booking->total->format(); $booking->hotel->name(); $booking->scopes(["paid"])->save(); } }',
        ]);
        $graph = $result->graph;

        foreach (['Illuminate\Support\Carbon::toDateString', 'App\Status::label', 'App\Money::format', 'App\Hotel::name', 'Illuminate\Database\Eloquent\Model::save'] as $target) {
            self::assertTrue($this->hasEdge($graph, 'App\Bookings::show', $target, Relation::Calls, Confidence::Inferred), $target);
        }
        self::assertSame(0, $result->calls->unknownReceiver);
    }

    public function testAnotherClassKeepsItsUnknownProperties(): void
    {
        $result = $this->buildProject(self::ELOQUENT + [
            'src/Dto.php' => 'namespace App; class Dto { public function hotel(): Hotel { return new Hotel(); } }',
            'src/Use.php' => 'namespace App; class UseIt { public function run(Dto $dto): void { $dto->created_at->toDateString(); $dto->hotel->name(); } }',
        ]);

        self::assertFalse($this->hasEdge($result->graph, 'App\UseIt::run', 'Illuminate\Support\Carbon::toDateString', Relation::Calls, Confidence::Inferred));
        self::assertFalse($this->hasEdge($result->graph, 'App\UseIt::run', 'App\Hotel::name', Relation::Calls, Confidence::Inferred));
    }

    public function testDocumentedProperties(): void
    {
        $result = $this->buildProject([
            'src/Item.php' => 'namespace App; class Item { public function price(): int { return 1; } }',
            'src/Order.php' => 'namespace App; /** @property-read Item $item @property list<Item> $items @property-write Item $ignored */ class Order { public function __get(string $name): mixed { return null; } }',
            'src/Use.php' => 'namespace App; class UseIt { public function run(Order $order): void { $order->item->price(); foreach ($order->items as $item) { $item->price(); } $order->ignored->price(); } }',
        ]);

        self::assertTrue($this->hasEdge($result->graph, 'App\UseIt::run', 'App\Item::price', Relation::Calls, Confidence::Inferred));
        self::assertSame(2, $result->calls->inferred, '@property-write is not read');
    }
}
