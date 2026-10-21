<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Relation;
use PhpGraph\Tests\Support\TemporaryProject;
use PHPUnit\Framework\TestCase;

/**
 * What a callable of the packages asserts of its arguments narrows them in the project: a helper read from an
 * autoloaded file (`@phpstan-assert-if-true`), a static method of an assertion class (`@psalm-assert` of a template a
 * `class-string` argument binds). The project's own callables are in Fixtures/expectations/docblock_assertions.php.
 */
final class AssertionsTest extends TestCase
{
    use TemporaryProject;

    public function testAssertionsOfTheDependenciesNarrowTheirArguments(): void
    {
        $result = $this->buildProject([
            'composer.json' => '{}',
            'vendor/composer/installed.json' => '{"packages": [{"name": "acme/assert", "install-path": "../acme/assert", "autoload": {"psr-4": {"Acme\\\\": "src/"}, "files": ["src/functions.php"]}}]}',
            'vendor/acme/assert/src/Assert.php' => "namespace Acme;\nfinal class Assert {\n    /**\n     * @psalm-template ExpectedType of object\n     * @psalm-param class-string<ExpectedType> \$class\n     * @psalm-assert ExpectedType \$value\n     */\n    public static function isInstanceOf(\$value, \$class): void {}\n}\n",
            'vendor/acme/assert/src/functions.php' => "/**\n * @phpstan-assert-if-true WP_Error \$thing\n */\nfunction is_wp_error(\$thing): bool { return false; }\n",
            'src/WP_Error.php' => 'class WP_Error { public function get_error_message(): string { return ""; } }',
            'src/Order.php' => 'namespace App; class Order { public function ship(): void {} } class Draft { public function ship(): void {} }',
            'src/Checkout.php' => 'namespace App; class Checkout {'
                . ' public function pay($response): string { if (is_wp_error($response)) { return $response->get_error_message(); } return ""; }'
                . ' public function ship($order): void { \Acme\Assert::isInstanceOf($order, Order::class); $order->ship(); } }',
        ]);
        $graph = $result->graph;

        self::assertTrue($this->hasEdge($graph, 'App\Checkout::pay', 'WP_Error::get_error_message', Relation::Calls, Confidence::Inferred), 'a helper of a package');
        self::assertTrue($this->hasEdge($graph, 'App\Checkout::ship', 'App\Order::ship', Relation::Calls, Confidence::Inferred), 'the template bound by Order::class');
        self::assertFalse($this->hasEdge($graph, 'App\Checkout::ship', 'App\Draft::ship', Relation::Calls));
    }
}
