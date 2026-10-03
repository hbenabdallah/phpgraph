<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

use PhpGraph\Graph\Graph;

final readonly class BuildResult
{
    /**
     * @param array<string, string>       $failures   file => parse error
     * @param array<string, list<string>> $duplicates node id declared more than once => files declaring it
     * @param CallStats                   $calls      every call site
     * @param CallStats                   $testCalls  the call sites in test code, a part of $calls
     * @param int                         $vendorFilesRead dependency files parsed for their signatures
     * @param list<string>                $composerFiles   composer.json files of the project, relative paths
     * @param int                         $vendorDirectories installed vendor/ directories found next to them
     * @param array<string, int>          $mostUnresolvedMethods method names most often called on an unknown receiver in application code
     * @param list<string>                $services        services of a multi-service repository, empty otherwise
     * @param int                         $phpFilesNotRead PHP files of the directory, when the build read none of them
     */
    public function __construct(
        public Graph $graph,
        public int $filesParsed,
        public array $failures,
        public array $duplicates = [],
        public CallStats $calls = new CallStats(),
        public CallStats $testCalls = new CallStats(),
        public int $vendorFilesRead = 0,
        public array $composerFiles = [],
        public int $vendorDirectories = 0,
        public array $mostUnresolvedMethods = [],
        public BusStats $bus = new BusStats(),
        public array $services = [],
        public HttpStats $http = new HttpStats(),
        public ?BuildState $state = null,
        public int $phpFilesNotRead = 0,
        public InjectionStats $injections = new InjectionStats(),
    ) {
    }

    /**
     * The calls made from application code, without the test code (see TestFiles).
     */
    public function applicationCalls(): CallStats
    {
        return $this->calls->minus($this->testCalls);
    }
}
