<?php

declare(strict_types=1);

namespace PhpGraph\Graph;

use PhpGraph\Values;

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
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Values::text($data['id'] ?? null),
            Values::text($data['label'] ?? null),
            NodeKind::from(Values::text($data['kind'] ?? null)),
            Values::optionalText($data['file'] ?? null),
            Values::optionalNumber($data['line'] ?? null),
            Values::optionalText($data['service'] ?? null),
        );
    }
}
