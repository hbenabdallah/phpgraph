<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Builder\GraphBuilder;
use PhpGraph\Graph\Relation;
use PhpGraph\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One PHP file per case in Fixtures/expectations, its expected calls written on the line of each call:
 *
 *     $shape->area(); // @phpgraph-expect calls App\Circle::area INFERRED
 *
 * A line may expect several calls (one `@phpgraph-expect` each); a line expecting none has no annotation. Every call
 * edge of the built graph is compared, line by line: a missing call and an unexpected one both fail the case. Calls
 * a method makes through a closure it runs (no line of their own) are not compared.
 *
 * A fixture whose expectations are not met yet says why in a `@phpgraph-todo` line: it runs, is reported incomplete
 * with what the graph has instead, and fails once it passes, so that the annotation goes.
 */
final class ExpectationsTest extends TestCase
{
    use TemporaryProject;

    private const DIRECTORY = __DIR__ . '/Fixtures/expectations';

    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtures(): iterable
    {
        $files = glob(self::DIRECTORY . '/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            yield basename($file, '.php') => [$file];
        }
    }

    #[DataProvider('fixtures')]
    public function testTheGraphHasTheExpectedCalls(string $fixture): void
    {
        $code = (string) file_get_contents($fixture);
        // Written as it is, not through write(): the lines of the expectations stay those of the graph.
        mkdir($this->root . '/src');
        file_put_contents($this->root . '/src/' . basename($fixture), $code);
        $graph = (new GraphBuilder())->build($this->root)->graph;

        $expected = self::expectations($code);
        $actual = [];
        foreach ($graph->edges() as $edge) {
            if ($edge->relation !== Relation::Calls) {
                continue;
            }
            foreach ($edge->lines() as $line) {
                $actual[$line][] = 'calls ' . $edge->target . ' ' . $edge->confidence->value;
            }
        }

        $differences = [];
        foreach (array_unique([...array_keys($expected), ...array_keys($actual)]) as $line) {
            $wanted = $expected[$line] ?? [];
            $found = $actual[$line] ?? [];
            foreach (array_diff($wanted, $found) as $missing) {
                $differences[$line][] = 'missing: ' . $missing;
            }
            foreach (array_diff($found, $wanted) as $unexpected) {
                $differences[$line][] = 'unexpected: ' . $unexpected;
            }
        }
        ksort($differences);
        $report = '';
        foreach ($differences as $line => $lines) {
            $report .= "\n  line " . $line . ': ' . implode('; ', $lines);
        }

        $todo = preg_match('/@phpgraph-todo\s+(.+)$/m', $code, $match) === 1 ? trim($match[1]) : null;
        if ($todo !== null) {
            self::assertNotSame('', $report, 'The expectations are met: remove @phpgraph-todo from ' . basename($fixture));
            self::markTestIncomplete($todo . $report);
        }
        self::assertSame('', $report, basename($fixture) . ':' . $report);
    }

    /**
     * @return array<int, list<string>> line => `calls Target CONFIDENCE`
     */
    private static function expectations(string $code): array
    {
        $expected = [];
        foreach (explode("\n", $code) as $index => $line) {
            preg_match_all('/@phpgraph-expect\s+(calls)\s+(\S+)\s+(EXTRACTED|INFERRED|AMBIGUOUS)/', $line, $matches, \PREG_SET_ORDER);
            foreach ($matches as $match) {
                $expected[$index + 1][] = $match[1] . ' ' . $match[2] . ' ' . $match[3];
            }
            if (str_contains($line, '@phpgraph-expect') && \count($matches) !== substr_count($line, '@phpgraph-expect')) {
                throw new \LogicException('Unreadable expectation on line ' . ($index + 1) . ': ' . trim($line));
            }
        }

        return $expected;
    }
}
