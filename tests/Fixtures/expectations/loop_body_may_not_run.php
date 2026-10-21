<?php

namespace App;

class Draft { public function publish(): void {} }
class Revision { public function publish(): void {} }

class Editor
{
    /** @param list<string> $changes */
    public function edit(array $changes): void
    {
        $document = new Draft();
        foreach ($changes as $change) {
            $document = new Revision();
        }
        $document->publish(); // @phpgraph-expect calls App\Draft::publish INFERRED @phpgraph-expect calls App\Revision::publish INFERRED
    }

    public function retry(int $attempts): void
    {
        $document = new Draft();
        while ($attempts-- > 0) {
            $document = new Revision();
        }
        $document->publish(); // @phpgraph-expect calls App\Draft::publish INFERRED @phpgraph-expect calls App\Revision::publish INFERRED
    }
}
