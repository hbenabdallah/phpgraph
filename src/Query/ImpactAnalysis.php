<?php

declare(strict_types=1);

namespace PhpGraph\Query;

use PhpGraph\Builder\TestFiles;
use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Graph;
use PhpGraph\Graph\Node;
use PhpGraph\Graph\Relation;
use PhpGraph\Query\Result\Impact;
use PhpGraph\Query\Result\ImpactedClass;

/**
 * What may break when a class or a method changes: everything that depends on it, directly or through others.
 *
 * Walks method by method, so a class is reached only through the methods that really use the change; a class that
 * depends on the changed one as a whole (subclass, property type) has all its methods followed.
 */
final class ImpactAnalysis
{
    /**
     * Incoming relations that make their source depend on the target.
     */
    private const DEPENDENTS = [
        Relation::Calls,
        Relation::References,
        Relation::Instantiates,
        Relation::Extends,
        Relation::Implements,
        Relation::UsesTrait,
        Relation::Overrides,
        Relation::Dispatches,
    ];

    public function __construct(private readonly Graph $graph)
    {
    }

    public function of(Node $changed, int $maxDepth = 3, int $limit = 200): Impact
    {
        $root = $this->classOf($changed->id);
        $confidence = [$changed->id => Confidence::Extracted];
        $frontier = $changed->kind->isClassLike() ? [$changed->id, ...$this->methods($changed->id)] : [$changed->id];
        foreach ($frontier as $id) {
            $confidence[$id] = Confidence::Extracted;
        }

        /** @var array<string, ImpactedClass> $classes */
        $classes = [];
        $truncated = false;

        for ($depth = 1; $depth <= $maxDepth && $frontier !== []; ++$depth) {
            $next = [];
            foreach ($frontier as $id) {
                foreach ($this->dependents($id) as [$dependent, $edge]) {
                    if (isset($confidence[$dependent]) || str_starts_with($dependent, 'file:') || !$this->graph->hasNode($dependent)) {
                        continue;
                    }

                    $reached = $this->weakest($confidence[$id], $edge->confidence);
                    $confidence[$dependent] = $reached;
                    $next[] = $dependent;

                    $class = $this->classOf($dependent);
                    if ($class !== $root && !isset($classes[$class])) {
                        if (\count($classes) >= $limit) {
                            $truncated = true;
                            break 3;
                        }
                        $file = $this->graph->node($class)?->file;
                        $classes[$class] = new ImpactedClass($class, $depth, $edge, $reached, $file !== null && TestFiles::isTest($file));
                    }

                    // A subclass, or a class holding the changed type in a property: all of it may be affected.
                    if ($this->graph->node($dependent)?->kind->isClassLike() === true) {
                        foreach ($this->methods($dependent) as $method) {
                            if (!isset($confidence[$method])) {
                                $confidence[$method] = $reached;
                                $next[] = $method;
                            }
                        }
                    }
                }
            }
            $frontier = $next;
        }

        return new Impact($changed, $maxDepth, array_values($classes), $truncated);
    }

    /**
     * @return list<array{string, \PhpGraph\Graph\Edge}>
     */
    private function dependents(string $id): array
    {
        $dependents = [];
        foreach ($this->graph->incident($id) as $item) {
            $relation = $item['edge']->relation;
            if (!$item['forward'] && \in_array($relation, self::DEPENDENTS, true)) {
                $dependents[] = [$item['other'], $item['edge']];
            } elseif ($item['forward'] && $relation === Relation::HandledBy) {
                // The handlers of a changed message are affected too.
                $dependents[] = [$item['other'], $item['edge']];
            }
        }

        return $dependents;
    }

    /**
     * @return list<string>
     */
    private function methods(string $class): array
    {
        $methods = [];
        foreach ($this->graph->incident($class) as $item) {
            if ($item['forward'] && $item['edge']->relation === Relation::HasMethod) {
                $methods[] = $item['other'];
            }
        }

        return $methods;
    }

    private function classOf(string $id): string
    {
        return explode('::', $id)[0];
    }

    private function weakest(Confidence $a, Confidence $b): Confidence
    {
        foreach ([Confidence::Ambiguous, Confidence::Inferred] as $confidence) {
            if ($a === $confidence || $b === $confidence) {
                return $confidence;
            }
        }

        return Confidence::Extracted;
    }
}
