<?php

declare(strict_types=1);

namespace PhpGraph\Query;

use PhpGraph\Builder\TestFiles;
use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Edge;
use PhpGraph\Graph\Graph;
use PhpGraph\Graph\NodeKind;
use PhpGraph\Graph\Relation;
use PhpGraph\Query\Result\Architecture;
use PhpGraph\Query\Result\LayerViolation;

/**
 * Checks the default dependency rules of a layered architecture (Layers::FORBIDDEN) on application code, and counts
 * dependencies between bounded contexts. Only certain dependencies count: EXTRACTED and INFERRED edges.
 */
final class LayerRules
{
    /**
     * Relations that make a class depend on another. handled_by goes from a message to its handler: the message
     * does not depend on it.
     */
    private const DEPENDENCIES = [
        Relation::Extends,
        Relation::Implements,
        Relation::UsesTrait,
        Relation::Instantiates,
        Relation::References,
        Relation::Calls,
        Relation::Dispatches,
    ];

    public function __construct(private readonly Graph $graph)
    {
    }

    public function check(): Architecture
    {
        $classes = [];
        foreach ($this->graph->nodes() as $node) {
            if ($node->kind->isClassLike() && $node->kind !== NodeKind::External && ($node->file === null || !TestFiles::isTest($node->file))) {
                $classes[$node->id] = true;
            }
        }
        $layerFirst = Layers::isLayerFirst(array_keys($classes));

        /** @var array<string, array{string, string, string, string, Edge, int}> $violations */
        $violations = [];
        $contexts = [];
        foreach ($this->graph->edges() as $edge) {
            if ($edge->confidence === Confidence::Ambiguous || !\in_array($edge->relation, self::DEPENDENCIES, true)) {
                continue;
            }

            $from = explode('::', $edge->source)[0];
            $to = explode('::', $edge->target)[0];
            if ($from === $to || !isset($classes[$from], $classes[$to])) {
                continue;
            }

            $fromLayer = Layers::ruleRole($from);
            $toLayer = Layers::ruleRole($to);
            if ($fromLayer !== null && $toLayer !== null && Layers::isForbidden($fromLayer, $toLayer)) {
                $key = $from . ' -> ' . $to;
                $known = $violations[$key] ?? null;
                $violations[$key] = [
                    $from,
                    $fromLayer,
                    $to,
                    $toLayer,
                    $known !== null && $this->rank($known[4]) >= $this->rank($edge) ? $known[4] : $edge,
                    ($known[5] ?? 0) + 1,
                ];
            }

            $fromContext = Layers::context($from, $layerFirst);
            $toContext = Layers::context($to, $layerFirst);
            if ($fromContext !== null && $toContext !== null && $fromContext !== $toContext) {
                $pair = $fromContext . ' -> ' . $toContext;
                $contexts[$pair] = ($contexts[$pair] ?? 0) + 1;
            }
        }

        ksort($violations);
        $list = array_values(array_map(static fn (array $entry): LayerViolation => new LayerViolation(...$entry), $violations));
        arsort($contexts);

        return new Architecture($list, $contexts, $layerFirst);
    }

    private function rank(Edge $edge): int
    {
        return $edge->confidence === Confidence::Extracted ? 1 : 0;
    }
}
