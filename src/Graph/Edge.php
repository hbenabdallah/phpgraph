<?php

declare(strict_types=1);

namespace PhpGraph\Graph;

final class Edge
{
    /**
     * @param string $lines the lines of the source file where the relation is written, `12,40`: the call sites of a
     *                      `calls` edge. A string, not a list: the large graphs have hundreds of thousands of edges.
     * @param string $via   what carries the relation, when it says more than the relation: the tag of a `receives`
     *                      edge (`tagged_iterator app.rule`, `tagged_locator app.use_case`)
     */
    public function __construct(
        public readonly string $source,
        public readonly string $target,
        public readonly Relation $relation,
        public readonly Confidence $confidence = Confidence::Extracted,
        private string $lines = '',
        private string $via = '',
    ) {
        $this->lines = $lines === '0' ? '' : $lines;
    }

    public function via(): string
    {
        return $this->via;
    }

    public function key(): string
    {
        return $this->source . "\0" . $this->relation->value . "\0" . $this->target;
    }

    /**
     * @return list<int> ascending
     */
    public function lines(): array
    {
        return $this->lines === '' ? [] : array_map('intval', explode(',', $this->lines));
    }

    /**
     * The same relation written again elsewhere in the source: its lines join this edge's. Adding a line twice
     * changes nothing, so a rebuild replaying the edge keeps it as it is.
     */
    public function addLines(self $other): void
    {
        // The named arguments of every call site: `named: severity, path`.
        if (str_starts_with($other->via, 'named: ') && $other->via !== $this->via) {
            $names = array_unique([...explode(', ', substr($this->via, 7)), ...explode(', ', substr($other->via, 7))]);
            $this->via = 'named: ' . implode(', ', array_filter($names, static fn (string $name): bool => $name !== ''));
        }
        if ($other->lines === '' || $other->lines === $this->lines) {
            return;
        }
        $lines = array_values(array_unique([...$this->lines(), ...$other->lines()]));
        sort($lines);
        $this->lines = implode(',', $lines);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'target' => $this->target,
            'relation' => $this->relation->value,
            'confidence' => $this->confidence->value,
        ] + ($this->lines === '' ? [] : ['lines' => $this->lines]) + ($this->via === '' ? [] : ['via' => $this->via]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['source'],
            (string) $data['target'],
            Relation::from((string) $data['relation']),
            Confidence::from((string) $data['confidence']),
            \is_string($data['lines'] ?? null) ? $data['lines'] : '',
            \is_string($data['via'] ?? null) ? $data['via'] : '',
        );
    }
}
