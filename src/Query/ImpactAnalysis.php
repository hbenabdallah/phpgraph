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
 * - A method reading a property the changed method writes depends on it (`reads_state_of`): its callers are listed as
 *   possibly affected through the state, not followed further; the tests calling the reading methods themselves are
 *   listed apart.
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

    /** @var array<string, array<string, true>> */
    private array $scopes = [];

    /**
     * Nodes the walk for routes may visit: enough for a project of thousands of classes, bounded all the same.
     */
    private const MAX_ENTRY_WALK = 20000;

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
        /** @var array<string, array<string, true>> $scope node => the classes holding the tagged members on its way */
        $scope = [];
        /** @var array<string, string> $parent node => the node it was reached from */
        $parent = [];
        /** @var array<string, true> $dispatched nodes reached by a call through an interface or a parent method */
        $dispatched = [];
        /** @var array<string, array{Edge, string}> $routes route => [its edge to the handler, the handler] */
        $routes = [];
        $frontier = $changed->kind->isClassLike() ? [$changed->id, ...$this->methods($changed->id)] : [$changed->id];
        foreach ([$changed->id, ...$frontier] as $id) {
            $confidence[$id] = Confidence::Extracted;
            // A changed rule is in the scope of the lists holding it from the start.
            $own = $this->scopeOf($this->classOf($id));
            if ($own !== []) {
                $scope[$id] = $own;
            }
        }

        /** @var array<string, ImpactedClass> $classes */
        $classes = [];
        /** @var array<string, array<string, Edge>> $sites class => its edges to what the change reaches */
        $sites = [];
        $truncated = false;
        $reach = function (string $dependent, Edge $edge, Confidence $reached, int $depth, bool $followed, ?string $through, bool $compares = false) use (&$classes, &$viaState, $root): ?bool {
            $class = $this->classOf($dependent);
            // Reached through the state first, then by a call: it is a caller (UpdateEstimate::handle() calling
            // notifyContextViolation()), whatever the order of the walk.
            if (isset($classes[$class]) && $classes[$class]->throughState && !isset($viaState[$dependent]) && $class !== $root) {
                unset($classes[$class]);
            }
            if ($class === $root || isset($classes[$class])) {
                return true;
            }
            $file = $this->graph->node($class)?->file;
            $classes[$class] = new ImpactedClass($class, $depth, $edge, $reached, $file !== null && TestFiles::isTest($file), $followed, $through, isset($viaState[$dependent]), [], [], $compares, $dependent);

            return null;
        };
        // What every walk does on a step: the scope of the injected lists on the way, the parent for the route
        // chains, and the routes met, kept for the end.
        /** @var array<string, int> $stateHops node => steps taken through the state outside the changed class */
        $stateHops = [];
        /** @var array<string, true> $stateParent nodes whose parent is a step through the state */
        $stateParent = [];
        $step = function (string $id, string $dependent, Edge $edge, ?string $through, bool $state = false) use (&$scope, &$parent, &$stateParent, &$dispatched, &$routes): void {
            // The chain of a route shows the calls: a parent through the state gives way to a call.
            if (!isset($parent[$dependent]) || (isset($stateParent[$dependent]) && !$state)) {
                $parent[$dependent] = $id;
                if ($state) {
                    $stateParent[$dependent] = true;
                } else {
                    unset($stateParent[$dependent]);
                }
            }
            if ($through !== null) {
                $dispatched[$dependent] = true;
            }
            $inherited = $scope[$id] ?? null;
            $own = $this->scopeOf($this->classOf($dependent));
            $scope[$dependent] = ($scope[$dependent] ?? []) + ($inherited ?? []) + $own;
            if ($scope[$dependent] === []) {
                unset($scope[$dependent]);
            }
            if ($this->graph->node($dependent)?->kind === NodeKind::Route) {
                $routes[$dependent] ??= [$edge, $id];
            }
        };

        for ($depth = 1; $depth <= $maxDepth && $frontier !== []; ++$depth) {
            $next = [];
            foreach ($frontier as $id) {
                foreach ($this->dependents($id) as [$dependent, $edge, $edgeConfidence, $followed, $through]) {
                    if (!$this->admits($id, $dependent, $scope[$id] ?? null)) {
                        continue;
                    }
                    // Every call site, not only the first that reaches the class.
                    $sites[$this->classOf($dependent)][$edge->key()] = $edge;
                    // Reached through the state first (executeRules() reads all()), then by a call: it is a caller,
                    // followed as such, whatever the order of the walk.
                    if (isset($confidence[$dependent], $viaState[$dependent]) && !isset($viaState[$id]) && $edge->relation !== Relation::ReadsStateOf
                        && $followed && !$this->isInherited($id, $dependent)) {
                        unset($viaState[$dependent]);
                        $step($id, $dependent, $edge, $through);
                        $reach($dependent, $edge, $confidence[$dependent], $depth, true, $through);
                        $next[] = $dependent;
                        continue;
                    }
                    if (isset($confidence[$dependent]) || str_starts_with($dependent, 'file:') || !$this->graph->hasNode($dependent)) {
                        continue;
                    }
                    if (\count($classes) >= $limit && !isset($classes[$this->classOf($dependent)])) {
                        $truncated = true;
                        break 3;
                    }

                    $reached = $this->weakest($confidence[$id], $this->listHop($id, $dependent, $edgeConfidence));
                    // Reading the state before and after a call (count($n->all()) twice) depends on what the change
                    // appends: ranked with the readers filtering on it.
                    $compares = isset($viaState[$id]) && $edge->relation === Relation::Calls && \count($edge->lines()) >= 2;
                    if ($compares) {
                        $reached = Confidence::Inferred;
                    }
                    $confidence[$dependent] = $reached;
                    $step($id, $dependent, $edge, $through, isset($viaState[$id]) || $edge->relation === Relation::ReadsStateOf);
                    // Through the state, one step further for the readers filtering on what it writes (INFERRED):
                    // DomainTreeWalker::descend() reads the frozen paths, its validators are where it shows.
                    $stateHops[$dependent] = ($stateHops[$id] ?? 0) + (isset($viaState[$id]) && $this->classOf($dependent) !== $root ? 1 : 0);
                    if (isset($routes[$dependent])) {
                        continue;
                    }
                    if (isset($viaState[$id]) || $edge->relation === Relation::ReadsStateOf) {
                        $viaState[$dependent] = true;
                    }
                    // Code inherited from an ancestor (a template method calling the changed one): its callers mostly
                    // call it on other subclasses, which the graph cannot tell apart.
                    $inherited = $this->isInherited((string) $id, $dependent);
                    $followed = $followed && !$inherited;
                    $reach($dependent, $edge, $reached, $depth, $followed, $through, $compares);
                    if (!$followed) {
                        // A service receiving the change among others: its tests. Inherited code: none, they test the other subclasses.
                        $stopped[$dependent] = !$inherited;
                    }
                    // Through the state: the callers of a method reading it (hasErrors()) are listed as possibly affected,
                    // not followed, since most of them never see what the change records. Their tests are still looked for.
                    $stateFollowed = !isset($viaState[$dependent]) || $this->classOf($dependent) === $root
                        || ($stateHops[$dependent] < 2 && $reached === Confidence::Inferred);
                    if ($followed && $stateFollowed) {
                        $methods = $this->wholeClass($this->injectedInto($dependent), $reached, $confidence);
                        foreach ($methods as $method) {
                            $step($dependent, $method, $edge, null);
                            if (isset($viaState[$dependent])) {
                                $viaState[$method] = true;
                            }
                        }
                        array_push($next, $dependent, ...$methods);
                    }
                }
            }
            $frontier = $next;
        }

        // Readers filtering on what it writes (INFERRED): their callers one step further, whatever the depth, since that
        // is where the state shows (DomainTreeWalker::walk() called by the validators).
        foreach ($classes as $class => $impacted) {
            if (!$impacted->throughState || $impacted->confidence !== Confidence::Inferred || ($stateHops[$impacted->node ?? ''] ?? 0) !== 1) {
                continue;
            }
            foreach (array_keys($confidence) as $node) {
                if ($this->classOf((string) $node) !== $class) {
                    continue;
                }
                foreach ($this->dependents((string) $node) as [$dependent, $edge, $edgeConfidence]) {
                    if ($edge->relation !== Relation::Calls || isset($confidence[$dependent]) || $this->isTest($dependent)) {
                        continue;
                    }
                    $confidence[$dependent] = $this->weakest(Confidence::Inferred, $edgeConfidence);
                    $viaState[$dependent] = true;
                    $stopped[$dependent] = true;
                    $step((string) $node, $dependent, $edge, null, true);
                    $sites[$this->classOf($dependent)][$edge->key()] = $edge;
                    $reach($dependent, $edge, $confidence[$dependent], $impacted->depth + 1, false, null);
                }
            }
        }

        // The routes reaching the change, without the depth limit: the entry points an agent has to check are often
        // eight calls away (route, processor, use case, pipeline, validator, rule...). Only the callers are followed,
        // within the scope of the injected lists on the way; nothing found here is listed but the routes.
        $this->entryPoints($confidence, $stopped, $viaState, $scope, $step, $routes);

        // Test code, without the depth limit: tests reach the change through their helpers.
        // Not from the callers reached through the state: their tests are about everything else they do.
        $frontier = array_keys(array_filter(
            $confidence,
            fn (string $id): bool => ($stopped[$id] ?? true) === true && !(isset($viaState[$id]) && $this->classOf($id) !== $root)
                && $this->graph->node($id)?->kind !== NodeKind::Route,
            \ARRAY_FILTER_USE_KEY,
        ));
        $tests = 0;
        for ($depth = $maxDepth + 1; $frontier !== [] && $tests < self::MAX_TESTS; ++$depth) {
            $next = [];
            foreach ($frontier as $id) {
                foreach ($this->dependents((string) $id) as [$dependent, $edge, $edgeConfidence, $followed, $through]) {
                    if (!$this->admits((string) $id, $dependent, $scope[$id] ?? null)) {
                        continue;
                    }
                    if ($this->isTest($dependent)) {
                        $sites[$this->classOf($dependent)][$edge->key()] = $edge;
                    }
                    if (isset($confidence[$dependent]) || !$this->isTest($dependent)) {
                        continue;
                    }
                    // The tests of a node not followed, not the tests reaching it through its other users.
                    $followed = $followed && !isset($stopped[$id]);
                    $reached = $this->weakest($confidence[$id], $edgeConfidence);
                    $confidence[$dependent] = $reached;
                    $step((string) $id, $dependent, $edge, $through);
                    if (isset($viaState[$id]) || $edge->relation === Relation::ReadsStateOf) {
                        $viaState[$dependent] = true;
                    }
                    // Code inherited from an ancestor (a template method calling the changed one): its callers mostly
                    // call it on other subclasses, which the graph cannot tell apart.
                    $inherited = $this->isInherited((string) $id, $dependent);
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

        $reachedClasses = [];
        foreach (array_keys($confidence) as $id) {
            $reachedClasses[$this->classOf((string) $id)] = true;
        }
        foreach ($routes as $route => [$edge, $handler]) {
            if (!$this->servesTheChange((string) $route, $handler, isset($dispatched[$handler]), $reachedClasses)) {
                continue;
            }
            $chain = [];
            for ($node = $handler; $node !== null && \count($chain) < 30; $node = $parent[$node] ?? null) {
                $chain[] = $node;
                if (isset($confidence[$node]) && $node === $changed->id) {
                    break;
                }
            }
            unset($classes[(string) $route]);
            $classes[(string) $route] = new ImpactedClass((string) $route, \count($chain), $edge, $confidence[$route] ?? Confidence::Ambiguous, false, false, null, false, [], $chain);
        }

        $withSites = [];
        foreach ($classes as $class => $impacted) {
            $edges = array_values($sites[$class] ?? []);
            usort($edges, static fn (Edge $a, Edge $b): int => [$a->source, $a->target] <=> [$b->source, $b->target]);
            $chain = $impacted->chain;
            if ($chain === [] && $impacted->node !== null) {
                for ($node = $impacted->node; $node !== null && \count($chain) < 30; $node = $parent[$node] ?? null) {
                    $chain[] = $node;
                    if ($node === $changed->id) {
                        break;
                    }
                }
            }
            $withSites[] = new ImpactedClass($impacted->class, $impacted->depth, $impacted->edge, $impacted->confidence, $impacted->isTest, $impacted->followed, $impacted->through, $impacted->throughState, $edges, $chain, $impacted->comparesState, $impacted->node);
        }

        return new Impact($changed, $maxDepth, $withSites, $truncated);
    }

    /**
     * Walks the callers up from what the change reached, without depth limit, for the routes only.
     *
     * @param array<string, Confidence>          $confidence
     * @param array<string, bool>                $stopped
     * @param array<string, true>                $viaState
     * @param array<string, array<string, true>> $scope
     * @param \Closure(string, string, Edge, ?string): void $step
     * @param array<string, array{Edge, string}> $routes
     */
    private function entryPoints(array &$confidence, array $stopped, array $viaState, array &$scope, \Closure $step, array &$routes): void
    {
        $frontier = array_keys(array_filter(
            $confidence,
            fn (string $id): bool => !isset($stopped[$id]) && !(isset($viaState[$id]) && $this->classOf($id) !== $this->root)
                && !$this->isTest($id) && $this->graph->node($id)?->kind !== NodeKind::Route,
            \ARRAY_FILTER_USE_KEY,
        ));
        $visited = 0;
        while ($frontier !== [] && $visited < self::MAX_ENTRY_WALK) {
            $next = [];
            foreach ($frontier as $id) {
                $id = (string) $id;
                foreach ($this->dependents($id) as [$dependent, $edge, $edgeConfidence, $followed, $through]) {
                    if ($edge->relation === Relation::ReadsStateOf || $edge->relation === Relation::Receives
                        || str_starts_with($dependent, 'file:') || $this->isTest($dependent) || !$this->admits($id, $dependent, $scope[$id] ?? null)) {
                        continue;
                    }
                    if ($this->isInherited($id, $dependent)) {
                        // A template method of a parent class, run on an instance of this class: its callers holding
                        // one (a processor holding the use case) are the ones running the change.
                        // Linked to this method, not to the shared template: the chain and the confidence are this path's.
                        foreach ($this->callersHolding($dependent, $this->classOf($id)) as [$caller, $call]) {
                            if (!isset($confidence[$caller])) {
                                $confidence[$caller] = $this->weakest($confidence[$id] ?? Confidence::Ambiguous, Confidence::Inferred);
                                $step($id, $caller, $call, null);
                                $next[] = $caller;
                            }
                        }
                        continue;
                    }
                    $before = $scope[$dependent] ?? [];
                    if (isset($confidence[$dependent])) {
                        // Reached again from another scope: the scope grows, and the walk goes on from there.
                        $step($id, $dependent, $edge, $through);
                        if (($scope[$dependent] ?? []) !== $before && !isset($routes[$dependent]) && !isset($stopped[$dependent]) && $followed && !$this->isInherited($id, $dependent)) {
                            $next[] = $dependent;
                        }
                        continue;
                    }
                    ++$visited;
                    $confidence[$dependent] = $this->weakest($confidence[$id] ?? Confidence::Ambiguous, $this->listHop($id, $dependent, $edgeConfidence));
                    $step($id, $dependent, $edge, $through);
                    if (isset($routes[$dependent]) || !$followed) {
                        continue;
                    }
                    $next[] = $dependent;
                    foreach ($this->wholeClass($this->injectedInto($dependent), $confidence[$dependent], $confidence) as $method) {
                        $step($dependent, $method, $edge, null);
                        $next[] = $method;
                    }
                }
            }
            $frontier = array_values(array_unique($next));
        }
    }

    /**
     * The callers of an inherited method whose class holds an instance of $class: by its constructor, a property
     * type, or an injection (`receives`, a locator included).
     *
     * @return list<array{string, Edge}>
     */
    private function callersHolding(string $method, string $class): array
    {
        $holders = [];
        foreach ($this->graph->incident($class) as $item) {
            $relation = $item['edge']->relation;
            if (!$item['forward'] && ($relation === Relation::References || $relation === Relation::Receives)) {
                $holders[$this->classOf($item['other'])] = true;
            }
        }

        $callers = [];
        foreach ($this->graph->incident($method) as $item) {
            if (!$item['forward'] && $item['edge']->relation === Relation::Calls && isset($holders[$this->classOf($item['other'])])) {
                $callers[] = [$item['other'], $item['edge']];
            }
        }

        return $callers;
    }

    /**
     * Injected into the constructor, the change is used by the whole class: a processor holding a use case calls it
     * from process().
     */
    private function injectedInto(string $dependent): string
    {
        return str_ends_with(strtolower($dependent), '::__construct') ? $this->classOf($dependent) : $dependent;
    }

    /**
     * The classes holding a tagged member: those injecting its tag, and those holding them in turn by service id
     * (`receives`, INFERRED). Not the locators of a whole tag: holding every member, they narrow nothing.
     *
     * @return array<string, true>
     */
    private function scopeOf(string $class): array
    {
        if (isset($this->scopes[$class])) {
            return $this->scopes[$class];
        }

        $holders = [];
        $tagged = false;
        foreach ($this->graph->incident($class) as $item) {
            $edge = $item['edge'];
            if (!$item['forward'] && $edge->relation === Relation::Receives && !str_starts_with($edge->via(), 'tagged_locator')) {
                $holders[$item['other']] = true;
                $tagged = $tagged || str_starts_with($edge->via(), 'tagged_iterator');
            }
        }

        return $this->scopes[$class] = $tagged ? $holders : [];
    }

    /**
     * Inside the scope of an injected list, a method of a class shared by several contexts (a validator, a pipeline,
     * held by other holders of the scope) leads only to the callers in the scope: the rules of the reservation
     * context reach the reservation use case, not every use case.
     *
     * @param array<string, true>|null $scope
     */
    private function admits(string $id, string $dependent, ?array $scope): bool
    {
        $class = $this->classOf($id);
        $other = $this->classOf($dependent);
        if ($scope === null || $other === $class || !isset($scope[$class])) {
            return true;
        }
        foreach ($this->graph->incident($class) as $item) {
            if (!$item['forward'] && $item['edge']->relation === Relation::Receives && isset($scope[$item['other']])) {
                // Shared: held by another holder of the scope.
                return isset($scope[$other]) || $this->graph->node($dependent)?->kind === NodeKind::Route;
            }
        }

        return true;
    }

    /**
     * A hop from a tagged member to the class receiving its list is INFERRED, and AMBIGUOUS when nothing narrows the
     * list to the services holding it (no holder by service id).
     */
    private function listHop(string $id, string $dependent, Confidence $confidence): Confidence
    {
        $holders = $this->scopeOf($this->classOf($id));
        if (!isset($holders[$this->classOf($dependent)])) {
            return $confidence;
        }
        foreach ($this->graph->incident($this->classOf($id)) as $item) {
            if (!$item['forward'] && $item['edge']->relation === Relation::Receives && $item['edge']->confidence === Confidence::Inferred) {
                return $this->weakest($confidence, Confidence::Inferred);
            }
        }

        return Confidence::Ambiguous;
    }

    /**
     * A route reached from its handler serves the change, unless the handler picks its work at run time: from a
     * locator of services (`tagged_locator`), or through an interface whose implementation the route's operation
     * chooses (`$payload->toQuery()`). Then only the routes naming a class the change reaches serve it: the operation
     * naming the use case or the payload.
     *
     * @param array<string, true> $reached
     */
    private function servesTheChange(string $route, string $handler, bool $dispatched, array $reached): bool
    {
        if ($this->graph->node($handler)?->kind === NodeKind::Route || !$this->isGeneric($handler, $dispatched)) {
            return true;
        }
        foreach ($this->graph->incident($route) as $item) {
            if ($item['forward'] && $item['edge']->relation === Relation::References && isset($reached[$item['other']])) {
                return true;
            }
        }

        return false;
    }

    private function isGeneric(string $handler, bool $dispatched): bool
    {
        if ($dispatched) {
            return true;
        }
        foreach ($this->graph->incident($this->classOf($handler)) as $item) {
            if ($item['forward'] && $item['edge']->relation === Relation::Receives && str_starts_with($item['edge']->via(), 'tagged_locator')) {
                return true;
            }
        }

        return false;
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
            } elseif (!$item['forward'] && $relation === Relation::HandledBy && $this->graph->node($item['other'])?->kind === NodeKind::Route) {
                // The routes a changed controller serves: the entry points affected. Listed, nothing depends on them.
                $dependents[] = [$item['other'], $item['edge'], $item['edge']->confidence, false, null];
            }
        }
        // The routes naming the class of a changed method: an API Platform operation naming its use case.
        if (str_contains($id, '::')) {
            foreach ($this->graph->incident($this->classOf($id)) as $item) {
                if (!$item['forward'] && $item['edge']->relation === Relation::References && $this->graph->node($item['other'])?->kind === NodeKind::Route) {
                    $dependents[] = [$item['other'], $item['edge'], $item['edge']->confidence, false, null];
                }
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

    /**
     * A step into code a class inherits: an ancestor of the changed class, or of the class it is reached from (a
     * template method calling `$this->handleItem()`). Its callers call it on any subclass.
     */
    private function isInherited(string $from, string $dependent): bool
    {
        $class = $this->classOf($dependent);
        if ($class === $this->root || $class === $this->classOf($from)) {
            return false;
        }

        return \in_array($class, $this->ancestorsOf($this->root), true) || \in_array($class, $this->ancestorsOf($this->classOf($from)), true);
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
