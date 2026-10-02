<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Relation;
use PhpGraph\Tests\Support\TemporaryProject;
use PHPUnit\Framework\TestCase;

/**
 * Listeners wired by a call in the code: Symfony addListener(), Laravel Event::listen(), WordPress add_action().
 */
final class ListenerRegistrationTest extends TestCase
{
    use TemporaryProject;

    public function testSymfonyAddListener(): void
    {
        $graph = $this->buildProject([
            'src/Setup.php' => 'namespace App; class OrderPaid {} class Setup {'
                . ' public function __construct(private \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $events) {}'
                . ' public function boot(): void { $this->events->addListener(OrderPaid::class, [$this, "onPaid"]); $this->events->addListener("cart.cleared", [Stock::class, "release"]); $this->events->addListener("ignored", function () {}); }'
                . ' public function onPaid(OrderPaid $event): void {} }'
                . ' class Stock { public function release(): void {} }',
        ])->graph;

        self::assertTrue($this->hasEdge($graph, 'App\OrderPaid', 'App\Setup::onPaid', Relation::HandledBy, Confidence::Inferred));
        self::assertTrue($this->hasEdge($graph, 'channel:cart.cleared', 'App\Stock::release', Relation::HandledBy, Confidence::Inferred));
        self::assertNull($graph->node('channel:ignored'), 'A closure names no listener');
    }

    public function testLaravelEventListen(): void
    {
        $graph = $this->buildProject([
            'app/Providers.php' => 'namespace App; use Illuminate\Support\Facades\Event; class OrderShipped {}'
                . ' class SendNotification { public function handle(OrderShipped $event): void {} }'
                . ' class Audit { public function record(): void {} }'
                . ' class AppServiceProvider { public function boot(): void { Event::listen(OrderShipped::class, SendNotification::class); Event::listen(OrderShipped::class, [Audit::class, "record"]); } }',
        ])->graph;

        self::assertTrue($this->hasEdge($graph, 'App\OrderShipped', 'App\SendNotification::handle', Relation::HandledBy, Confidence::Inferred));
        self::assertTrue($this->hasEdge($graph, 'App\OrderShipped', 'App\Audit::record', Relation::HandledBy, Confidence::Inferred));
    }

    public function testWordPressHooks(): void
    {
        $result = $this->buildProject([
            'plugin.php' => 'function my_register_types() {}'
                . ' class My_Plugin { public function __construct() { add_action("save_post", array($this, "on_save")); add_filter("the_title", array(__CLASS__, "filter_title")); }'
                . ' public function on_save($id) {} public static function filter_title($title) { return $title; } }'
                . ' add_action("init", "my_register_types");',
            'core.php' => 'function wp_insert_post($post) { do_action("save_post", 1); do_action("save_post_{$post->type}", 1); return apply_filters("the_title", "x"); }'
                . ' do_action("init");',
        ]);
        $graph = $result->graph;

        self::assertTrue($this->hasEdge($graph, 'channel:init', 'my_register_types', Relation::HandledBy, Confidence::Inferred), 'A function by name');
        self::assertTrue($this->hasEdge($graph, 'channel:save_post', 'My_Plugin::on_save', Relation::HandledBy, Confidence::Inferred), 'array($this, ...)');
        self::assertTrue($this->hasEdge($graph, 'channel:the_title', 'My_Plugin::filter_title', Relation::HandledBy, Confidence::Inferred), 'array(__CLASS__, ...)');
        self::assertTrue($this->hasEdge($graph, 'wp_insert_post', 'channel:save_post', Relation::Dispatches, Confidence::Inferred));
        self::assertTrue($this->hasEdge($graph, 'wp_insert_post', 'channel:the_title', Relation::Dispatches, Confidence::Inferred));
        self::assertTrue($this->hasEdge($graph, 'file:core.php', 'channel:init', Relation::Dispatches, Confidence::Inferred), 'From a script');
        self::assertSame(1, $result->bus->untypedSends, 'A hook name computed at runtime');
    }
}
