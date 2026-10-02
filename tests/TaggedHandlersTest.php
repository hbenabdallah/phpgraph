<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Relation;
use PhpGraph\Tests\Support\TemporaryProject;
use PHPUnit\Framework\TestCase;

/**
 * Handlers declared by the container configuration: tags in YAML, XML and PHP, _instanceof, and event subscribers.
 */
final class TaggedHandlersTest extends TestCase
{
    use TemporaryProject;

    public function testMessageHandlersTaggedInYamlXmlAndPhp(): void
    {
        $graph = $this->buildProject([
            'src/Messages.php' => 'namespace App; class PlaceOrder {} class CancelOrder {} class ShipOrder {} class Refund {}',
            'src/Handlers.php' => 'namespace App; class Placing { public function __invoke(PlaceOrder $c): void {} }'
                . ' class Cancelling { public function process($c): void {} }'
                . ' class Shipping { public function __invoke(ShipOrder $c): void {} }'
                . ' class Refunding { public function __invoke(Refund $c): void {} }',
            'config/services.yaml' => "services:\n    App\\Placing:\n        tags: [messenger.message_handler]\n"
                . "    app.cancelling:\n        class: App\\Cancelling\n        tags:\n            - { name: messenger.message_handler, handles: App\\CancelOrder, method: process }\n",
            'config/services.xml' => '<?xml version="1.0"?><container><services><service id="app.shipping" class="App\Shipping"><tag name="messenger.message_handler"/></service></services></container>',
            'config/services.php' => 'return static function ($container): void { $container->services()->set("app.refunding", App\Refunding::class)->tag("messenger.message_handler", ["handles" => App\Refund::class]); };',
        ])->graph;

        self::assertTrue($this->hasEdge($graph, 'App\PlaceOrder', 'App\Placing::__invoke', Relation::HandledBy, Confidence::Extracted), 'YAML, message from the parameter');
        self::assertTrue($this->hasEdge($graph, 'App\CancelOrder', 'App\Cancelling::process', Relation::HandledBy, Confidence::Extracted), 'YAML, handles and method attributes');
        self::assertTrue($this->hasEdge($graph, 'App\ShipOrder', 'App\Shipping::__invoke', Relation::HandledBy, Confidence::Extracted), 'XML');
        self::assertTrue($this->hasEdge($graph, 'App\Refund', 'App\Refunding::__invoke', Relation::HandledBy, Confidence::Extracted), 'PHP configurator');
    }

    public function testATagOnAnInterfaceMakesHandlersOfItsImplementations(): void
    {
        $result = $this->buildProject([
            'src/Bus.php' => 'namespace App\Bus; interface CommandHandler {}',
            'src/CreateCourse.php' => 'namespace App\Courses; class CreateCourseCommand {} final class CreateCourseCommandHandler implements \App\Bus\CommandHandler { public function __invoke(CreateCourseCommand $c): void {} }',
            'config/services.yaml' => "services:\n    _instanceof:\n        App\\Bus\\CommandHandler:\n            tags: ['app.command_handler']\n",
        ]);

        self::assertTrue($this->hasEdge($result->graph, 'App\Courses\CreateCourseCommand', 'App\Courses\CreateCourseCommandHandler::__invoke', Relation::HandledBy, Confidence::Extracted));
        self::assertSame(['EXTRACTED' => 1], $result->bus->handlers, 'Counted once, though the shape shows it too');
    }

    public function testEventListenersOfEventClassesAndNamedEvents(): void
    {
        $graph = $this->buildProject([
            'src/Events.php' => 'namespace App; class OrderPlaced {}',
            'src/Listeners.php' => 'namespace App; class Mailer { public function __invoke(OrderPlaced $e): void {} } class Stock { public function onOrderPaid($e): void {} }',
            'src/Payment.php' => 'namespace App; class Payment { public function __construct(private \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $events) {} public function pay(): void { $this->events->dispatch(new \stdClass(), "order.paid"); } }',
            'config/services.yaml' => "services:\n    App\\Mailer:\n        tags: [{ name: kernel.event_listener, event: App\\OrderPlaced }]\n"
                . "    App\\Stock:\n        tags:\n            - kernel.event_listener: { event: order.paid }\n"
                . "    App\\Audit:\n        tags: [{ name: doctrine.event_listener, event: prePersist }]\n",
        ])->graph;

        self::assertTrue($this->hasEdge($graph, 'App\OrderPlaced', 'App\Mailer::__invoke', Relation::HandledBy, Confidence::Extracted));
        self::assertTrue($this->hasEdge($graph, 'channel:order.paid', 'App\Stock::onOrderPaid', Relation::HandledBy, Confidence::Extracted), 'Default method: on + the event name');
        self::assertTrue($this->hasEdge($graph, 'App\Payment::pay', 'channel:order.paid', Relation::Dispatches, Confidence::Inferred), 'dispatch($event, name)');
        self::assertNull($graph->node('channel:prePersist'), 'Doctrine lifecycle events are not messages');
    }

    public function testEventSubscribersAreReadFromTheirCode(): void
    {
        $graph = $this->buildProject([
            'src/Events.php' => 'namespace App; class OrderPlaced { const SHIPPED = "order.shipped"; }',
            'src/Subscriber.php' => 'namespace App; abstract class Base { public function onShipped($e): void {} }'
                . ' class OrderSubscriber extends Base implements \Symfony\Component\EventDispatcher\EventSubscriberInterface {'
                . ' public static function getSubscribedEvents(): array { return [OrderPlaced::class => "onPlaced", "order.paid" => ["onPaid", 10], OrderPlaced::SHIPPED => [["onShipped", 5]]]; }'
                . ' public function onPlaced(OrderPlaced $e): void {} public function onPaid($e): void {} }',
        ])->graph;

        self::assertTrue($this->hasEdge($graph, 'App\OrderPlaced', 'App\OrderSubscriber::onPlaced', Relation::HandledBy, Confidence::Extracted));
        self::assertTrue($this->hasEdge($graph, 'channel:order.paid', 'App\OrderSubscriber::onPaid', Relation::HandledBy, Confidence::Extracted));
        self::assertTrue($this->hasEdge($graph, 'channel:order.shipped', 'App\Base::onShipped', Relation::HandledBy, Confidence::Extracted), 'Event name in a constant, method inherited');
    }
}
