<?php

declare(strict_types=1);

namespace PhpGraph\Project;

use PhpGraph\Builder\ActionStats;
use PhpGraph\Builder\ApiSchemaStats;
use PhpGraph\Builder\BuildResult;
use PhpGraph\Builder\BusStats;
use PhpGraph\Builder\CallStats;
use PhpGraph\Builder\HttpStats;
use PhpGraph\Builder\InjectionStats;

/**
 * What a build learnt beyond the graph itself, saved with it for the overview: stack, call resolution and gaps.
 */
final readonly class ProjectSummary
{
    /**
     * @param list<array{directory: string, php: ?string, locked: bool, packages: array<string, array{version: string, role: string}>}> $stack
     * @param list<string>       $duplicates            names declared more than once (first ones)
     * @param array<string, int> $mostUnresolvedMethods
     * @param list<string>       $services              services of a multi-service repository, empty otherwise
     * @param int                $phpFilesNotRead       PHP files of the directory, when the build read none of them
     * @param array<string, int> $otherLanguages        source files in other languages, by language: not in the graph
     */
    public function __construct(
        public string $generatedAt,
        public int $filesParsed,
        public int $filesFailed,
        public int $duplicateCount,
        public array $duplicates,
        public int $vendorDirectories,
        public int $vendorFilesRead,
        public BuildOptions $options,
        public CallStats $applicationCalls,
        public CallStats $testCalls,
        public array $mostUnresolvedMethods,
        public array $stack,
        public BusStats $bus = new BusStats(),
        public array $services = [],
        public HttpStats $http = new HttpStats(),
        public int $phpFilesNotRead = 0,
        public InjectionStats $injections = new InjectionStats(),
        public array $otherLanguages = [],
        public ApiSchemaStats $apiSchemas = new ApiSchemaStats(),
        public ActionStats $actions = new ActionStats(),
    ) {
    }

    /**
     * @param list<array{directory: string, php: ?string, locked: bool, packages: array<string, array{version: string, role: string}>}> $stack
     * @param array<string, int> $otherLanguages
     */
    public static function fromBuild(BuildResult $result, BuildOptions $options, array $stack, array $otherLanguages = []): self
    {
        return new self(
            gmdate('c'),
            $result->filesParsed,
            \count($result->failures),
            \count($result->duplicates),
            \array_slice(array_keys($result->duplicates), 0, 10),
            $result->vendorDirectories,
            $result->vendorFilesRead,
            $options,
            $result->applicationCalls(),
            $result->testCalls,
            $result->mostUnresolvedMethods,
            $stack,
            $result->bus,
            $result->services,
            $result->http,
            $result->phpFilesNotRead,
            $result->injections,
            $otherLanguages,
            $result->apiSchemas,
            $result->actions,
        );
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        if (!\is_array($data['applicationCalls'] ?? null) || !\is_array($data['testCalls'] ?? null)) {
            return null;
        }

        $int = static fn (string $key): int => \is_int($data[$key] ?? null) ? $data[$key] : 0;
        $list = static fn (string $key): array => \is_array($data[$key] ?? null) ? $data[$key] : [];

        /** @var list<array{directory: string, php: ?string, locked: bool, packages: array<string, array{version: string, role: string}>}> $stack */
        $stack = $list('stack');
        /** @var list<string> $duplicates */
        $duplicates = array_values(array_filter($list('duplicates'), 'is_string'));
        /** @var array<string, int> $unresolved */
        $unresolved = array_filter($list('mostUnresolvedMethods'), 'is_int');
        /** @var array<string, int> $otherLanguages */
        $otherLanguages = array_filter($list('otherLanguages'), 'is_int');

        return new self(
            \is_string($data['generatedAt'] ?? null) ? $data['generatedAt'] : '',
            $int('filesParsed'),
            $int('filesFailed'),
            $int('duplicateCount'),
            $duplicates,
            $int('vendorDirectories'),
            $int('vendorFilesRead'),
            BuildOptions::fromArray($list('options')),
            CallStats::fromArray($data['applicationCalls']),
            CallStats::fromArray($data['testCalls']),
            $unresolved,
            $stack,
            BusStats::fromArray($list('bus')),
            array_values(array_filter($list('services'), 'is_string')),
            HttpStats::fromArray($list('http')),
            $int('phpFilesNotRead'),
            InjectionStats::fromArray($list('injections')),
            $otherLanguages,
            ApiSchemaStats::fromArray($list('apiSchemas')),
            ActionStats::fromArray($list('actions')),
        );
    }

    /**
     * @return array{
     *     generatedAt: string, filesParsed: int, filesFailed: int, duplicateCount: int, duplicates: list<string>,
     *     vendorDirectories: int, vendorFilesRead: int,
     *     options: array{excludes: list<string>, readVendor: bool, depth: int},
     *     applicationCalls: array{total: int, inferred: int, ambiguous: int, outsideProject: int, unknownReceiver: int, chainOutsideProject: int},
     *     testCalls: array{total: int, inferred: int, ambiguous: int, outsideProject: int, unknownReceiver: int, chainOutsideProject: int},
     *     mostUnresolvedMethods: array<string, int>,
     *     stack: list<array{directory: string, php: ?string, locked: bool, packages: array<string, array{version: string, role: string}>}>,
     *     bus: array{
     *         handlers: array<string, int>, sends: array<string, int>, untypedSends: int,
     *         messagesWithoutHandlerCount: int, messagesWithoutHandler: list<string>,
     *         messagesNeverSentCount: int, messagesNeverSent: list<string>, contracts: int,
     *     },
     *     services: list<string>,
     *     http: array{
     *         routes: int, routesWithHandler: int, requests: int, requestsToProject: int, requestsToServices: int,
     *         routesToDependencies: int, methodMismatchCount: int, methodMismatches: list<string>,
     *         routesToMissingControllers: int, missingControllers: list<string>,
     *     },
     *     phpFilesNotRead: int,
     *     injections: array{injections: int, linked: int, edges: int, unlinkedCount: int, unlinked: list<string>},
     *     otherLanguages: array<string, int>,
     *     apiSchemas: array{
     *         documents: int, operations: int, implemented: int, notImplemented: list<string>, routesCovered: int,
     *         undocumentedCount: int, undocumented: list<string>, rpcs: int, rpcsImplemented: int,
     *         rpcsNotImplemented: list<string>, otherApis: int, requests: int,
     *     },
     *     actions: array{
     *         actions: int, linked: int, toClass: int, unlinkedCount: int, unlinked: list<string>, forwards: int,
     *         forwardsLinked: int, templates: int, unresolvedTargetCount: int, unresolvedTargets: list<string>,
     *         citations: int, citingFiles: int, unreadable: list<string>,
     *     },
     * }
     */
    public function toArray(): array
    {
        return [
            'generatedAt' => $this->generatedAt,
            'filesParsed' => $this->filesParsed,
            'filesFailed' => $this->filesFailed,
            'duplicateCount' => $this->duplicateCount,
            'duplicates' => $this->duplicates,
            'vendorDirectories' => $this->vendorDirectories,
            'vendorFilesRead' => $this->vendorFilesRead,
            'options' => $this->options->toArray(),
            'applicationCalls' => $this->applicationCalls->toArray(),
            'testCalls' => $this->testCalls->toArray(),
            'mostUnresolvedMethods' => $this->mostUnresolvedMethods,
            'stack' => $this->stack,
            'bus' => $this->bus->toArray(),
            'services' => $this->services,
            'http' => $this->http->toArray(),
            'phpFilesNotRead' => $this->phpFilesNotRead,
            'injections' => $this->injections->toArray(),
            'otherLanguages' => $this->otherLanguages,
            'apiSchemas' => $this->apiSchemas->toArray(),
            'actions' => $this->actions->toArray(),
        ];
    }
}
