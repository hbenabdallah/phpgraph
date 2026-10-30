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
 * - The seeds' namespace, with the namespaces below it, is the module asked about: its classes linked to a seed, up
 *   what uses it then down what those use, never down then up (the forms sharing an add button are siblings).
 * - Around it, a class joins when the core uses it and it is about the question or uses the core back (a value
 *   object, an outcome), or when it uses the core and is about the question or shared by many (a validator, the
 *   base class of the use cases). A class only using the core (a use case, an aggregate) is a client: the flow.
 * - An interface or a base class of the core implemented by several classes is a family: its members are counted,
 *   not listed (34 context rules).
 * - A call to a hub (MethodCone::isHub) is no link: every DAO's getInstance() would join every class using it.
 * - A class used by many (isHub: the User model of 520 classes) may join, but nothing joins through it unless it is
 *   of the module asked about or its name carries half the words of the question: every action on a commit is not
 *   the feature of the polls on commits and votes, but what uses ShippingMethodInterface is the shipping method's.
 * - A class off the topic joins through a class about the question only (a seed, its module, a class on the topic):
 *   not one DAO after another calling each other.
 * - A word of the question that most application classes carry (the Order of App\SalesOrder) is the topic only in a
 *   class's own name, not in its namespace.
 * - At most MAX classes: of classes equally linked, the one most about the question joins first, then the one whose
 *   name is the most the question's (PromotionInterface before CatalogPromotionTranslation); those left are said.
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

    private readonly MethodCone $hubs;

    public function __construct(
        private readonly Graph $graph,
        private readonly Relevance $relevance,
    ) {
        $this->hubs = new MethodCone($graph);
    }

    /**
     * @param list<string> $seeds classes the question names
     * @param list<string> $stems
     * @param list<string> $asked classes named exactly: in the core even when members of a family
     *
     * @return array{core: list<string>, families: array<string, list<string>>, hubs: list<string>, left: list<string>} the core
     *                                                                                                                classes, in the order they joined; the families: class-like => its members; the classes used by many that
     *                                                                                                                joined the core, not followed; the classes that qualified once the core was full, best first
     */
    public function grow(array $seeds, array $stems, array $asked = []): array
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
        $followed = array_map(static fn (): bool => true, $core);
        // The classes about the question: the seeds, their module, and those on the topic that joined.
        $anchors = $core;

        $left = [];
        $common = $this->common($stems);
        for ($round = 0; $round < self::ROUNDS && \count($core) < self::MAX; ++$round) {
            $members = $this->familyMembers(array_keys($core));
            $tally = [];
            $linkedMembers = [];
            foreach (array_keys($core) as $class) {
                if (!$this->followed($followed, (string) $class, $stems)) {
                    continue;
                }
                $anchor = isset($anchors[$class]);
                foreach ($this->linksOf($class) as $other => [$uses, $usedBy]) {
                    if (isset($core[$other]) || isset($members[$other])) {
                        continue;
                    }
                    $tally[$other]['anchored'] = ($tally[$other]['anchored'] ?? false) || $anchor;
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
                $about = $headNode !== null && ($this->onTopic($headNode, $stems, $common)
                    || array_intersect_key($this->linksOf($head), $core) !== []);
                if ($about && \count($linked) >= max(2, \count($this->membersOf($head)) / 2)) {
                    $accepted[$head] = 2 * \count($linked);
                    foreach ($this->membersOf($head) as $member) {
                        $joining[$member] = true;
                    }
                }
            }
            foreach ($tally as $other => ['in' => $in, 'out' => $out, 'anchored' => $anchored]) {
                $node = $this->graph->node($other);
                if ($node === null || isset($joining[$other])) {
                    continue;
                }
                $topic = $this->onTopic($node, $stems, $common);
                $shared = \count($this->membersOf($other)) >= 2;
                // Off the topic, a class joins through a class about the question only: not DAO after DAO calling
                // each other.
                if (!$topic && !$anchored) {
                    continue;
                }
                if (($in >= 1 && ($topic || $out >= 1)) || ($out >= 1 && ($topic || $shared))) {
                    $accepted[$other] = $in * 2 + $out + ($topic ? 2 : 0);
                }
            }
            if ($accepted === []) {
                break;
            }
            // Equal links: the class most about the question first (its own name, rare words), then the one its
            // words name the most of (PromotionInterface before CatalogPromotionTranslation), then by name.
            $order = [];
            foreach (array_keys($accepted) as $other) {
                $node = $this->graph->node((string) $other);
                $short = substr((string) $other, (int) strrpos('\\' . $other, '\\'));
                $order[$other] = [-$accepted[$other], $node === null ? 0.0 : -$this->relevance->score($node, $stems), \count(Relevance::stems($short)), (string) $other];
            }
            uksort($accepted, static fn (int|string $a, int|string $b): int => $order[$a] <=> $order[$b]);
            foreach (array_keys($accepted) as $other) {
                if (\count($core) >= self::MAX) {
                    $left[] = (string) $other;

                    continue;
                }
                $core[(string) $other] = true;
                $node = $this->graph->node((string) $other);
                if ($node !== null && $this->onTopic($node, $stems, $common)) {
                    $anchors[(string) $other] = true;
                }
            }
        }

        $classes = array_map('strval', array_keys($core));
        // A parent class used by many stands for none of its subclasses that joined: a controller stays, though its
        // base has 59. An interface still stands for its implementations.
        $members = $this->familyMembers(array_values(array_filter(
            $classes,
            fn (string $class): bool => $this->graph->node($class)?->kind !== NodeKind::PhpClass
                || $this->followed($followed, $class, $stems),
        )));
        $families = [];
        foreach ($classes as $class) {
            $node = $this->graph->node($class);
            $count = \count($this->membersOf($class));
            if ($node?->kind === NodeKind::PhpInterface || $count >= 2) {
                $families[$class] = $this->membersOf($class);
            }
        }

        // A class asked about stays: it is no member of its parent's family (a controller among the 59 of its base).
        $asked = array_fill_keys($asked, true);

        return [
            'core' => array_values(array_filter($classes, static fn (string $class): bool => !isset($members[$class]) || isset($asked[$class]))),
            'families' => $families,
            'hubs' => array_values(array_filter($classes, fn (string $class): bool => !$this->followed($followed, $class, $stems))),
            'left' => array_values(array_filter($left, static fn (string $class): bool => !isset($members[$class]))),
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
        // From a seed, up what uses it then down what those use (a controller's validation rule), but never down
        // then up: the forms sharing an add button are siblings, not each other's feature.
        $kept = [];
        $ways = [];
        $queue = [];
        foreach ($seeds as $seed) {
            $queue[] = [$seed, 'both'];
        }
        while ($queue !== []) {
            [$class, $way] = array_shift($queue);
            $class = (string) $class;
            if (isset($ways[$class][$way]) || isset($ways[$class]['both'])) {
                continue;
            }
            $ways[$class][$way] = true;
            $kept[$class] = true;
            foreach ($this->linksOf($class) as $other => [$uses, $usedBy]) {
                if (!isset($classes[$other])) {
                    continue;
                }
                if ($uses) {
                    $queue[] = [$other, 'down'];
                }
                if ($usedBy && $way !== 'down') {
                    $queue[] = [$other, 'up'];
                }
            }
            // An interface of the module and its implementations are linked by their declaration.
            foreach ($this->graph->incident($class) as $item) {
                if (\in_array($item['edge']->relation, [Relation::Implements, Relation::Extends], true)
                    && isset($classes[$item['other']])) {
                    $queue[] = [$item['other'], $way];
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
                // A call to a hub (a singleton's getInstance(), a method called by every class) links nothing.
                if ($edge->relation === Relation::Calls && $this->hubs->isHub($item['forward'] ? $item['other'] : $member)) {
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

    /**
     * A class used by many application classes (MethodCone::HUB): what uses it is not about it.
     */
    public function isHub(string $class): bool
    {
        return \count(array_filter($this->linksOf($class), static fn (array $link): bool => $link[1])) >= MethodCone::HUB;
    }

    /**
     * Whether the classes using a class of the core may join through it: not when it is used by many, unless it is of
     * the module asked about or named by the question.
     *
     * @param array<string, bool> $followed the classes decided, the module's from the start
     * @param list<string>        $stems
     */
    private function followed(array &$followed, string $class, array $stems): bool
    {
        return $followed[$class] ??= !$this->isHub($class) || $this->namesHalf($class, $stems);
    }

    /**
     * @param list<string> $stems
     * @param list<string> $common
     */
    private function onTopic(Node $node, array $stems, array $common): bool
    {
        return $this->relevance->score($node, array_values(array_diff($stems, $common))) > 0
            || ($common !== [] && $this->relevance->score($node, $common, true) > 0);
    }

    /**
     * The question's words that most application classes carry somewhere in their name or namespace (the Order of
     * App\SalesOrder): about the question only in a class's own name.
     *
     * @param list<string> $stems
     *
     * @return list<string>
     */
    private function common(array $stems): array
    {
        $classes = 0;
        $carrying = [];
        foreach ($this->graph->nodes() as $node) {
            if (!$node->kind->isClassLike() || !$this->isApplication($node)) {
                continue;
            }
            ++$classes;
            foreach ($this->relevance->matched($node, $stems) as $stem) {
                $carrying[$stem] = ($carrying[$stem] ?? 0) + 1;
            }
        }

        return array_values(array_filter($stems, static fn (string $stem): bool => 2 * ($carrying[$stem] ?? 0) > $classes));
    }

    /**
     * @param list<string> $stems
     */
    private function namesHalf(string $class, array $stems): bool
    {
        $node = $this->graph->node($class);
        if ($node === null || $stems === []) {
            return false;
        }
        $named = array_filter($stems, fn (string $stem): bool => $this->relevance->score($node, [$stem], true) > 0);

        return 2 * \count($named) >= \count($stems);
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
