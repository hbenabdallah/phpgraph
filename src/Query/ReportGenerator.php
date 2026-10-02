<?php

declare(strict_types=1);

namespace PhpGraph\Query;

use PhpGraph\Builder\BuildResult;
use PhpGraph\Graph\Graph;
use PhpGraph\Graph\NodeKind;
use PhpGraph\Graph\Relation;
use PhpGraph\Presentation\TextPresenter;
use PhpGraph\Project\ProjectSummary;

final class ReportGenerator
{
    private const BOUNDARY_RELATIONS = [
        Relation::Extends,
        Relation::Implements,
        Relation::UsesTrait,
        Relation::Instantiates,
        Relation::References,
        Relation::Calls,
        Relation::Overrides,
    ];

    public function generate(BuildResult $result, int $boundaryDepth = 2, ?ProjectSummary $summary = null): string
    {
        $graph = $result->graph;
        $presenter = new TextPresenter(new GraphQuery($graph, $summary));

        $lines = [
            '# Graph report',
            '',
            sprintf(
                '%d files parsed, %d failed, %d nodes, %d edges, %d dependency files read for their signatures.',
                $result->filesParsed,
                \count($result->failures),
                $graph->nodeCount(),
                $graph->edgeCount(),
                $result->vendorFilesRead,
            ),
            '',
            // The overview is what the MCP tool of the same name returns: its headings move one level down here.
            preg_replace('/^#/m', '##', $presenter->overview()) ?? '',
        ];

        $application = $result->applicationCalls();
        $tests = $result->testCalls;
        $lines[] = '';
        $lines[] = '## Method calls by outcome';
        $lines[] = '';
        $lines[] = 'Test code is recognised by its path: a `tests`, `test`, `spec`, `specs`, `__tests__` or `Behat` directory, or a';
        $lines[] = '`*Test.php`, `*TestCase.php`, `*Spec.php` or `*Cest.php` file.';
        $lines[] = '';
        $lines[] = '| Call sites | Application | Tests |';
        $lines[] = '|---|---:|---:|';
        foreach ([
            'Total' => 'total',
            'Resolved from types (INFERRED)' => 'inferred',
            'Guessed from a unique method name (AMBIGUOUS)' => 'ambiguous',
            'To code outside the project' => 'outsideProject',
            'Unresolved, receiver type unknown' => 'unknownReceiver',
            '… of which the chain reaches code outside the project' => 'chainOutsideProject',
        ] as $label => $key) {
            $lines[] = sprintf('| %s | %d | %d |', $label, $application->toArray()[$key], $tests->toArray()[$key]);
        }

        $lines[] = '';
        $lines[] = '## God nodes';
        $lines[] = '';
        $lines[] = '```';
        $lines[] = $presenter->godNodes(15);
        $lines[] = '```';
        $lines[] = '';
        $lines[] = sprintf('## Cross-boundary dependencies (namespace depth %d)', $boundaryDepth);
        $lines[] = '';

        $boundaries = $this->boundaryDependencies($graph, $boundaryDepth);
        if ($boundaries === []) {
            $lines[] = 'None detected.';
        }
        foreach (\array_slice($boundaries, 0, 20, true) as $pair => $count) {
            $lines[] = sprintf('- %s: %d', $pair, $count);
        }

        if ($result->duplicates !== []) {
            $lines[] = '';
            $lines[] = '## Duplicate declarations';
            $lines[] = '';
            $lines[] = 'Only the first declaration is kept as a node; edges of the others are merged into it.';
            $lines[] = '';
            foreach ($result->duplicates as $id => $files) {
                $lines[] = sprintf('- %s: %s', $id, implode(', ', $files));
            }
        }

        if ($result->failures !== []) {
            $lines[] = '';
            $lines[] = '## Parse failures';
            $lines[] = '';
            foreach ($result->failures as $file => $message) {
                $lines[] = sprintf('- %s: %s', $file, $message);
            }
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @return array<string, int>
     */
    private function boundaryDependencies(Graph $graph, int $depth): array
    {
        $pairs = [];

        foreach ($graph->edges() as $edge) {
            if (!\in_array($edge->relation, self::BOUNDARY_RELATIONS, true)) {
                continue;
            }

            $source = $graph->node($edge->source);
            $target = $graph->node($edge->target);
            if ($source === null || $target === null || $source->kind === NodeKind::External || $target->kind === NodeKind::External) {
                continue;
            }

            $from = $this->boundary($edge->source, $depth);
            $to = $this->boundary($edge->target, $depth);
            if ($from === null || $to === null || $from === $to) {
                continue;
            }

            $key = $from . ' -> ' . $to;
            $pairs[$key] = ($pairs[$key] ?? 0) + 1;
        }

        arsort($pairs);

        return $pairs;
    }

    private function boundary(string $id, int $depth): ?string
    {
        $class = explode('::', $id)[0];
        if (str_starts_with($class, 'file:')) {
            return null;
        }

        $segments = explode('\\', $class);
        if (\count($segments) <= $depth) {
            return null;
        }

        return implode('\\', \array_slice($segments, 0, $depth));
    }
}
