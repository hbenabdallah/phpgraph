<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

/**
 * Services the container configuration injects by tag or by id into application code, and what could not be linked.
 */
final readonly class InjectionStats
{
    /**
     * @param int          $injections    injections into application code (`tagged_iterator('app.rule')`, `service('app.mailer')`...)
     * @param int          $linked        those linked to at least one project class
     * @param int          $edges         `receives` edges, test code included
     * @param int          $unlinkedCount the others: services the project's configuration does not define
     * @param list<string> $unlinked      the first of them, those that look like the project's own first
     */
    public function __construct(
        public int $injections = 0,
        public int $linked = 0,
        public int $edges = 0,
        public int $unlinkedCount = 0,
        public array $unlinked = [],
    ) {
    }

    /**
     * @param array<mixed> $data as written by toArray()
     */
    public static function fromArray(array $data): self
    {
        $int = static fn (string $key): int => \is_int($data[$key] ?? null) ? $data[$key] : 0;

        return new self(
            $int('injections'),
            $int('linked'),
            $int('edges'),
            $int('unlinkedCount'),
            array_values(array_filter(\is_array($data['unlinked'] ?? null) ? $data['unlinked'] : [], 'is_string')),
        );
    }

    /**
     * @return array<string, int|list<string>>
     */
    public function toArray(): array
    {
        return [
            'injections' => $this->injections,
            'linked' => $this->linked,
            'edges' => $this->edges,
            'unlinkedCount' => $this->unlinkedCount,
            'unlinked' => $this->unlinked,
        ];
    }
}
