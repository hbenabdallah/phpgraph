<?php

declare(strict_types=1);

namespace PhpGraph\Project;

/**
 * How a graph is built. Saved with the graph so a rebuild uses the same options.
 */
final readonly class BuildOptions
{
    /**
     * @param list<string> $excludes   path patterns left out of the build
     * @param bool         $readVendor read dependency signatures from vendor/
     * @param int          $depth      namespace depth of the cross-boundary report
     */
    public function __construct(
        public array $excludes = [],
        public bool $readVendor = true,
        public int $depth = 2,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $excludes = \is_array($data['excludes'] ?? null) ? array_values(array_filter($data['excludes'], 'is_string')) : [];

        return new self(
            $excludes,
            \is_bool($data['readVendor'] ?? null) ? $data['readVendor'] : true,
            \is_int($data['depth'] ?? null) ? max(1, $data['depth']) : 2,
        );
    }

    /**
     * @return array{excludes: list<string>, readVendor: bool, depth: int}
     */
    public function toArray(): array
    {
        return ['excludes' => $this->excludes, 'readVendor' => $this->readVendor, 'depth' => $this->depth];
    }
}
