<?php

declare(strict_types=1);

namespace PhpGraph\Graph;

final readonly class Edge
{
    public function __construct(
        public string $source,
        public string $target,
        public Relation $relation,
        public Confidence $confidence = Confidence::Extracted,
    ) {
    }

    public function key(): string
    {
        return $this->source . "\0" . $this->relation->value . "\0" . $this->target;
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
        ];
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
        );
    }
}
