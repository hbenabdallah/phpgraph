<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

use PhpGraph\Values;

/**
 * The actions of the routing files phpgraph.yaml declares (ActionMapping): how many reach a controller method.
 */
final readonly class ActionStats
{
    /**
     * @param int          $actions          actions read
     * @param int          $linked           handled by a method of a controller of the project
     * @param int          $toClass          whose controller is found but not the method: handled by the class
     * @param int          $unlinkedCount    actions not handled by a method (controller or method missing)
     * @param list<string> $unlinked         the first of them, with why
     * @param int          $forwards         targets and forwards declared
     * @param int          $forwardsLinked   those leading to an action or a file of the project
     * @param int          $templates        files of the project they lead to
     * @param int          $unresolvedTargetCount targets found neither among the actions nor the files
     * @param list<string> $unresolvedTargets the first of them
     * @param int          $citations        links from a file quoting a code to its action (AMBIGUOUS)
     * @param int          $citingFiles      files quoting a code
     * @param list<string> $unreadable       declared routing files missing or not valid XML
     */
    public function __construct(
        public int $actions = 0,
        public int $linked = 0,
        public int $toClass = 0,
        public int $unlinkedCount = 0,
        public array $unlinked = [],
        public int $forwards = 0,
        public int $forwardsLinked = 0,
        public int $templates = 0,
        public int $unresolvedTargetCount = 0,
        public array $unresolvedTargets = [],
        public int $citations = 0,
        public int $citingFiles = 0,
        public array $unreadable = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->actions === 0 && $this->unreadable === [];
    }

    /**
     * @param array<mixed> $data as written by toArray()
     */
    public static function fromArray(array $data): self
    {
        $strings = static fn (string $key): array => array_values(array_filter(\is_array($data[$key] ?? null) ? $data[$key] : [], 'is_string'));

        return new self(
            Values::number($data['actions'] ?? 0),
            Values::number($data['linked'] ?? 0),
            Values::number($data['toClass'] ?? 0),
            Values::number($data['unlinkedCount'] ?? 0),
            $strings('unlinked'),
            Values::number($data['forwards'] ?? 0),
            Values::number($data['forwardsLinked'] ?? 0),
            Values::number($data['templates'] ?? 0),
            Values::number($data['unresolvedTargetCount'] ?? 0),
            $strings('unresolvedTargets'),
            Values::number($data['citations'] ?? 0),
            Values::number($data['citingFiles'] ?? 0),
            $strings('unreadable'),
        );
    }

    /**
     * @return array{
     *     actions: int, linked: int, toClass: int, unlinkedCount: int, unlinked: list<string>, forwards: int,
     *     forwardsLinked: int, templates: int, unresolvedTargetCount: int, unresolvedTargets: list<string>,
     *     citations: int, citingFiles: int, unreadable: list<string>,
     * }
     */
    public function toArray(): array
    {
        return [
            'actions' => $this->actions,
            'linked' => $this->linked,
            'toClass' => $this->toClass,
            'unlinkedCount' => $this->unlinkedCount,
            'unlinked' => $this->unlinked,
            'forwards' => $this->forwards,
            'forwardsLinked' => $this->forwardsLinked,
            'templates' => $this->templates,
            'unresolvedTargetCount' => $this->unresolvedTargetCount,
            'unresolvedTargets' => $this->unresolvedTargets,
            'citations' => $this->citations,
            'citingFiles' => $this->citingFiles,
            'unreadable' => $this->unreadable,
        ];
    }
}
