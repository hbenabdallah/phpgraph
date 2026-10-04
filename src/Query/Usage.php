<?php

declare(strict_types=1);

namespace PhpGraph\Query;

use PhpGraph\Builder\TestFiles;
use PhpGraph\Graph\Graph;
use PhpGraph\Graph\NodeKind;
use PhpGraph\Graph\Relation;

/**
 * Who uses a class, tests apart: what no test touches, and what nothing in the application uses.
 */
final class Usage
{
    public function __construct(private readonly Graph $graph)
    {
    }

    /**
     * Whether a test instantiates, calls or references the class or one of its methods, anywhere in the project.
     */
    public function touchedByTests(string $class): bool
    {
        foreach ($this->members($class) as $node) {
            foreach ($this->graph->incident($node) as $item) {
                $file = $this->graph->node(explode('::', $item['other'])[0])?->file;
                if (!$item['forward'] && $item['edge']->relation !== Relation::Imports && $file !== null && TestFiles::isTest($file)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * For an application class standing alone, whether nothing outside tests uses it: no other class of the
     * application calls, instantiates, references, receives or extends it, nor its methods. Null when it may be
     * used without that showing: it implements an interface or extends a class, through which the framework may
     * reach it.
     *
     * @return array{bool, bool}|null [unused by the application, used by tests]
     */
    public function unused(string $class): ?array
    {
        $node = $this->graph->node($class);
        if ($node?->kind !== NodeKind::PhpClass || $node->file === null || TestFiles::isTest($node->file)) {
            return null;
        }
        foreach ($this->graph->incident($class) as $item) {
            if ($item['forward'] && \in_array($item['edge']->relation, [Relation::Extends, Relation::Implements], true)) {
                return null;
            }
        }
        $tested = false;
        foreach ($this->members($class) as $member) {
            foreach ($this->graph->incident($member) as $item) {
                if ($item['forward'] || \in_array($item['edge']->relation, [Relation::Defines, Relation::Imports, Relation::HasMethod, Relation::ReadsStateOf], true)) {
                    continue;
                }
                $owner = $this->graph->node(explode('::', $item['other'])[0]);
                if ($owner?->id === $class) {
                    continue;
                }
                if ($owner?->file === null || !TestFiles::isTest($owner->file)) {
                    return [false, $tested];
                }
                $tested = true;
            }
        }

        return [true, $tested];
    }

    /**
     * @return list<string> the class and its methods
     */
    private function members(string $class): array
    {
        $members = [$class];
        foreach ($this->graph->incident($class) as $item) {
            if ($item['forward'] && $item['edge']->relation === Relation::HasMethod) {
                $members[] = $item['other'];
            }
        }

        return $members;
    }
}
