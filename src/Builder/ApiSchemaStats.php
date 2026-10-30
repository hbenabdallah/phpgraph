<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

use PhpGraph\Values;

/**
 * The API schemas of the project, OpenAPI documents and protobuf services, and how far the code implements them.
 */
final readonly class ApiSchemaStats
{
    /**
     * @param int          $documents           OpenAPI documents read
     * @param int          $operations          the operations (a method on a path) of those the project serves
     * @param int          $implemented         operations linked to a route of the project
     * @param list<string> $notImplemented      the first operations no route of the project declares
     * @param int          $routesCovered       routes of the services the documents describe
     * @param int          $undocumentedCount   those routes no operation describes
     * @param list<string> $undocumented        the first of them
     * @param int          $rpcs                rpc methods of the protobuf services
     * @param int          $rpcsImplemented     those a class of the project implements
     * @param list<string> $rpcsNotImplemented  the first of the others
     * @param int          $otherApis           OpenAPI documents no route implements: other APIs, the code may call
     * @param int          $requests            HTTP calls of application code linked to an operation of a document
     */
    public function __construct(
        public int $documents = 0,
        public int $operations = 0,
        public int $implemented = 0,
        public array $notImplemented = [],
        public int $routesCovered = 0,
        public int $undocumentedCount = 0,
        public array $undocumented = [],
        public int $rpcs = 0,
        public int $rpcsImplemented = 0,
        public array $rpcsNotImplemented = [],
        public int $otherApis = 0,
        public int $requests = 0,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->documents === 0 && $this->rpcs === 0;
    }

    /**
     * @param array<mixed> $data as written by toArray()
     */
    public static function fromArray(array $data): self
    {
        $strings = static fn (string $key): array => array_values(array_filter(\is_array($data[$key] ?? null) ? $data[$key] : [], 'is_string'));

        return new self(
            Values::number($data['documents'] ?? 0),
            Values::number($data['operations'] ?? 0),
            Values::number($data['implemented'] ?? 0),
            $strings('notImplemented'),
            Values::number($data['routesCovered'] ?? 0),
            Values::number($data['undocumentedCount'] ?? 0),
            $strings('undocumented'),
            Values::number($data['rpcs'] ?? 0),
            Values::number($data['rpcsImplemented'] ?? 0),
            $strings('rpcsNotImplemented'),
            Values::number($data['otherApis'] ?? 0),
            Values::number($data['requests'] ?? 0),
        );
    }

    /**
     * @return array{
     *     documents: int, operations: int, implemented: int, notImplemented: list<string>, routesCovered: int,
     *     undocumentedCount: int, undocumented: list<string>, rpcs: int, rpcsImplemented: int,
     *     rpcsNotImplemented: list<string>, otherApis: int, requests: int,
     * }
     */
    public function toArray(): array
    {
        return [
            'documents' => $this->documents,
            'operations' => $this->operations,
            'implemented' => $this->implemented,
            'notImplemented' => $this->notImplemented,
            'routesCovered' => $this->routesCovered,
            'undocumentedCount' => $this->undocumentedCount,
            'undocumented' => $this->undocumented,
            'rpcs' => $this->rpcs,
            'rpcsImplemented' => $this->rpcsImplemented,
            'rpcsNotImplemented' => $this->rpcsNotImplemented,
            'otherApis' => $this->otherApis,
            'requests' => $this->requests,
        ];
    }
}
