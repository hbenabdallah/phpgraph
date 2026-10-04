<?php

declare(strict_types=1);

namespace PhpGraph\Query;

use PhpGraph\Builder\TestFiles;
use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Graph;
use PhpGraph\Graph\Node;
use PhpGraph\Graph\NodeKind;
use PhpGraph\Graph\Relation;

/**
 * The classes a feature is made of, from the classes a question names: deterministic, from the graph only.
 *
 * - The seeds' namespace, with the namespaces below it, is the module asked about.
 * - Around it, a class joins when the core uses it and it is about the question or uses the core back (a value
 *   object, an outcome), or when it uses the core and is about the question or shared by many (a validator, the
 *   base class of the use cases). A class only using the core (a use case, an aggregate) is a client: the flow.
 * - An interface or a base class of the core implemented by several classes is a family: its members are counted,
 *   not listed (34 context rules).
 */
final class FeatureCluster
{
    private const MODULE = 60;

    private const MAX = 45;

    private const ROUNDS = 4;

    private const STRONG = [
        Relation::Calls, Relation::Instantiates, Relation::References, Relation::Receives, Relation::Extends,
        Relation::Implements, Relation::Dispatches, Relation::HandledBy, Relation::ReadsStateOf,
    ];

    /** @var array<string, array<string, array{bool, bool}>> class => other class => [it uses the other, the other uses it] */
    private array $links = [];

    /** @var array<string, list<string>> class-like => the application classes implementing or extending it */
    private array $members = [];

    public function __construct(
        private readonly Graph $graph,
        private readonly Relevance $relevance,
    ) {
    }

    /**
     * @param list<string> $seeds classes the question names
     * @param list<string> $stems
     *
     * @return array{core: list<string>, families: array<string, list<string>>} the core classes, in the order they
     *                                                                          joined, and the families: class-like => its members
     */
    public function grow(array $seeds, array $stems): array
    {
        $core = [];
        foreach ($seeds as $seed) {
            $core[$seed] = true;
        }
        foreach ($seeds as $seed) {
            foreach ($this->module($seed) as $class) {
                $core[$class] ??= true;
            }
        }
        $core = $this->connected($core, $seeds);

        for ($round = 0; $round < self::ROUNDS && \count($core) < self::MAX; ++$round) {
            $members = $this->familyMembers(array_keys($core));
            $tally = [];
            $linkedMembers = [];
            foreach (array_keys($core) as $class) {
                foreach ($this->linksOf($class) as $other => [$uses, $usedBy]) {
                    if (isset($core[$other]) || isset($members[$other])) {
                        continue;
                    }
                    // A member of a family stands for its family: the interface of the rules joins, not each rule.
                    $head = $this->headOf($other);
                    if ($head !== null && !isset($core[$head]) && $usedBy) {
                        $linkedMembers[$head][$other] = true;
                    }
                    $tally[$other]['in'] = ($tally[$other]['in'] ?? 0) + ($uses ? 1 : 0);
                    $tally[$other]['out'] = ($tally[$other]['out'] ?? 0) + ($usedBy ? 1 : 0);
                }
            }
            $accepted = [];
            // Most of a family using the core: the family is part of the feature (the rules of a validator), its
            // members are not.
            $joining = [];
            foreach ($linkedMembers as $head => $linked) {
                // The family itself works with the core (its interface takes a Notification) or is about the question.
                $headNode = $this->graph->node($head);
                $about = $headNode !== null && ($this->relevance->score($headNode, $stems) > 0
                    || array_intersect_key($this->linksOf($head), $core) !== []);
                if ($about && \count($linked) >= max(2, \count($this->membersOf($head)) / 2)) {
                    $accepted[$head] = 2 * \count($linked);
                    foreach ($this->membersOf($head) as $member) {
                        $joining[$member] = true;
                    }
                }
            }
            foreach ($tally as $other => ['in' => $in, 'out' => $out]) {
                $node = $this->graph->node($other);
                if ($node === null || isset($joining[$other])) {
                    continue;
                }
                $topic = $this->relevance->score($node, $stems) > 0;
                $shared = \count($this->membersOf($other)) >= 2;
                if (($in >= 1 && ($topic || $out >= 1)) || ($out >= 1 && ($topic || $shared))) {
                    $accepted[$other] = $in * 2 + $out + ($topic ? 2 : 0);
                }
            }
            if ($accepted === []) {
                break;
            }
            arsort($accepted);
            foreach (array_keys($accepted) as $other) {
                if (\count($core) >= self::MAX) {
                    break;
                }
                $core[(string) $other] = true;
            }
        }

        $classes = array_map('strval', array_keys($core));
        $members = $this->familyMembers($classes);
        $families = [];
        foreach ($classes as $class) {
            $node = $this->graph->node($class);
            $count = \count($this->membersOf($class));
            if ($node?->kind === NodeKind::PhpInterface || $count >= 2) {
                $families[$class] = $this->membersOf($class);
            }
        }

        return [
            'core' => array_values(array_filter($classes, static fn (string $class): bool => !isset($members[$class]))),
            'families' => $families,
        ];
    }

    /**
     * The application classes implementing or extending a class-like, directly or not.
     *
     * @return list<string>
     */
    public function membersOf(string $class): array
    {
        if (isset($this->members[$class])) {
            return $this->members[$class];
        }
        $found = [];
        for ($queue = [$class]; $queue !== [];) {
            $next = [];
            foreach ($queue as $id) {
                foreach ($this->graph->incident($id) as $item) {
                    if ($item['forward'] || isset($found[$item['other']])
                        || !\in_array($item['edge']->relation, [Relation::Implements, Relation::Extends], true)) {
                        continue;
                    }
                    $node = $this->graph->node($item['other']);
                    if ($node === null || !$this->isApplication($node)) {
                        continue;
                    }
                    $found[$item['other']] = true;
                    $next[] = $item['other'];
                }
            }
            $queue = $next;
        }

        return $this->members[$class] = array_values(array_filter(
            array_map('strval', array_keys($found)),
            fn (string $id): bool => \in_array($this->graph->node($id)?->kind, [NodeKind::PhpClass, NodeKind::PhpEnum], true),
        ));
    }

    /**
     * The classes linked to the seeds through each other: a class of the module working with something else
     * (a value object used by other validators) is no part of the feature.
     *
     * @param array<string, true> $classes
     * @param list<string>        $seeds
     *
     * @return array<string, true>
     */
    private function connected(array $classes, array $seeds): array
    {
        $kept = [];
        for ($queue = $seeds; $queue !== [];) {
            $class = (string) array_shift($queue);
            if (isset($kept[$class])) {
                continue;
            }
            $kept[$class] = true;
            foreach (array_keys($this->linksOf($class)) as $other) {
                if (isset($classes[$other]) && !isset($kept[$other])) {
                    $queue[] = $other;
                }
            }
            // An interface of the module and its implementations are linked by their declaration.
            foreach ($this->graph->incident($class) as $item) {
                if (\in_array($item['edge']->relation, [Relation::Implements, Relation::Extends], true)
                    && isset($classes[$item['other']]) && !isset($kept[$item['other']])) {
                    $queue[] = $item['other'];
                }
            }
        }

        return $kept;
    }

    /**
     * The nearest interface or parent class of a class implemented by several application classes, if any.
     */
    public function headOf(string $class): ?string
    {
        $seen = [];
        for ($queue = [$class]; $queue !== [];) {
            $next = [];
            foreach ($queue as $id) {
                foreach ($this->graph->incident($id) as $item) {
                    if (!$item['forward'] || isset($seen[$item['other']])
                        || !\in_array($item['edge']->relation, [Relation::Implements, Relation::Extends], true)) {
                        continue;
                    }
                    $seen[$item['other']] = true;
                    $node = $this->graph->node($item['other']);
                    if ($node === null || !$this->isApplication($node)) {
                        continue;
                    }
                    if (\count($this->membersOf($node->id)) >= 2) {
                        return $node->id;
                    }
                    $next[] = $node->id;
                }
            }
            $queue = $next;
        }

        return null;
    }

    /**
     * Classes this class uses, or used by it, by a strong relation of it or its methods: application code only.
     *
     * @return array<string, array{bool, bool}> other class => [it uses the other, the other uses it]
     */
    public function linksOf(string $class): array
    {
        if (isset($this->links[$class])) {
            return $this->links[$class];
        }
        $links = [];
        $members = [$class];
        foreach ($this->graph->incident($class) as $item) {
            if ($item['forward'] && $item['edge']->relation === Relation::HasMethod) {
                $members[] = $item['other'];
            }
        }
        foreach ($members as $member) {
            foreach ($this->graph->incident($member) as $item) {
                $edge = $item['edge'];
                // A closure a method runs is its writer's code: the pipeline does not use each use case's builder.
                if (!\in_array($edge->relation, self::STRONG, true) || str_starts_with($edge->via(), 'closure of ')) {
                    continue;
                }
                // A member of an injected list is the list's, not each holder's: counted as a family.
                if ($edge->relation === Relation::Receives && ($edge->confidence !== Confidence::Extracted || str_starts_with($edge->via(), 'tagged_'))) {
                    continue;
                }
                $other = explode('::', $item['other'])[0];
                $node = $this->graph->node($other);
                // An exception thrown or caught says nothing of what a class works with.
                if ($other === $class || $node === null || !$node->kind->isClassLike() || !$this->isApplication($node)
                    || preg_match('/(Exception|Error)$/', $other) === 1) {
                    continue;
                }
                $links[$other] ??= [false, false];
                $links[$other][$item['forward'] ? 0 : 1] = true;
            }
        }

        return $this->links[$class] = $links;
    }

    public function isApplication(Node $node): bool
    {
        return $node->kind !== NodeKind::External && $node->file !== null && !TestFiles::isTest($node->file);
    }

    /**
     * @param list<string> $classes
     *
     * @return array<string, true> the members of the families among the classes
     */
    private function familyMembers(array $classes): array
    {
        $members = [];
        foreach ($classes as $class) {
            $found = $this->membersOf($class);
            if (\count($found) >= 2) {
                foreach ($found as $member) {
                    $members[$member] = true;
                }
            }
        }

        return $members;
    }

    /**
     * The application classes of a class's namespace and the namespaces below it, unless it is a layer rather
     * than a module (more than 60 classes).
     *
     * @return list<string>
     */
    private function module(string $class): array
    {
        $namespace = (string) strrchr('\\' . $class, '\\') === '\\' . $class ? '' : substr($class, 0, (int) strrpos($class, '\\'));
        if ($namespace === '') {
            return [];
        }
        $found = [];
        foreach ($this->graph->nodes() as $node) {
            if ($node->kind->isClassLike() && $this->isApplication($node)
                && str_starts_with($node->id, $namespace . '\\')) {
                $found[] = $node->id;
                if (\count($found) > self::MODULE) {
                    return [];
                }
            }
        }

        return $found;
    }
}
