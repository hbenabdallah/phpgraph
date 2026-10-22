<?php

// Branches assigning a class and one of its subclasses: a call reaches the subclass when only it declares the method,
// the parent otherwise (jac2-web: `Context::getSocieteEnCours()` documented `@return JacBean`, a Societe elsewhere).

namespace App;

class Bean
{
    public function get(): int { return 0; }
    public function describe(): string { return ''; }
}

class Company extends Bean
{
    public function isForeign(): bool { return false; }
    public function describe(): string { return 'company'; }
}

class Context
{
    public static function current(): Bean { return new Company(); }
}

class Accounts
{
    public function main(): Company { return new Company(); }

    public function foreign(bool $current): bool
    {
        if ($current) {
            $company = Context::current(); // @phpgraph-expect calls App\Context::current INFERRED
        } else {
            $company = $this->main(); // @phpgraph-expect calls App\Accounts::main INFERRED
        }

        return $company->isForeign(); // @phpgraph-expect calls App\Company::isForeign INFERRED
    }

    public function value(bool $current): int
    {
        if ($current) {
            $company = Context::current(); // @phpgraph-expect calls App\Context::current INFERRED
        } else {
            $company = $this->main(); // @phpgraph-expect calls App\Accounts::main INFERRED
        }

        // Declared by the parent: the parent, once.
        return $company->get(); // @phpgraph-expect calls App\Bean::get INFERRED
    }

    public function overridden(bool $current): string
    {
        if ($current) {
            $company = Context::current(); // @phpgraph-expect calls App\Context::current INFERRED
        } else {
            $company = $this->main(); // @phpgraph-expect calls App\Accounts::main INFERRED
        }

        // Declared by both: each, as for a documented `Bean|Company`.
        return $company->describe(); // @phpgraph-expect calls App\Bean::describe INFERRED @phpgraph-expect calls App\Company::describe INFERRED
    }
}
