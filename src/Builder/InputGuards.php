<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

use PhpGraph\Extractor\TypeExpr;
use PhpGraph\Graph\Graph;
use PhpGraph\Graph\NodeKind;
use PhpGraph\Graph\Relation;

/**
 * Whether a member of a tagged collection can run for what a service gives the collection's holder. A rule declaring
 * what it accepts (`supports(object $input): bool { return $input instanceof CreateEstimateQuery; }`) never runs for a
 * use case passing another query to the shared pipeline: the preview use case does not run the rules of creation.
 *
 * The input is the classes of the arguments the use case passes to the service it holds by id, with the classes their
 * properties hold, recursively (a validator walking the input meets its lines). An argument typed by an interface may
 * be any project class implementing it and the types of the parameters it is passed on to: the rules running for
 * some of them only are unresolved. A guard is trusted only when the
 * project calls it on the members (`RuleExecution::canRun()` calling `ContextRuleInterface::supports()`).
 *
 * Conservative: a guard or an input it cannot read keeps the member, as unresolved.
 */
final class InputGuards
{
    public const KEEP = 'keep';

    public const DROP = 'drop';

    public const UNRESOLVED = 'unresolved';

    /**
     * How deep the properties of an input are followed, and how many classes at most.
     */
    private const MAX_DEPTH = 4;

    private const MAX_CLASSES = 300;

    /** @var array<string, array{method: string, types: list<string>|true|null}|null> */
    private array $guardOf = [];

    /** @var array<string, list<string>>|null */
    private ?array $descendants = null;

    /**
     * @param array<string, array{method: string, types: list<string>|true|null}> $guards        class => its guard
     * @param array<string, list<array{string, int, list<?string>, list<?string>}>> $callArguments method id => calls to a
     *                                                                                      held service: lowercase method,
     *                                                                                      line, argument classes, the
     *                                                                                      caller's parameters they are
     * @param array<string, list<string>>                                    $propertyHolds class => what its properties hold
     * @param array<string, string>                                          $parameterTypes method id => class of its first parameter
     * @param array<string, list<array{string, ?string}>>                    $methodParameters method id => name and class
     *                                                                                      of each parameter
     * @param array<string, list<array{string, TypeExpr, string, int|string}>> $parameterPasses method id => its parameters
     *                                                                                      passed on untouched: parameter,
     *                                                                                      receiver, method, argument
     */
    public function __construct(
        private readonly Graph $graph,
        private readonly TypeResolver $types,
        private readonly array $guards,
        private readonly array $callArguments,
        private readonly array $propertyHolds,
        private readonly array $parameterTypes,
        private readonly array $methodParameters = [],
        private readonly array $parameterPasses = [],
    ) {
    }

    /**
     * The guard of a member the project relies on: declared by the member or a parent, and called on it or on one of
     * its types by application code (a test calling it proves nothing). Null when it has none.
     *
     * @return array{method: string, types: list<string>|true|null}|null
     */
    public function guardOf(string $member): ?array
    {
        if (\array_key_exists($member, $this->guardOf)) {
            return $this->guardOf[$member];
        }

        $guard = null;
        foreach ($this->types->lineage($member) as $class) {
            if (isset($this->guards[$class])) {
                $guard = $this->guards[$class];
                break;
            }
        }
        if ($guard !== null && !$this->isCalled($member, $guard['method'])) {
            $guard = null;
        }

        return $this->guardOf[$member] = $guard;
    }

    /**
     * Whether the member can run for what $holder passes to the service of class $held it holds: kept when it runs for
     * some argument whatever its class at run time, dropped when it runs for none, unresolved otherwise.
     *
     * @return self::KEEP|self::DROP|self::UNRESOLVED
     */
    public function decide(string $member, string $holder, string $held): string
    {
        $guard = $this->guardOf($member);
        if ($guard === null || $guard['types'] === true) {
            return self::KEEP;
        }
        if ($guard['types'] === null) {
            return self::UNRESOLVED;
        }
        $arguments = $this->inputs($holder, $held);
        if ($arguments === null) {
            return self::UNRESOLVED;
        }

        $decision = self::DROP;
        foreach ($arguments as $constraints) {
            $outcome = $this->outcome($guard['types'], $constraints);
            if ($outcome === self::KEEP) {
                return self::KEEP;
            }
            if ($outcome === self::UNRESOLVED) {
                $decision = self::UNRESOLVED;
            }
        }

        return $decision;
    }

    /**
     * What $holder passes to the methods of $held: for each argument, the classes it is known to be, its type and the
     * types of the parameters it is passed on to (`QueryInterface $query` given to `setUp(CreateEstimateQueryInterface
     * $query)`). Null when an argument is of unknown class, or when no call is found.
     *
     * @return list<list<string>>|null
     */
    private function inputs(string $holder, string $held): ?array
    {
        $lineage = array_flip($this->types->lineage($held));
        $arguments = [];
        $found = false;
        foreach ($this->methodsOf($holder) as $method) {
            foreach ($this->graph->incident($method) as $item) {
                $edge = $item['edge'];
                if (!$item['forward'] || $edge->relation !== Relation::Calls || !isset($lineage[explode('::', $item['other'])[0]])) {
                    continue;
                }
                $name = strtolower((string) substr(strrchr($item['other'], ':') ?: '', 1));
                $lines = $edge->lines();
                $matched = false;
                foreach ($this->callArguments[$method] ?? [] as [$called, $line, $classes, $parameters]) {
                    if ($called !== $name || !\in_array($line, $lines, true)) {
                        continue;
                    }
                    $matched = true;
                    foreach ($classes as $position => $class) {
                        $class ??= $position === 0 ? $this->parameterTypes[$item['other']] ?? null : null;
                        if ($class === '') {
                            continue;
                        }
                        $parameter = $parameters[$position] ?? null;
                        $constraints = array_values(array_unique([
                            ...($class === null ? [] : [$class]),
                            ...($parameter === null ? [] : $this->passedAs($method, $parameter)),
                        ]));
                        if ($constraints === []) {
                            return null;
                        }
                        $arguments[] = $constraints;
                    }
                }
                if (!$matched) {
                    return null;
                }
                $found = true;
            }
        }

        return $found ? $arguments : null;
    }

    /**
     * The classes of the parameters a method's parameter is passed on to, untouched.
     *
     * @return list<string>
     */
    private function passedAs(string $method, string $parameter): array
    {
        $classes = [];
        foreach ($this->parameterPasses[$method] ?? [] as [$passed, $receiver, $called, $argument]) {
            $class = $passed === $parameter ? $this->types->resolve($receiver) : null;
            $callee = $class === null ? null : $this->types->findMethod($class, strtolower($called));
            foreach ($callee === null ? [] : $this->methodParameters[$callee] ?? [] as $position => [$name, $type]) {
                if ($type !== null && ($argument === $position || $argument === $name)) {
                    $classes[] = $type;
                }
            }
        }

        return $classes;
    }

    /**
     * For one argument: kept when the member runs whatever the class of the argument, dropped when it never does,
     * unresolved otherwise. The argument is any project class meeting all its constraints (a `QueryInterface` that is a
     * `CreateEstimateQueryInterface`: `CreateEstimateQuery`), with what its properties hold.
     *
     * @param list<string> $accepted
     * @param list<string> $constraints
     *
     * @return self::KEEP|self::DROP|self::UNRESOLVED
     */
    private function outcome(array $accepted, array $constraints): string
    {
        $candidates = $this->candidates($constraints);
        if ($candidates === []) {
            return self::UNRESOLVED;
        }
        $outcomes = [];
        foreach ($candidates as $candidate) {
            $outcomes[$this->runsFor($accepted, $candidate)] = true;
        }

        return \count($outcomes) === 1 ? (string) array_key_first($outcomes) : self::UNRESOLVED;
    }

    /**
     * The classes an argument may be at run time: those of the project extending or implementing all its constraints.
     * A constraint the project does not declare (a dependency's class) is taken as is.
     *
     * @param list<string> $constraints
     *
     * @return list<string>
     */
    private function candidates(array $constraints): array
    {
        $known = array_values(array_filter($constraints, fn (string $class): bool => $this->graph->hasNode($class)));
        if ($known === []) {
            return [$constraints[0]];
        }
        $candidates = [];
        $base = $known[0];
        $classes = [...($this->graph->node($base)?->kind === NodeKind::PhpClass ? [$base] : []), ...$this->descendants()[$base] ?? []];
        foreach ($classes as $class) {
            if (array_diff($known, $this->types->lineage($class)) === []) {
                $candidates[] = $class;
            }
        }

        return $candidates;
    }

    /**
     * Whether a member accepting $accepted runs for an input of class $class: on it, or on what its properties hold.
     * Unresolved when only a wider type is held (a property typed by an interface), or an accepted class is unknown.
     *
     * @param list<string> $accepted
     *
     * @return self::KEEP|self::DROP|self::UNRESOLVED
     */
    private function runsFor(array $accepted, string $class): string
    {
        $held = $this->reachable([$class => true]);
        $decision = self::DROP;
        foreach ($accepted as $type) {
            if (!$this->graph->hasNode($type)) {
                $decision = self::UNRESOLVED;
                continue;
            }
            $lineage = $this->types->lineage($type);
            foreach (array_keys($held) as $input) {
                if (\in_array($type, $this->types->lineage((string) $input), true)) {
                    return self::KEEP;
                }
                if ($input !== $class && \in_array($input, $lineage, true)) {
                    $decision = self::UNRESOLVED;
                }
            }
        }

        return $decision;
    }

    /**
     * The classes and those their properties hold, through their parents too, as deep as MAX_DEPTH.
     *
     * @param array<string, true> $classes
     *
     * @return array<string, true>
     */
    private function reachable(array $classes): array
    {
        $seen = $classes;
        for ($depth = 0, $frontier = array_keys($classes); $depth < self::MAX_DEPTH && $frontier !== []; ++$depth) {
            $next = [];
            foreach ($frontier as $class) {
                foreach ($this->types->lineage((string) $class) as $type) {
                    foreach ($this->propertyHolds[$type] ?? [] as $held) {
                        if (!isset($seen[$held]) && \count($seen) < self::MAX_CLASSES) {
                            $seen[$held] = true;
                            $next[] = $held;
                        }
                    }
                }
            }
            $frontier = $next;
        }

        return $seen;
    }

    /**
     * @return array<string, list<string>> class => the project classes extending or implementing it
     */
    private function descendants(): array
    {
        if ($this->descendants === null) {
            $this->descendants = [];
            foreach ($this->graph->nodes() as $node) {
                if ($node->kind !== NodeKind::PhpClass) {
                    continue;
                }
                foreach ($this->types->lineage($node->id) as $ancestor) {
                    if ($ancestor !== $node->id) {
                        $this->descendants[$ancestor][] = $node->id;
                    }
                }
            }
        }

        return $this->descendants;
    }

    private function isCalled(string $member, string $method): bool
    {
        foreach ($this->types->lineage($member) as $class) {
            $declared = $this->types->findMethod($class, strtolower($method));
            foreach ($declared === null ? [] : $this->graph->incident($declared) as $item) {
                $file = $this->graph->node($item['other'])?->file;
                if (!$item['forward'] && $item['edge']->relation === Relation::Calls && $file !== null && !TestFiles::isTest($file)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function methodsOf(string $class): array
    {
        $methods = [];
        foreach ($this->graph->incident($class) as $item) {
            if ($item['forward'] && $item['edge']->relation === Relation::HasMethod) {
                $methods[] = $item['other'];
            }
        }

        return $methods;
    }
}
