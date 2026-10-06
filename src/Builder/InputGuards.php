<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

use PhpGraph\Graph\Graph;
use PhpGraph\Graph\NodeKind;
use PhpGraph\Graph\Relation;

/**
 * Whether a member of a tagged collection can run for what a service gives the collection's holder. A rule declaring
 * what it accepts (`supports(object $input): bool { return $input instanceof CreateEstimateQuery; }`) never runs for a
 * use case passing another query to the shared pipeline: the preview use case does not run the rules of creation.
 *
 * The input is the classes of the arguments the use case passes to the service it holds by id, with the classes their
 * properties hold, recursively (a validator walking the input meets its lines); an argument of an interface may be
 * any of its implementations, which leaves their rules unresolved. A guard is trusted only when the
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
     * @param array<string, list<array{string, int, list<?string>}>>         $callArguments method id => calls to a held
     *                                                                                      service: lowercase method,
     *                                                                                      line, argument classes
     * @param array<string, list<string>>                                    $propertyHolds class => what its properties hold
     * @param array<string, string>                                          $parameterTypes method id => class of its first parameter
     */
    public function __construct(
        private readonly Graph $graph,
        private readonly TypeResolver $types,
        private readonly array $guards,
        private readonly array $callArguments,
        private readonly array $propertyHolds,
        private readonly array $parameterTypes,
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
     * Whether the member can run for what $holder passes to the service of class $held it holds.
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
        $roots = $this->inputs($holder, $held);
        if ($roots === null) {
            return self::UNRESOLVED;
        }
        // What the arguments hold, and what they may hold: a `QueryInterface` is one of its implementations at run time.
        $possible = $roots;
        foreach (array_keys($roots) as $root) {
            foreach ($this->descendants()[$root] ?? [] as $class) {
                $possible[$class] = true;
            }
        }

        return $this->accepts($guard['types'], $this->reachable($roots), $this->reachable($possible));
    }

    /**
     * The classes $holder passes to the methods of $held. Null when a call passes an argument of unknown class, or
     * when no call is found.
     *
     * @return array<string, true>|null
     */
    private function inputs(string $holder, string $held): ?array
    {
        $lineage = array_flip($this->types->lineage($held));
        $roots = [];
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
                foreach ($this->callArguments[$method] ?? [] as [$called, $line, $arguments]) {
                    if ($called !== $name || !\in_array($line, $lines, true)) {
                        continue;
                    }
                    $matched = true;
                    foreach ($arguments as $position => $class) {
                        $class ??= $position === 0 ? $this->parameterTypes[$item['other']] ?? null : null;
                        if ($class === null) {
                            return null;
                        }
                        if ($class !== '') {
                            $roots[$class] = true;
                        }
                    }
                }
                if (!$matched) {
                    return null;
                }
                $found = true;
            }
        }

        return $found ? $roots : null;
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
     * Kept when an input is an accepted class or extends one; unresolved when an input is only wider (the use case
     * passes a `QueryInterface`, the rule accepts `CreateEstimateQuery`), when only what a subclass of an input holds
     * matches, or when an accepted class is unknown to the project; dropped otherwise.
     *
     * @param list<string>        $accepted
     * @param array<string, true> $inputs   the arguments and what their properties hold
     * @param array<string, true> $possible the same, with the subclasses of the arguments
     *
     * @return self::KEEP|self::DROP|self::UNRESOLVED
     */
    private function accepts(array $accepted, array $inputs, array $possible): string
    {
        $decision = self::DROP;
        foreach ($accepted as $class) {
            if (!$this->graph->hasNode($class)) {
                $decision = self::UNRESOLVED;
                continue;
            }
            $lineage = $this->types->lineage($class);
            foreach (array_keys($possible) as $input) {
                $narrower = \in_array($class, $this->types->lineage((string) $input), true);
                if ($narrower && isset($inputs[$input])) {
                    return self::KEEP;
                }
                if ($narrower || \in_array($input, $lineage, true)) {
                    $decision = self::UNRESOLVED;
                }
            }
        }

        return $decision;
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
