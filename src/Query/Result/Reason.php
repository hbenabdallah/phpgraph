<?php

declare(strict_types=1);

namespace PhpGraph\Query\Result;

/**
 * Why a class is in an outline, or would have been when the core was full: what the heuristics decided, made visible.
 */
final readonly class Reason
{
    /**
     * @param ?string $via     the class (or method, route) the decision went through: the seed of a module, the core
     *                         class it uses or is used by, the method calling it, the route named after the question
     * @param ?int    $count   for a family, its members using the core
     * @param bool    $reduced a class used by many: shown with the methods the core calls only
     */
    public function __construct(
        public ReasonKind $kind,
        public ?string $via = null,
        public ?int $count = null,
        public bool $reduced = false,
    ) {
    }

    public function reduced(): self
    {
        return new self($this->kind, $this->via, $this->count, true);
    }

    /**
     * @return array{kind: string, via?: string, count?: int, reduced?: true}
     */
    public function toArray(): array
    {
        $data = ['kind' => $this->kind->value];
        if ($this->via !== null) {
            $data['via'] = $this->via;
        }
        if ($this->count !== null) {
            $data['count'] = $this->count;
        }
        if ($this->reduced) {
            $data['reduced'] = true;
        }

        return $data;
    }
}
