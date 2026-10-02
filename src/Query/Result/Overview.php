<?php

declare(strict_types=1);

namespace PhpGraph\Query\Result;

use PhpGraph\Project\ProjectSummary;

/**
 * The first look at a project: what it is made of, how it is organised, and what the graph does not know.
 */
final readonly class Overview
{
    /**
     * @param array<string, int>              $nodesByKind
     * @param string                          $root               namespace shared by every application class ('' if none)
     * @param list<NamespaceGroup>            $namespaces         below the root, largest first
     * @param array<string, array<string, int>> $layers           role => layer-like segment => application classes
     * @param array<string, int>              $suffixes           class name suffix (Handler, Repository...) => application classes
     */
    public function __construct(
        public ?ProjectSummary $summary,
        public array $nodesByKind,
        public int $applicationClasses,
        public int $testClasses,
        public int $globalClasses,
        public string $root,
        public array $namespaces,
        public array $layers,
        public array $suffixes,
    ) {
    }
}
