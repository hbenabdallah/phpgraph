<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

/**
 * HTTP routes of the project and HTTP calls from application code.
 */
final readonly class HttpStats
{
    /**
     * @param int $routes             routes declared (attributes, Laravel route files, YAML routing files)
     * @param int $routesWithHandler  routes linked to a controller of the project
     * @param int $requests           HTTP calls found in application code
     * @param int $requestsToProject  calls linked to a route of the project
     * @param int $requestsToServices calls linked to a route of another service
     * @param int $routesToDependencies routes whose controller is a class of a dependency
     * @param list<string> $methodMismatches calls whose path matches a route declared for other methods only (first ones)
     * @param int $routesToMissingControllers routes whose controller class is declared neither in the project nor in a dependency
     * @param list<string> $missingControllers those routes, controllers and routing files (first ones)
     */
    public function __construct(
        public int $routes = 0,
        public int $routesWithHandler = 0,
        public int $requests = 0,
        public int $requestsToProject = 0,
        public int $requestsToServices = 0,
        public int $routesToDependencies = 0,
        public int $methodMismatchCount = 0,
        public array $methodMismatches = [],
        public int $routesToMissingControllers = 0,
        public array $missingControllers = [],
    ) {
    }

    /**
     * @param array<mixed> $data as written by toArray()
     */
    public static function fromArray(array $data): self
    {
        $int = static fn (string $key): int => \is_int($data[$key] ?? null) ? $data[$key] : 0;

        $strings = static fn (string $key): array => array_values(array_filter(\is_array($data[$key] ?? null) ? $data[$key] : [], 'is_string'));

        return new self(
            $int('routes'),
            $int('routesWithHandler'),
            $int('requests'),
            $int('requestsToProject'),
            $int('requestsToServices'),
            $int('routesToDependencies'),
            $int('methodMismatchCount'),
            $strings('methodMismatches'),
            $int('routesToMissingControllers'),
            $strings('missingControllers'),
        );
    }

    /**
     * @return array<string, int|list<string>>
     */
    public function toArray(): array
    {
        return [
            'routes' => $this->routes,
            'routesWithHandler' => $this->routesWithHandler,
            'requests' => $this->requests,
            'requestsToProject' => $this->requestsToProject,
            'requestsToServices' => $this->requestsToServices,
            'routesToDependencies' => $this->routesToDependencies,
            'methodMismatchCount' => $this->methodMismatchCount,
            'methodMismatches' => $this->methodMismatches,
            'routesToMissingControllers' => $this->routesToMissingControllers,
            'missingControllers' => $this->missingControllers,
        ];
    }
}
