<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Extractor\TypeExpr;
use PHPUnit\Framework\TestCase;

final class TypeExprTest extends TestCase
{
    public function testTheCallsOfAChainShareTheLinksTheyHaveMappedOnce(): void
    {
        $query = TypeExpr::returnOf(TypeExpr::named('App\Repository'), 'query');
        $where = TypeExpr::returnOf($query, 'where');
        /** @var \WeakMap<TypeExpr, TypeExpr> $mapped */
        $mapped = new \WeakMap();
        $names = 0;
        $map = static function (string $name) use (&$names): string {
            ++$names;

            return 'service@' . $name;
        };

        $first = $query->map($map, $mapped);
        $second = $where->map($map, $mapped);

        self::assertSame($first, $second->receiver, 'the lower link of the chain is mapped once, for both calls');
        self::assertSame(1, $names);
        self::assertSame('service@app\repository->query()->where()', $second->key());
    }
}
