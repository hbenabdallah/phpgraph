<?php

namespace App;

class Card { public function charge(): void {} }
class Transfer { public function charge(): void {} }
class Wallet { public function charge(): void {} }
class PaymentFailed extends \RuntimeException { public function retry(): void {} }
class GatewayDown extends \RuntimeException { public function retry(): void {} }

class Checkout
{
    public function switched(string $method): void
    {
        switch ($method) {
            case 'card':
                $payment = new Card();
                break;
            case 'transfer':
                $payment = new Transfer();
                break;
            default:
                $payment = new Wallet();
        }
        $payment->charge(); // @phpgraph-expect calls App\Card::charge INFERRED @phpgraph-expect calls App\Transfer::charge INFERRED @phpgraph-expect calls App\Wallet::charge INFERRED
    }

    public function tried(): void
    {
        try {
            $payment = new Card();
        } catch (\RuntimeException) {
            $payment = new Wallet();
        }
        $payment->charge(); // @phpgraph-expect calls App\Card::charge INFERRED @phpgraph-expect calls App\Wallet::charge INFERRED
    }

    public function multiCatch(): void
    {
        try {
            $this->switched('card'); // @phpgraph-expect calls App\Checkout::switched INFERRED
        } catch (PaymentFailed|GatewayDown $failure) {
            $failure->retry(); // @phpgraph-expect calls App\GatewayDown::retry INFERRED @phpgraph-expect calls App\PaymentFailed::retry INFERRED
        }
    }

    public function ternary(bool $saved): void
    {
        $payment = $saved ? new Card() : new Transfer();
        $payment->charge(); // @phpgraph-expect calls App\Card::charge INFERRED @phpgraph-expect calls App\Transfer::charge INFERRED
    }

    public function coalesce(?Card $saved): void
    {
        $payment = $saved ?? new Wallet();
        $payment->charge(); // @phpgraph-expect calls App\Card::charge INFERRED @phpgraph-expect calls App\Wallet::charge INFERRED
    }

    public function matched(string $method): void
    {
        $payment = match ($method) {
            'card' => new Card(),
            default => new Transfer(),
        };
        $payment->charge(); // @phpgraph-expect calls App\Card::charge INFERRED @phpgraph-expect calls App\Transfer::charge INFERRED
    }
}
