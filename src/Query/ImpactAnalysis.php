<?php

declare(strict_types=1);

namespace PhpGraph\Query;

use PhpGraph\Builder\TestFiles;
use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Edge;
use PhpGraph\Graph\Graph;
use PhpGraph\Graph\Node;
use PhpGraph\Graph\NodeKind;
use PhpGraph\Graph\Relation;
use PhpGraph\Query\Result\Impact;
use PhpGraph\Query\Result\ImpactedClass;

/**
 * What may break when a class or a method changes: everything that depends on it, directly or through others.
 *
 * Walks method by method, so a class is reached only through the methods that really use the change; a class that
 * depends on the changed one as a whole (subclass, property type) has all its methods followed.
 *
 * - A method is also reached through the interface or parent method it implements: the callers of
 *   `OrderRepository::save` may run `DbalOrderRepository::save` (INFERRED, the call is resolved at runtime).
 * - A method reading a property the changed method writes depends on it (`reads_state_of`): its callers are reached
 *   too, marked as reached through the state.
 * - A service receiving the changed one among others (a tagged collection) is listed, not followed: its users do not
 *   depend on that member.
 * - Test code is followed without the depth limit, through test helpers (fakers, fixtures): a test using a faker that
 *   builds the changed object must run, though it never names the change.
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
        Relation::Receives,
        Relation::ReadsStateOf,
    ];

    private const MAX_TESTS = 300;

    public function __construct(private readonly Graph $graph)
    {
    }

    /** @var array<string, list<string>> */
    private array $ancestors = [];

    private string $root = '';

    public function of(Node $changed, int $maxDepth = 3, int $limit = 200): Impact
    {
        $root = $this->classOf($changed->id);
        $this->root = $root;
        $confidence = [];
        /** @var array<string, bool> $stopped reached but not followed => whether their own tests are added */
        $stopped = [];
        /** @var array<string, true> $viaState reached through a reads_state_of edge */
        $viaState = [];
        $frontier = $changed->kind->isClassLike() ? [$changed->id, ...$this->methods($changed->id)] : [$changed->id];
        foreach ([$changed->id, ...$frontier] as $id) {
            $confidence[$id] = Confidence::Extracted;
        }

        /** @var array<string, ImpactedClass> $classes */
        $classes = [];
        $truncated = false;
        $reach = function (string $dependent, Edge $edge, Confidence $reached, int $depth, bool $followed, ?string $through) use (&$classes, &$viaState, $root): ?bool {
            $class = $this->classOf($dependent);
            if ($class === $root || isset($classes[$class])) {
                return true;
            }
            $file = $this->graph->node($class)?->file;
            $classes[$class] = new ImpactedClass($class, $depth, $edge, $reached, $file !== null && TestFiles::isTest($file), $followed, $through, isset($viaState[$dependent]));

            return null;
        };

        for ($depth = 1; $depth <= $maxDepth && $frontier !== []; ++$depth) {
            $next = [];
            foreach ($frontier as $id) {
                foreach ($this->dependents($id) as [$dependent, $edge, $edgeConfidence, $followed, $through]) {
                    if (isset($confidence[$dependent]) || str_starts_with($dependent, 'file:') || !$this->graph->hasNode($dependent)) {
                        continue;
                    }
                    if (\count($classes) >= $limit && !isset($classes[$this->classOf($dependent)])) {
                        $truncated = true;
                        break 3;
                    }

                    $reached = $this->weakest($confidence[$id], $edgeConfidence);
                    $confidence[$dependent] = $reached;
                    if (isset($viaState[$id]) || $edge->relation === Relation::ReadsStateOf) {
                        $viaState[$dependent] = true;
                    }
                    // Code inherited from an ancestor (a template method calling the changed one): its callers mostly
                    // call it on other subclasses, which the graph cannot tell apart.
                    $inherited = $this->isAncestorOfRoot($this->classOf($dependent));
                    $followed = $followed && !$inherited;
                    $reach($dependent, $edge, $reached, $depth, $followed, $through);
                    if (!$followed) {
                        // A service receiving the change among others: its tests. Inherited code: none, they test the other subclasses.
                        $stopped[$dependent] = !$inherited;
                    }
                    if ($followed) {
                        $methods = $this->wholeClass($dependent, $reached, $confidence);
                        foreach (isset($viaState[$dependent]) ? $methods : [] as $method) {
                            $viaState[$method] = true;
                        }
                        array_push($next, $dependent, ...$methods);
                    }
                }
            }
            $frontier = $next;
        }

        // Test code, without the depth limit: tests reach the change through their helpers.
        $frontier = array_keys(array_filter($confidence, static fn (string $id): bool => ($stopped[$id] ?? true) === true, \ARRAY_FILTER_USE_KEY));
        $tests = 0;
        for ($depth = $maxDepth + 1; $frontier !== [] && $tests < self::MAX_TESTS; ++$depth) {
            $next = [];
            foreach ($frontier as $id) {
                foreach ($this->dependents((string) $id) as [$dependent, $edge, $edgeConfidence, $followed, $through]) {
                    if (isset($confidence[$dependent]) || !$this->isTest($dependent)) {
                        continue;
                    }
                    // The tests of a node not followed, not the tests reaching it through its other users.
                    $followed = $followed && !isset($stopped[$id]);
                    $reached = $this->weakest($confidence[$id], $edgeConfidence);
                    $confidence[$dependent] = $reached;
                    if (isset($viaState[$id]) || $edge->relation === Relation::ReadsStateOf) {
                        $viaState[$dependent] = true;
                    }
                    // Code inherited from an ancestor (a template method calling the changed one): its callers mostly
                    // call it on other subclasses, which the graph cannot tell apart.
                    $inherited = $this->isAncestorOfRoot($this->classOf($dependent));
                    $followed = $followed && !$inherited;
                    if ($reach($dependent, $edge, $reached, $depth, $followed, $through) === null) {
                        ++$tests;
                    }
                    if (!$followed) {
                        $stopped[$dependent] = !$inherited;
                    }
                    if ($followed) {
                        $methods = $this->wholeClass($dependent, $reached, $confidence);
                        foreach (isset($viaState[$dependent]) ? $methods : [] as $method) {
                            $viaState[$method] = true;
                        }
                        array_push($next, $dependent, ...$methods);
                    }
                }
            }
            $frontier = $next;
        }

        return new Impact($changed, $maxDepth, array_values($classes), $truncated);
    }

    /**
     * A subclass, or a class holding the changed type in a property: all of it may be affected.
     *
     * @param array<string, Confidence> $confidence
     *
     * @return list<string> its methods not reached yet
     */
    private function wholeClass(string $dependent, Confidence $reached, array &$confidence): array
    {
        if ($this->graph->node($dependent)?->kind->isClassLike() !== true) {
            return [];
        }

        $methods = [];
        foreach ($this->methods($dependent) as $method) {
            if (!isset($confidence[$method])) {
                $confidence[$method] = $reached;
                $methods[] = $method;
            }
        }

        return $methods;
    }

    /**
     * @return list<array{string, Edge, Confidence, bool, ?string}> dependent, edge, its confidence, whether to follow
     *                                                               it, the method it calls in place of $id
     */
    private function dependents(string $id): array
    {
        $dependents = [];
        $isRoute = $this->graph->node($id)?->kind === NodeKind::Route;
        foreach ($this->graph->incident($id) as $item) {
            $relation = $item['edge']->relation;
            if (!$item['forward'] && \in_array($relation, self::DEPENDENTS, true)) {
                $dependents[] = [$item['other'], $item['edge'], $item['edge']->confidence, $relation !== Relation::Receives, null];
            } elseif ($item['forward'] && $relation === Relation::HandledBy && !$isRoute) {
                // The handlers of a changed message are affected too; the controller of a route is not affected by it.
                $dependents[] = [$item['other'], $item['edge'], $item['edge']->confidence, true, null];
            }
        }

        // Called through the interface or parent method it implements: the call may run this one. Not a constructor
        // (parent::__construct() runs the parent's), nor a call from another subclass, which runs its own.
        foreach (str_ends_with(strtolower($id), '::__construct') ? [] : $this->implemented($id) as $parent) {
            $parentClass = $this->classOf($parent);
            foreach ($this->graph->incident($parent) as $item) {
                if (!$item['forward'] && $item['edge']->relation === Relation::Calls) {
                    $caller = $this->classOf($item['other']);
                    if ($caller !== $this->root && $caller !== $parentClass && \in_array($parentClass, $this->ancestorsOf($caller), true) && !\in_array($this->root, $this->ancestorsOf($caller), true)) {
                        continue;
                    }
                    $dependents[] = [$item['other'], $item['edge'], Confidence::Inferred, true, $parent];
                }
            }
        }

        return $dependents;
    }

    /**
     * The methods a method implements or overrides, up the hierarchy.
     *
     * @return list<string>
     */
    private function implemented(string $method): array
    {
        $parents = [];
        for ($queue = [$method]; $queue !== [];) {
            foreach ($this->graph->incident((string) array_shift($queue)) as $item) {
                if ($item['forward'] && $item['edge']->relation === Relation::Overrides && !\in_array($item['other'], $parents, true)) {
                    $parents[] = $item['other'];
                    $queue[] = $item['other'];
                }
            }
        }

        return $parents;
    }

    private function isAncestorOfRoot(string $class): bool
    {
        return $class !== $this->root && \in_array($class, $this->ancestorsOf($this->root), true);
    }

    /**
     * The classes and interfaces a class extends or implements, up the hierarchy, itself included.
     *
     * @return list<string>
     */
    private function ancestorsOf(string $class): array
    {
        if (isset($this->ancestors[$class])) {
            return $this->ancestors[$class];
        }

        $ancestors = [$class];
        for ($index = 0; $index < \count($ancestors); ++$index) {
            foreach ($this->graph->incident($ancestors[$index]) as $item) {
                if ($item['forward'] && \in_array($item['edge']->relation, [Relation::Extends, Relation::Implements], true) && !\in_array($item['other'], $ancestors, true)) {
                    $ancestors[] = $item['other'];
                }
            }
        }

        return $this->ancestors[$class] = $ancestors;
    }

    private function isTest(string $id): bool
    {
        $file = $this->graph->node($this->classOf($id))->file ?? $this->graph->node($id)?->file;

        return $file !== null && TestFiles::isTest($file);
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
