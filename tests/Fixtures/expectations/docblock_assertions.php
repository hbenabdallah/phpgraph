<?php

// A call narrows its argument as its callable's @phpstan-assert / @psalm-assert tags say, whatever file declares it.

namespace Lib {
    class Failure { public function message(): string { return ''; } }

    /**
     * @param mixed $thing
     * @phpstan-assert-if-true Failure $thing
     */
    function is_failure($thing): bool
    {
        return $thing instanceof Failure;
    }

    final class Assert
    {
        /**
         * @psalm-template ExpectedType of object
         * @psalm-param class-string<ExpectedType> $class
         * @psalm-assert ExpectedType $value
         *
         * @param mixed  $value
         * @param string $class
         */
        public static function isInstanceOf($value, $class): void
        {
        }

        /**
         * @psalm-assert !null $value
         */
        public static function notNull($value): void
        {
        }
    }

    abstract class TestCase
    {
        /**
         * @template ExpectedType of object
         * @param class-string<ExpectedType> $expected
         * @phpstan-assert =ExpectedType $actual
         */
        public static function assertInstanceOf(string $expected, mixed $actual): void
        {
        }
    }
}

namespace App {
    use Lib\Assert;
    use Lib\Failure;

    class Order { public function ship(): void {} }
    class Invoice { public function ship(): void {} }

    class Shipping
    {
        public function guard($result): string
        {
            if (\Lib\is_failure($result)) {
                return $result->message(); // @phpgraph-expect calls Lib\Failure::message INFERRED
            }

            return '';
        }

        public function negated($result, bool $fallback): string
        {
            if (!\Lib\is_failure($result)) {
                $result = new Failure();
            }

            // A Failure on both paths.
            return $result->message(); // @phpgraph-expect calls Lib\Failure::message INFERRED
        }

        public function asserted(): void
        {
            $order = $this->load(); // @phpgraph-expect calls App\Shipping::load INFERRED
            Assert::isInstanceOf($order, Order::class); // @phpgraph-expect calls Lib\Assert::isInstanceOf INFERRED
            $order->ship(); // @phpgraph-expect calls App\Order::ship INFERRED
        }

        public function nothingAsserted(): void
        {
            $order = $this->load(); // @phpgraph-expect calls App\Shipping::load INFERRED
            Assert::notNull($order); // @phpgraph-expect calls Lib\Assert::notNull INFERRED
            // Nothing known: ship() is declared twice, nothing is guessed.
            $order->ship();
        }

        private function load()
        {
            return null;
        }
    }

    class ShippingTest extends \Lib\TestCase
    {
        public function testShips(): void
        {
            $order = $this->make(); // @phpgraph-expect calls App\ShippingTest::make INFERRED
            self::assertInstanceOf(Invoice::class, $order); // @phpgraph-expect calls Lib\TestCase::assertInstanceOf INFERRED
            $order->ship(); // @phpgraph-expect calls App\Invoice::ship INFERRED
        }

        private function make()
        {
            return null;
        }
    }
}
