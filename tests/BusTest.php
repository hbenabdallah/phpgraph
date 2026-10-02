<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Builder\BuildResult;
use PhpGraph\Builder\GraphBuilder;
use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Graph;
use PhpGraph\Graph\Relation;
use PhpGraph\Presentation\TextPresenter;
use PhpGraph\Query\GraphQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sender --dispatches--> message --handled_by--> handler, one case per kind of evidence.
 */
final class BusTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/phpgraph-bus-' . bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root);
    }

    /**
     * @return iterable<string, array{string, string, string, Relation, Confidence}>
     */
    public static function links(): iterable
    {
        yield 'class attribute: Symfony AsMessageHandler' => [
            'namespace App; use Symfony\Component\Messenger\Attribute\AsMessageHandler; class PlaceOrder {} #[AsMessageHandler] final class PlaceOrderHandler { public function __invoke(PlaceOrder $command): void {} }',
            'App\PlaceOrder', 'App\PlaceOrderHandler::__invoke', Relation::HandledBy, Confidence::Extracted,
        ];
        yield 'method attribute on a static aggregate method: Ecotone CommandHandler' => [
            'namespace App; use Ecotone\Modelling\Attribute\CommandHandler; class RegisterUser {} class User { #[CommandHandler] public static function register(RegisterUser $command): self { return new self(); } }',
            'App\RegisterUser', 'App\User::register', Relation::HandledBy, Confidence::Extracted,
        ];
        yield 'class attribute naming the handled message and the method' => [
            'namespace App; class Ping {} #[\Symfony\Component\Messenger\Attribute\AsMessageHandler(handles: Ping::class, method: "onPing")] class Pong { public function onPing($message): void {} }',
            'App\Ping', 'App\Pong::onPing', Relation::HandledBy, Confidence::Extracted,
        ];
        yield "a project's own handler attribute: PrestaShop AsCommandHandler" => [
            'namespace App; #[\Attribute] class AsCommandHandler {} class EditCart {} #[AsCommandHandler] class EditCartHandler { public function handle(EditCart $command): void {} }',
            'App\EditCart', 'App\EditCartHandler::handle', Relation::HandledBy, Confidence::Extracted,
        ];
        yield 'shape: __invoke with one message in a class implementing a handler interface' => [
            'namespace App; interface CommandHandler {} class CreateCourseCommand {} final class CourseCreator implements CommandHandler { public function __invoke(CreateCourseCommand $command): void {} }',
            'App\CreateCourseCommand', 'App\CourseCreator::__invoke', Relation::HandledBy, Confidence::Inferred,
        ];
        yield 'shape: a handler-named class taking an object not named like a message, never sent' => [
            'namespace App; class Category {} final class GetPositionHandler { public function __invoke(Category $category): int { return 0; } }',
            'App\Category', 'App\GetPositionHandler::__invoke', Relation::HandledBy, Confidence::Ambiguous,
        ];
        yield 'shape: handle with one message that a bus sends' => [
            'namespace App; class OrderShipped {} class Notifier { public function handle(OrderShipped $event): void {} } class Shipper { public function __construct(private \Symfony\Component\Messenger\MessageBusInterface $bus) {} public function ship(): void { $this->bus->dispatch(new OrderShipped()); } }',
            'App\OrderShipped', 'App\Notifier::handle', Relation::HandledBy, Confidence::Inferred,
        ];
        yield 'name: XHandler with an untyped parameter handles X' => [
            'namespace App; class Pay {} class PayHandler { public function __invoke($command): void {} }',
            'App\Pay', 'App\PayHandler::__invoke', Relation::HandledBy, Confidence::Ambiguous,
        ];
        yield 'Laravel listen table' => [
            'namespace App; class OrderShipped {} class SendShipmentNotification { public function handle($event): void {} } class EventServiceProvider extends \Illuminate\Foundation\Support\Providers\EventServiceProvider { protected $listen = [OrderShipped::class => [SendShipmentNotification::class]]; }',
            'App\OrderShipped', 'App\SendShipmentNotification::handle', Relation::HandledBy, Confidence::Extracted,
        ];
        yield 'send through a typed bus' => [
            'namespace App; class PlaceOrder {} class CheckoutController { public function __construct(private \App\Bus\CommandBus $commandBus) {} public function __invoke(): void { $this->commandBus->dispatch(new PlaceOrder()); } }',
            'App\CheckoutController::__invoke', 'App\PlaceOrder', Relation::Dispatches, Confidence::Inferred,
        ];
        yield 'send of a typed variable' => [
            'namespace App; class PlaceOrder {} class Retry { public function run(\Symfony\Component\Messenger\MessageBusInterface $bus, PlaceOrder $command): void { $bus->dispatch($command); } }',
            'App\Retry::run', 'App\PlaceOrder', Relation::Dispatches, Confidence::Inferred,
        ];
        yield 'domain event recorded by an aggregate' => [
            'namespace App; class OrderPlaced {} class Order { public function place(): void { $this->recordThat(new OrderPlaced()); } }',
            'App\Order::place', 'App\OrderPlaced', Relation::Dispatches, Confidence::Inferred,
        ];
        yield 'send through a receiver of unknown type' => [
            'namespace App; class PlaceOrder {} class Checkout { public function run($bus): void { $bus->dispatch(new PlaceOrder()); } }',
            'App\Checkout::run', 'App\PlaceOrder', Relation::Dispatches, Confidence::Ambiguous,
        ];
        yield 'Laravel event helper' => [
            'namespace Illuminate\Foundation { class Application {} } namespace App { class OrderShipped {} class Shipper { public function ship(): void { event(new OrderShipped()); } } }',
            'App\Shipper::ship', 'App\OrderShipped', Relation::Dispatches, Confidence::Inferred,
        ];
        yield 'Laravel dispatchable job' => [
            'namespace Illuminate\Foundation\Bus { trait Dispatchable {} } namespace App { class ProcessPodcast { use \Illuminate\Foundation\Bus\Dispatchable; } class Uploader { public function upload($podcast): void { ProcessPodcast::dispatch($podcast); } } }',
            'App\Uploader::upload', 'App\ProcessPodcast', Relation::Dispatches, Confidence::Inferred,
        ];
        yield 'send through a project method that forwards to a bus' => [
            'namespace App; class CreateCourse {} abstract class ApiController { public function __construct(private \App\Bus\CommandBus $bus) {} protected function dispatch(object $command): void { $this->bus->dispatch($command); } }'
            . ' class CoursesPutController extends ApiController { public function __invoke(): void { $this->dispatch(new CreateCourse()); } }',
            'App\CoursesPutController::__invoke', 'App\CreateCourse', Relation::Dispatches, Confidence::Inferred,
        ];
        yield 'send from a script, outside any function' => [
            'namespace App; class PlaceOrder {} interface CommandBus {} /** @var CommandBus $bus */ $bus = make(); $bus->send(new PlaceOrder());',
            'file:a.php', 'App\PlaceOrder', Relation::Dispatches, Confidence::Inferred,
        ];
        yield 'Laravel job handled by its own handle method' => [
            'namespace Illuminate\Foundation\Bus { trait Dispatchable {} } namespace App { class ProcessPodcast { use \Illuminate\Foundation\Bus\Dispatchable; public function handle(): void {} } class Uploader { public function upload(): void { ProcessPodcast::dispatch(); } } }',
            'App\ProcessPodcast', 'App\ProcessPodcast::handle', Relation::HandledBy, Confidence::Inferred,
        ];
    }

    #[DataProvider('links')]
    public function testLinksMessages(string $code, string $source, string $target, Relation $relation, Confidence $confidence): void
    {
        $graph = $this->build($code)->graph;

        self::assertTrue($this->hasEdge($graph, $source, $target, $relation, $confidence), "Missing {$source} --{$relation->value}--> {$target} [{$confidence->value}]");
    }

    /**
     * @return iterable<string, array{string, string, string, Relation}>
     */
    public static function nonLinks(): iterable
    {
        yield 'handle with one object, no handler name, never sent' => [
            'namespace App; class Order {} class PriceFormatter { public function handle(Order $order): void {} }',
            'App\Order', 'App\PriceFormatter::handle', Relation::HandledBy,
        ];
        yield 'send on a known receiver that is not a bus' => [
            'namespace App; class Email {} class Mailer { public function send(Email $email): void {} } class Welcome { public function __construct(private Mailer $mailer) {} public function run(): void { $this->mailer->send(new Email()); } }',
            'App\Welcome::run', 'App\Email', Relation::Dispatches,
        ];
        yield 'a message that is not a project class' => [
            'namespace App; class Sender { public function __construct(private \Symfony\Component\Messenger\MessageBusInterface $bus) {} public function run(): void { $this->bus->dispatch(new \Symfony\Component\Mailer\Messenger\SendEmailMessage()); } }',
            'App\Sender::run', 'Symfony\Component\Mailer\Messenger\SendEmailMessage', Relation::Dispatches,
        ];
        yield 'send of an abstract message type, that only says something is sent' => [
            'namespace App; interface Command {} class PlaceOrder implements Command {} class Bus { public function __construct(private \Symfony\Component\Messenger\MessageBusInterface $bus) {} public function dispatch(Command $command): void { $this->bus->dispatch($command); } }',
            'App\Bus::dispatch', 'App\Command', Relation::Dispatches,
        ];
        yield 'handler method with two parameters and no attribute' => [
            'namespace App; class Request {} class AuthMiddleware { public function handle(Request $request, \Closure $next): void {} }',
            'App\Request', 'App\AuthMiddleware::handle', Relation::HandledBy,
        ];
    }

    #[DataProvider('nonLinks')]
    public function testDoesNotLinkWithoutEvidence(string $code, string $source, string $target, Relation $relation): void
    {
        foreach ($this->build($code)->graph->edges() as $edge) {
            self::assertFalse($edge->source === $source && $edge->target === $target && $edge->relation === $relation, "Unexpected {$source} --{$relation->value}--> {$target}");
        }
    }

    public function testReportsWhatItCouldNotLink(): void
    {
        $bus = $this->build(
            'namespace App; use Symfony\Component\Messenger\MessageBusInterface;'
            . ' class PlaceOrder {} class CancelOrder {} class ArchiveOrder {}'
            . ' #[\Symfony\Component\Messenger\Attribute\AsMessageHandler] class PlaceOrderHandler { public function __invoke(PlaceOrder $c): void {} }'
            . ' #[\Symfony\Component\Messenger\Attribute\AsMessageHandler] class ArchiveOrderHandler { public function __invoke(ArchiveOrder $c): void {} }'
            . ' class Controller { public function __construct(private MessageBusInterface $bus) {} public function run(array $payload): void {'
            . ' $this->bus->dispatch(new PlaceOrder()); $this->bus->dispatch(new CancelOrder()); $this->bus->dispatch($payload["command"]); } }',
        )->bus;

        self::assertSame(['EXTRACTED' => 2], $bus->handlers);
        self::assertSame(['INFERRED' => 2], $bus->sends);
        self::assertSame(1, $bus->untypedSends);
        self::assertSame(['App\CancelOrder'], $bus->messagesWithoutHandler);
        self::assertSame(['App\ArchiveOrder'], $bus->messagesNeverSent);
    }

    public function testThePathGoesFromTheSenderThroughTheMessageToTheHandler(): void
    {
        $graph = $this->build(
            'namespace App; class PlaceOrder {} #[\Symfony\Component\Messenger\Attribute\AsMessageHandler] class PlaceOrderHandler { public function __invoke(PlaceOrder $c): void {} }'
            . ' class CheckoutController { public function __construct(private \Symfony\Component\Messenger\MessageBusInterface $bus) {} public function __invoke(): void { $this->bus->dispatch(new PlaceOrder()); } }',
        )->graph;

        $path = (new TextPresenter(new GraphQuery($graph)))->path('CheckoutController::__invoke', 'PlaceOrderHandler::__invoke');

        self::assertStringContainsString('--dispatches--> PlaceOrder', $path);
        self::assertStringContainsString('--handled_by--> PlaceOrderHandler::__invoke()', $path);
    }

    private function build(string $code): BuildResult
    {
        file_put_contents($this->root . '/a.php', "<?php\n" . $code . "\n");

        return (new GraphBuilder())->build($this->root);
    }

    private function hasEdge(Graph $graph, string $source, string $target, Relation $relation, Confidence $confidence): bool
    {
        foreach ($graph->edges() as $edge) {
            if ($edge->source === $source && $edge->target === $target && $edge->relation === $relation && $edge->confidence === $confidence) {
                return true;
            }
        }

        return false;
    }
}
