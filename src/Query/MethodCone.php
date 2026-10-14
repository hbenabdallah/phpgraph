<?php

declare(strict_types=1);

namespace PhpGraph\Query;

use PhpGraph\Builder\TestFiles;
use PhpGraph\Graph\Graph;
use PhpGraph\Graph\NodeKind;
use PhpGraph\Graph\Relation;

/**
 * What a method runs: the methods it calls, transitively and nearest first, a few calls deep. A method called by many
 * classes is a hub (a singleton's getInstance(), a context, a bean's set()): listed with its callers counted, not
 * followed, and its class joins only through another of its methods; so is a singleton's getInstance(). An interface or abstract method leads to its
 * implementations when few, and an instantiated class to its constructor.
 */
final class MethodCone
{
    public const DEPTH = 3;

    /**
     * Called by this many application classes or more: a hub of the project, not a step of the feature. Measured on a
     * legacy project of 4,800 files: the methods of a feature are called by 1 to 30 classes, its hubs by 60 to 1,000.
     */
    public const HUB = 20;

    private const MAX_METHODS = 120;

    private const MAX_CLASSES = 45;

    /** An interface implemented by more classes is a family: which one runs is not for the graph to say. */
    private const IMPLEMENTATIONS = 4;

    /** @var array<string, int> */
    private array $callers = [];

    public function __construct(private readonly Graph $graph)
    {
    }

    /**
     * @return array{methods: array<string, int>, calls: array<string, list<string>>, hubs: array<string, int>, classes: list<string>}
     *                                                                                                                                   the methods run, with their depth; who calls whom among them; the hubs called, with their callers; the classes, nearest first
     */
    public function of(string $start): array
    {
        $methods = [$start => 0];
        $calls = [];
        $hubs = [];
        $classes = [$this->classOf($start) => true];
        for ($frontier = [$start], $depth = 1; $frontier !== [] && $depth <= self::DEPTH; ++$depth) {
            $next = [];
            foreach ($frontier as $method) {
                foreach ($this->steps($method) as $callee) {
                    $class = $this->classOf($callee);
                    if ($this->isHub($callee)) {
                        $hubs[$callee] = $this->callers($callee);
                        continue;
                    }
                    $calls[$method][] = $callee;
                    if (isset($methods[$callee])) {
                        continue;
                    }
                    if (\count($methods) >= self::MAX_METHODS || (!isset($classes[$class]) && \count($classes) >= self::MAX_CLASSES)) {
                        continue;
                    }
                    $methods[$callee] = $depth;
                    $classes[$class] = true;
                    $next[] = $callee;
                }
            }
            $frontier = $next;
        }
        foreach ($calls as $method => $callees) {
            $calls[$method] = array_values(array_unique(array_filter($callees, static fn (string $callee): bool => isset($methods[$callee]))));
        }
        arsort($hubs);

        return [
            'methods' => $methods,
            'calls' => array_filter($calls),
            'hubs' => $hubs,
            'classes' => array_map('strval', array_keys($classes)),
        ];
    }

    /**
     * The methods a method calls in the application, the constructors of the classes it instantiates, and the
     * implementations of an interface or abstract method (when it declares nothing to run itself), when few.
     *
     * @return list<string>
     */
    private function steps(string $method): array
    {
        $steps = [];
        $implementations = [];
        foreach ($this->graph->incident($method) as $item) {
            $edge = $item['edge'];
            if ($item['forward'] && $edge->relation === Relation::Calls && !str_starts_with($edge->via(), 'closure of ')) {
                $steps[] = $item['other'];
            } elseif ($item['forward'] && $edge->relation === Relation::Instantiates) {
                $constructor = $this->constructor($item['other']);
                if ($constructor !== null) {
                    $steps[] = $constructor;
                }
            } elseif (!$item['forward'] && $edge->relation === Relation::Overrides) {
                $implementations[] = $item['other'];
            }
        }
        if ($implementations !== [] && \count($implementations) <= self::IMPLEMENTATIONS && $this->isAbstract($method)) {
            array_push($steps, ...$implementations);
        }

        return array_values(array_unique(array_filter($steps, fn (string $id): bool => $this->isApplication($id))));
    }

    /**
     * Called by many classes, or a singleton's accessor (getInstance()): plumbing, whoever calls it.
     */
    public function isHub(string $method): bool
    {
        return $this->callers($method) >= self::HUB || self::isAccessor($method);
    }

    /**
     * A singleton's accessor, getInstance() or instance(): plumbing, nothing of a feature.
     */
    public static function isAccessor(string $method): bool
    {
        return preg_match('/::(get)?instance$/i', $method) === 1;
    }

    /**
     * The application classes calling a method (or instantiating its class, for a constructor).
     */
    public function callers(string $method): int
    {
        if (!isset($this->callers[$method])) {
            $classes = [];
            $constructor = $this->isConstructor($method);
            foreach ([$method, ...($constructor ? [$this->classOf($method)] : [])] as $id) {
                foreach ($this->graph->incident($id) as $item) {
                    $relation = $item['edge']->relation;
                    if (!$item['forward'] && ($relation === Relation::Calls || ($constructor && $relation === Relation::Instantiates))) {
                        $node = $this->graph->node($this->classOf($item['other']));
                        if ($node !== null && $node->kind->isClassLike() && $node->file !== null && !TestFiles::isTest($node->file)) {
                            $classes[$node->id] = true;
                        }
                    }
                }
            }
            $this->callers[$method] = \count($classes);
        }

        return $this->callers[$method];
    }

    private function constructor(string $class): ?string
    {
        $label = $this->graph->node($class)?->label;
        foreach ($this->graph->incident($class) as $item) {
            if ($item['forward'] && $item['edge']->relation === Relation::HasMethod) {
                $name = substr($item['other'], (int) strrpos($item['other'], ':') + 1);
                // PHP 4 names its constructor after its class.
                if (strtolower($name) === '__construct' || ($label !== null && strcasecmp($name, $label) === 0)) {
                    return $item['other'];
                }
            }
        }

        return null;
    }

    private function isConstructor(string $method): bool
    {
        return $this->constructor($this->classOf($method)) === $method;
    }

    /**
     * A method of an interface, or one with nothing to run: its implementations are what runs.
     */
    private function isAbstract(string $method): bool
    {
        if ($this->graph->node($this->classOf($method))?->kind === NodeKind::PhpInterface) {
            return true;
        }
        foreach ($this->graph->incident($method) as $item) {
            if ($item['forward'] && \in_array($item['edge']->relation, [Relation::Calls, Relation::Instantiates], true)) {
                return false;
            }
        }

        return true;
    }

    private function isApplication(string $id): bool
    {
        $node = $this->graph->node($this->classOf($id));

        return $node !== null && $node->kind->isClassLike() && $node->kind !== NodeKind::External && $node->file !== null && !TestFiles::isTest($node->file)
            && $this->graph->hasNode($id);
    }

    private function classOf(string $method): string
    {
        return explode('::', $method)[0];
    }
}
