<?php

declare(strict_types=1);

namespace PhpGraph\Graph;

final readonly class Node
{
    public function __construct(
        public string $id,
        public string $label,
        public NodeKind $kind,
        public ?string $file = null,
        public ?int $line = null,
        public ?string $service = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'kind' => $this->kind->value,
            'file' => $this->file,
            'line' => $this->line,
        ] + ($this->service === null ? [] : ['service' => $this->service]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (string) $data['label'],
            NodeKind::from((string) $data['kind']),
            isset($data['file']) ? (string) $data['file'] : null,
            isset($data['line']) ? (int) $data['line'] : null,
            isset($data['service']) ? (string) $data['service'] : null,
        );
    }
}
