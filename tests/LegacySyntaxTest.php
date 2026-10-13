<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Builder\GraphBuilder;
use PhpGraph\Extractor\PhpCodeParser;
use PhpGraph\Graph\Graph;
use PhpGraph\Graph\Relation;
use PhpParser\Error;
use PhpParser\Node\Expr;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PHPUnit\Framework\TestCase;

/**
 * PHP 4 and 5 syntax removed since, read on the original source: the lines of the graph stay those of the files.
 */
final class LegacySyntaxTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/Fixtures/legacy-php';

    public function testEveryFixtureIsParsed(): void
    {
        $result = (new GraphBuilder(readVendor: false))->build(self::FIXTURES);

        self::assertSame([], $result->failures);
        self::assertSame(4, $result->filesParsed);
    }

    public function testCurlyOffsetAccessIsReadAsAnOffset(): void
    {
        $code = (string) file_get_contents(self::FIXTURES . '/CurlyOffset.php');
        $fetches = (new NodeFinder())->findInstanceOf((new PhpCodeParser())->parse($code), Expr\ArrayDimFetch::class);

        self::assertCount(3, $fetches);
        $offset = $fetches[0];
        self::assertSame("\$session['TBLDROIT2']{2}", substr($code, $offset->getStartFilePos(), $offset->getEndFilePos() - $offset->getStartFilePos() + 1), 'Columns of the original source');
        self::assertSame(7, $offset->getStartLine());
        self::assertSame([8], $this->lines('Rights::check', 'Rights::allow'));
        self::assertSame(12, $this->graph()->node('Rights::allow')?->line);
    }

    public function testBracesOfAStringOrABlockAreNotOffsets(): void
    {
        $code = (string) file_get_contents(self::FIXTURES . '/CurlyOffset.php');
        $string = (new NodeFinder())->findFirstInstanceOf((new PhpCodeParser())->parse($code), \PhpParser\Node\Scalar\InterpolatedString::class);

        self::assertNotNull($string);
        self::assertSame([Expr\Variable::class, InterpolatedStringPart::class, Expr\Variable::class], array_map(static fn (object $part): string => $part::class, $string->parts));

        $modern = (new PhpCodeParser())->parse((string) file_get_contents(self::FIXTURES . '/Modern.php'));
        $greeter = (new NodeFinder())->findFirst($modern, static fn (\PhpParser\Node $node): bool => $node instanceof Stmt\Class_ && $node->name?->name === 'Greeter');
        self::assertInstanceOf(Stmt\Class_::class, $greeter);
        self::assertCount(1, $greeter->attrGroups, 'Modern code is read by the newest grammar: an attribute is not a comment');
        self::assertSame([16, 20], $this->lines('App\Greeter::greet', 'App\Greeter::name'));
    }

    public function testNewAssignedByReference(): void
    {
        self::assertTrue($this->graph()->hasNode('Handler'));
        self::assertSame([10], $this->lines('Router::Router', 'Handler::run'), 'The variable holds the Handler created on line 9');
    }

    public function testInvalidOctalLiteral(): void
    {
        self::assertSame([8], $this->lines('Calendar::august', 'Calendar::format'));
    }

    public function testAFileNoGrammarReadsKeepsTheErrorOfTheNewest(): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessage("Syntax error, unexpected '{'");

        (new PhpCodeParser())->parse('<?php enum Status { case On; } $a = $b{0};');
    }

    private static ?Graph $graph = null;

    private function graph(): Graph
    {
        return self::$graph ??= (new GraphBuilder(readVendor: false))->build(self::FIXTURES)->graph;
    }

    /**
     * @return list<int> the lines of the calls from one method to another
     */
    private function lines(string $source, string $target): array
    {
        foreach ($this->graph()->edges() as $edge) {
            if ($edge->source === $source && $edge->target === $target && $edge->relation === Relation::Calls) {
                return $edge->lines();
            }
        }

        return [];
    }
}
