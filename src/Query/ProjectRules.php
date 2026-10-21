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
use PhpGraph\Project\Rules\DependencyRule;
use PhpGraph\Project\Rules\HandlerRule;
use PhpGraph\Project\Rules\Pattern;
use PhpGraph\Project\Rules\RuleSet;
use PhpGraph\Project\Rules\StructureRule;
use PhpGraph\Query\Result\RuleViolation;

/**
 * Checks the project's own rules (phpgraph.yaml `rules`) on application code, as LayerRules checks the default ones:
 * who may depend on what, what the classes a pattern selects must be, and what a message handler may dispatch. Only
 * certain dependencies count, EXTRACTED and INFERRED edges; a class is matched by its name, without its service.
 * Inheritance is read from the graph: the parents of a dependency's class are not known.
 */
final class ProjectRules
{
    /**
     * The relations making a class depend on another, as LayerRules counts them.
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

    private const TARGETS = [
        'class' => NodeKind::PhpClass,
        'interface' => NodeKind::PhpInterface,
        'trait' => NodeKind::PhpTrait,
        'enum' => NodeKind::PhpEnum,
    ];

    /** @var array<string, Node> application classes, by id */
    private array $classes = [];

    /** @var array<string, list<string>> class id => the lowercase names it extends, implements or uses, at any depth */
    private array $lineages = [];

    public function __construct(private readonly Graph $graph)
    {
        foreach ($graph->nodes() as $node) {
            if ($node->kind->isClassLike() && $node->kind !== NodeKind::External && ($node->file === null || !TestFiles::isTest($node->file))) {
                $this->classes[$node->id] = $node;
            }
        }
    }

    /**
     * @return list<RuleViolation>
     */
    public function check(RuleSet $rules): array
    {
        $violations = [
            ...$this->dependencies($rules->dependencies),
            ...$this->structure($rules->structure),
            ...($rules->handlers === null ? [] : $this->handlers($rules->handlers)),
        ];
        usort($violations, static fn (RuleViolation $a, RuleViolation $b): int => strcmp($a->key(), $b->key()));

        return $violations;
    }

    /**
     * @param list<DependencyRule> $rules
     *
     * @return list<RuleViolation>
     */
    private function dependencies(array $rules): array
    {
        if ($rules === []) {
            return [];
        }
        /** @var array<string, array{DependencyRule, string, string, Edge, int}> $found */
        $found = [];
        foreach ($this->graph->edges() as $edge) {
            if ($edge->confidence === Confidence::Ambiguous || !\in_array($edge->relation, self::DEPENDENCIES, true)) {
                continue;
            }
            $from = explode('::', $edge->source)[0];
            $to = explode('::', $edge->target)[0];
            if ($from === $to || !isset($this->classes[$from])) {
                continue;
            }
            $fromName = self::name($from);
            $toName = self::name($to);
            foreach ($rules as $rule) {
                if (!Pattern::matches($rule->dependency, $toName) || Pattern::matches($rule->dependency, $fromName) || !$rule->forbids($fromName)) {
                    continue;
                }
                $key = $rule->name . "\0" . $from . "\0" . $to;
                $known = $found[$key] ?? null;
                $found[$key] = [
                    $rule,
                    $fromName,
                    $toName,
                    $known !== null && ($known[3]->confidence === Confidence::Extracted || $edge->confidence !== Confidence::Extracted) ? $known[3] : $edge,
                    ($known[4] ?? 0) + 1,
                ];
            }
        }

        return array_values(array_map(
            static fn (array $entry): RuleViolation => new RuleViolation($entry[0]->name, $entry[1], 'depends on ' . $entry[2], $entry[0]->reason, $entry[3], $entry[4]),
            $found,
        ));
    }

    /**
     * @param list<StructureRule> $rules
     *
     * @return list<RuleViolation>
     */
    private function structure(array $rules): array
    {
        $violations = [];
        foreach ($rules as $rule) {
            foreach ($this->classes as $id => $node) {
                $name = self::name($id);
                if (!Pattern::matches($rule->on, $name) || ($rule->notOn !== null && Pattern::matches($rule->notOn, $name))
                    || ($rule->target !== null && $node->kind !== self::TARGETS[$rule->target])) {
                    continue;
                }
                foreach ($this->breaches($rule, $id, $node) as $detail) {
                    $violations[] = new RuleViolation($rule->name, $name, $detail, $rule->reason);
                }
            }
        }

        return $violations;
    }

    /**
     * What a class selected by a rule fails to be, one sentence per constraint broken.
     *
     * @return list<string>
     */
    private function breaches(StructureRule $rule, string $id, Node $node): array
    {
        $breaches = [];
        foreach (['final' => $rule->mustBeFinal, 'abstract' => $rule->mustBeAbstract, 'readonly' => $rule->mustBeReadonly] as $modifier => $wanted) {
            if ($wanted !== null && \in_array($modifier, $node->modifiers, true) !== $wanted) {
                $breaches[] = ($wanted ? 'is not ' : 'is ') . $modifier;
            }
        }
        if ($rule->mustBeNamed !== null && !Pattern::matches($rule->mustBeNamed, $node->label)) {
            $breaches[] = 'is not named ' . $rule->mustBeNamed;
        }
        $lineage = $this->lineage($id);
        foreach ($rule->mustImplement as $interface) {
            if (!\in_array(strtolower(ltrim($interface, '\\')), $lineage, true)) {
                $breaches[] = 'does not implement ' . ltrim($interface, '\\');
            }
        }
        if ($rule->mustExtend !== null && !\in_array(strtolower(ltrim($rule->mustExtend, '\\')), $lineage, true)) {
            $breaches[] = 'does not extend ' . ltrim($rule->mustExtend, '\\');
        }
        foreach ($rule->mustUseTrait as $trait) {
            if (!\in_array(strtolower(ltrim($trait, '\\')), $lineage, true)) {
                $breaches[] = 'does not use ' . ltrim($trait, '\\');
            }
        }
        if ($rule->onlyPublicMethods !== null) {
            $allowed = array_map('strtolower', $rule->onlyPublicMethods);
            foreach ($this->graph->incident($id) as $item) {
                $method = $this->graph->node($item['other']);
                if (!$item['forward'] || $item['edge']->relation !== Relation::HasMethod || $method === null
                    || \in_array('protected', $method->modifiers, true) || \in_array('private', $method->modifiers, true)) {
                    continue;
                }
                $short = substr($method->id, (int) strrpos($method->id, '::') + 2);
                if (!\in_array(strtolower($short), $allowed, true)) {
                    $breaches[] = 'has the public method ' . $short . '()';
                }
            }
        }

        return $breaches;
    }

    /**
     * A handler dispatching a message of another bounded context than its own: the contexts are read from the
     * namespaces, as for the layers (Layers::context).
     *
     * @return list<RuleViolation>
     */
    private function handlers(HandlerRule $rule): array
    {
        $layerFirst = Layers::isLayerFirst(array_keys($this->classes));
        $shared = array_map('strtolower', $rule->shared);
        $handlers = [];
        foreach ($this->graph->edges() as $edge) {
            if ($edge->relation === Relation::HandledBy && $edge->confidence !== Confidence::Ambiguous) {
                $handlers[explode('::', $edge->target)[0]] = true;
            }
        }
        $found = [];
        foreach (array_keys($handlers) as $handler) {
            $context = isset($this->classes[$handler]) ? Layers::context(self::name($handler), $layerFirst) : null;
            if ($context === null) {
                continue;
            }
            foreach ($this->dispatchesOf($handler) as $edge) {
                $message = self::name($edge->target);
                $other = Layers::context($message, $layerFirst);
                if ($other === null || strcasecmp($other, $context) === 0) {
                    continue;
                }
                $separator = strrpos($other, '\\');
                if (array_intersect([strtolower($other), strtolower($separator === false ? $other : substr($other, $separator + 1))], $shared) !== []) {
                    continue;
                }
                $found[self::name($handler) . "\0" . $message] ??= new RuleViolation(
                    $rule->name,
                    self::name($handler),
                    \sprintf('dispatches %s, a message of %s, not %s', $message, $other, $context),
                    $rule->reason,
                    $edge,
                    1,
                );
            }
        }

        return array_values($found);
    }

    /**
     * The certain `dispatches` edges of a class's methods, to a message class.
     *
     * @return list<Edge>
     */
    private function dispatchesOf(string $class): array
    {
        $edges = [];
        foreach ($this->graph->incident($class) as $member) {
            if (!$member['forward'] || $member['edge']->relation !== Relation::HasMethod) {
                continue;
            }
            foreach ($this->graph->incident($member['other']) as $item) {
                $node = $this->graph->node($item['other']);
                if ($item['forward'] && $item['edge']->relation === Relation::Dispatches && $item['edge']->confidence !== Confidence::Ambiguous
                    && $node !== null && $node->kind->isClassLike()) {
                    $edges[] = $item['edge'];
                }
            }
        }

        return $edges;
    }

    /**
     * @return list<string>
     */
    private function lineage(string $id): array
    {
        if (isset($this->lineages[$id])) {
            return $this->lineages[$id];
        }
        $this->lineages[$id] = [];
        $lineage = [];
        foreach ($this->graph->incident($id) as $item) {
            if ($item['forward'] && \in_array($item['edge']->relation, [Relation::Extends, Relation::Implements, Relation::UsesTrait], true)) {
                $lineage[] = strtolower(self::name($item['other']));
                array_push($lineage, ...$this->lineage($item['other']));
            }
        }

        return $this->lineages[$id] = array_values(array_unique($lineage));
    }

    /**
     * A class's name without its service: `billing@App\Order` is `App\Order`.
     */
    private static function name(string $id): string
    {
        $at = strpos($id, '@');

        return $at === false ? $id : substr($id, $at + 1);
    }
}
