<?php

// Docblocks as older code writes them, read as they mean (jac2-web): a class followed by parentheses, `@var` in a plain
// comment with the variable first, an IDE placeholder for a type; a static variable cached on the first call.

namespace App;

class Draft { public function attach(string $file): void {} }
class Client { public function name(): string { return ''; } }
class Line { public function weight(): int { return 0; } }
class Finder { public function lookUp(): void {} }

class Mailer
{
    /**
     * @param Client () $client the client
     *
     * @return Draft() $draft
     */
    public function prepare($client)
    {
        $client->name(); // @phpgraph-expect calls App\Client::name INFERRED

        return new Draft();
    }

    public function send(Client $client): void
    {
        $draft = $this->prepare($client); // @phpgraph-expect calls App\Mailer::prepare INFERRED
        $draft->attach('invoice.pdf'); // @phpgraph-expect calls App\Draft::attach INFERRED
    }

    public function total(array $lines): int
    {
        $total = 0;
        foreach ($lines as $line) {
            /* @var $line Line */
            $total += $line->weight(); // @phpgraph-expect calls App\Line::weight INFERRED
        }

        return $total;
    }

    /**
     * @param unknown $finder a placeholder, known nowhere: the method is looked for by its name
     */
    public function placeholder($finder): void
    {
        $finder->lookUp(); // @phpgraph-expect calls App\Finder::lookUp AMBIGUOUS
    }

    public function cached(): int
    {
        static $line = null;
        if ($line === null) {
            $line = new Line();
        }

        return $line->weight(); // @phpgraph-expect calls App\Line::weight INFERRED
    }
}
