<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

use PhpGraph\Extractor\PendingRequest;
use PhpGraph\Extractor\RouteFact;
use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Edge;
use PhpGraph\Graph\Graph;
use PhpGraph\Graph\Node;
use PhpGraph\Graph\NodeKind;
use PhpGraph\Graph\Relation;

/**
 * Routes become nodes handled by their controller (`handled_by`, EXTRACTED: read in an attribute or a routing
 * file); HTTP calls are linked to the routes their path matches (`requests`), in any service.
 *
 * A call is INFERRED when it goes through an HTTP client and its path is written in full; AMBIGUOUS when part of
 * the path is computed, when several routes match, or when only an absolute URL says it is an HTTP call.
 */
final class HttpResolver
{
    /**
     * Types HTTP calls go through: Symfony HttpClientInterface, Guzzle, PSR-18, Laravel's PendingRequest.
     */
    private const CLIENT = '/(HttpClient|ClientInterface|GuzzleHttp\\\\Client|PendingRequest|Http\\\\Client\\\\Factory)$/';

    private const MAX_MATCHES = 5;

    /** @var array<string, array{list<string>, list<string>, string}> route id => [methods, path segments, service] */
    private array $routes = [];

    public function __construct(
        private readonly Graph $graph,
        private readonly NameCanonicalizer $names,
        private readonly TypeResolver $types,
        private readonly ?ContainerServices $container = null,
    ) {
    }

    /**
     * @param list<array{RouteFact, string}>     $routes   with the service declaring them
     * @param list<array{PendingRequest, bool}>  $requests with whether the file is test code
     */
    public function resolve(array $routes, array $requests): HttpStats
    {
        $withHandler = [];
        $inDependencies = [];
        foreach ($routes as [$route, $service]) {
            $id = NameCanonicalizer::qualify($route->id(), $service);
            if (!$this->graph->hasNode($id)) {
                $this->graph->addNode(new Node($id, $route->label(), NodeKind::Route, $route->file, $route->line, $service === '' ? null : $service));
                $this->routes[$id] = [$route->methods, $this->segments($route->path), $service];
            }

            $class = $this->controllerClass($route, $service);
            $handler = $class === null ? null : $this->handler($class, $route->action);
            if ($handler !== null) {
                $this->graph->addEdge(new Edge($id, $handler, Relation::HandledBy, Confidence::Extracted));
                $withHandler[$id] = true;
            } elseif ($class !== null && $this->graph->node($class) === null) {
                // A controller class of a dependency (a generic CRUD controller, for example): outside the project.
                $inDependencies[$id] = true;
            }
        }

        $calls = $toProject = $toServices = 0;
        $mismatches = [];
        foreach ($requests as [$request, $inTests]) {
            $throughClient = $this->isClientCall($request);
            if (!$throughClient && !$request->absolute) {
                continue;
            }

            $matches = $this->matches($request);
            // The path of a route, but not its method: the call means it, and would fail as written.
            if ($matches === [] && $request->httpMethod !== null) {
                $sameMethodless = $this->matches($request, false);
                foreach (\array_slice($sameMethodless, 0, self::MAX_MATCHES) as $route) {
                    $this->graph->addEdge(new Edge($this->names->canonical($request->source), $route, Relation::Requests, Confidence::Ambiguous));
                }
                if ($sameMethodless !== [] && !$inTests) {
                    $mismatches[] = \sprintf('%s %s, declared for %s', $request->httpMethod, $request->path, $this->graph->node($sameMethodless[0])->label ?? '?');
                }
            }
            $confidence = \count($matches) === 1 && $throughClient && $request->literal ? Confidence::Inferred : Confidence::Ambiguous;
            $source = $this->names->canonical($request->source);
            foreach (\array_slice($matches, 0, self::MAX_MATCHES) as $route) {
                $this->graph->addEdge(new Edge($source, $route, Relation::Requests, $confidence));
            }

            if (!$inTests) {
                ++$calls;
                if ($matches !== []) {
                    ++$toProject;
                    if ($this->routes[$matches[0]][2] !== '' && $this->routes[$matches[0]][2] !== $this->serviceOf($source)) {
                        ++$toServices;
                    }
                }
            }
        }

        return new HttpStats(
            \count($this->routes),
            \count($withHandler),
            $calls,
            $toProject,
            $toServices,
            \count($inDependencies),
            \count($mismatches),
            \array_slice($mismatches, 0, 5),
        );
    }

    /**
     * The controller class: written in the route, or named by a container service id.
     */
    private function controllerClass(RouteFact $route, string $service): ?string
    {
        $class = $route->controller;
        if ($class === null && $route->serviceId !== null) {
            $class = $this->container?->classOf($route->serviceId, $service);
        }

        return $class === null ? null : $this->names->canonical($class, $service);
    }

    private function handler(string $class, ?string $action): ?string
    {
        $method = $this->types->findMethod($class, strtolower($action ?? '__invoke'));
        if ($method !== null && $this->graph->hasNode($method)) {
            return $method;
        }

        return $this->graph->node($class)?->kind->isClassLike() === true ? $class : null;
    }

    private function isClientCall(PendingRequest $request): bool
    {
        if ($request->staticClass !== null) {
            return $this->names->canonical($request->staticClass) === 'Illuminate\Support\Facades\Http';
        }

        $receiver = $request->receiver === null ? null : $this->types->resolve($request->receiver);
        if ($receiver === null) {
            return false;
        }

        foreach ($this->types->lineage($receiver) as $type) {
            if (preg_match(self::CLIENT, $type) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Routes whose method and path match: `{id}` in a route and `*` in a call match any segment.
     *
     * @return list<string> route ids
     */
    private function matches(PendingRequest $request, bool $sameMethod = true): array
    {
        $segments = $this->segments($request->path);
        $matches = [];
        foreach ($this->routes as $id => [$methods, $routeSegments]) {
            if ($sameMethod && $request->httpMethod !== null && $methods !== [] && !\in_array($request->httpMethod, $methods, true)) {
                continue;
            }
            if (\count($routeSegments) !== \count($segments)) {
                continue;
            }
            foreach ($segments as $index => $segment) {
                if (!$this->segmentMatches($routeSegments[$index], $segment)) {
                    continue 2;
                }
            }
            $matches[] = $id;
        }

        // The most specific route first: /products/info before /products/{id}.
        usort($matches, fn (string $a, string $b): int => $this->placeholders($a) <=> $this->placeholders($b));

        return $matches;
    }

    private function placeholders(string $route): int
    {
        return \count(array_filter($this->routes[$route][1], static fn (string $segment): bool => str_contains($segment, '{')));
    }

    private function segmentMatches(string $route, string $call): bool
    {
        if ($call === '*' || $route === $call || preg_match('/^\{[^}]*\}$/', $route) === 1) {
            return true;
        }

        // A segment partly computed in the call (`order-*`) or partly a placeholder in the route (`{id}.json`).
        $pattern = '/^' . str_replace(['\*', '\{', '\}'], ['.*', '{', '}'], preg_quote($call, '/')) . '$/';
        $routePattern = '/^' . preg_replace('/\\\\\{[^}]*\\\\\}/', '[^/]+', preg_quote($route, '/')) . '$/';

        return preg_match($pattern, $route) === 1 || preg_match($routePattern, $call) === 1;
    }

    /**
     * @return list<string>
     */
    private function segments(string $path): array
    {
        return array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));
    }

    private function serviceOf(string $id): string
    {
        $at = strpos($id, '@');

        return $at === false ? '' : substr($id, 0, $at);
    }
}
