<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Builder\TestFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TestFilesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function paths(): iterable
    {
        yield 'tests directory' => ['tests/Unit/OrderTotals.php', true];
        yield 'nested Tests directory' => ['src/Sales/Tests/OrderMother.php', true];
        yield 'PhpSpec spec directory' => ['src/Sales/spec/OrderSpec.php', true];
        yield 'Behat contexts under src' => ['src/Sylius/Behat/Context/Ui/CartContext.php', true];
        yield 'PHPUnit test next to the code' => ['src/Sales/OrderTest.php', true];
        yield 'shared test case' => ['src/Support/IntegrationTestCase.php', true];
        yield 'Codeception test' => ['src/Checkout/CheckoutCest.php', true];
        yield 'application code' => ['src/Sales/Domain/Order.php', false];
        yield 'application named Testing' => ['Testing/src/Domain/User.php', false];
        yield 'data fixtures' => ['src/CoreBundle/Fixture/OrderFixture.php', false];
        yield 'name containing test' => ['src/Contest/Latest.php', false];
    }

    #[DataProvider('paths')]
    public function testRecognisesTestCode(string $path, bool $isTest): void
    {
        self::assertSame($isTest, TestFiles::isTest($path));
    }
}
