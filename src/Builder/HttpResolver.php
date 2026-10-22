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
 *
 * The path of a call is relative to the base URL its client is given (`Http::baseUrl()`, in the chain or in the
 * method returning the client). A route restricted to a host (`Route::domain()`) is reached only by calls to that
 * host: a call to another host is not linked, one whose host is unknown is AMBIGUOUS.
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

    /** @var array<string, ?string> route id => the host it is restricted to */
    private array $hosts = [];

    /** @var list<array{string, ?string, list<string>, bool, bool}> calls reaching no route: source, method, path segments, sure, in tests */
    private array $unmatched = [];

    public function __construct(
        private readonly Graph $graph,
        private readonly NameCanonicalizer $names,
        private readonly TypeResolver $types,
        private readonly ?ContainerServices $container = null,
        /** @var array<string, string> method id => the base URL of the HTTP client it returns */
        private readonly array $baseUrls = [],
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
        $missing = [];
        foreach ($routes as [$route, $service]) {
            $id = NameCanonicalizer::qualify($route->id(), $service);
            if (!$this->graph->hasNode($id)) {
                $this->graph->addNode(new Node($id, $route->label(), NodeKind::Route, $route->file, $route->line, $service === '' ? null : $service));
                $this->routes[$id] = [$route->methods, $this->segments($route->path), $service];
                $this->hosts[$id] = $route->host;
            }

            $class = $this->controllerClass($route, $service);
            $handler = $class === null ? null : $this->handler($class, $route->action);
            // The classes the declaration names besides its handler: an API Platform resource, its input and output.
            foreach ($route->references as $reference) {
                $this->graph->addEdge(new Edge($id, $this->names->canonical($reference, $service), Relation::References, Confidence::Extracted));
            }
            if ($handler !== null) {
                $this->graph->addEdge(new Edge($id, $handler, Relation::HandledBy, Confidence::Extracted));
                $withHandler[$id] = true;
            } elseif ($class === null && $route->byFramework) {
                // API Platform's own state provider and processor (Doctrine): outside the project.
                $inDependencies[$id] = true;
            } elseif ($class !== null && $this->types->knows($class)) {
                // A controller class of a dependency (a generic CRUD controller, for example): outside the project.
                $inDependencies[$id] = true;
            } elseif ($class !== null) {
                // Declared nowhere: a stale route, or a controller in code the build did not read.
                $missing[$id] = \sprintf('%s -> %s (%s:%d)', $route->label(), ltrim($class, '\\'), $route->file, $route->line);
            }
        }

        $calls = $toProject = $toServices = 0;
        $mismatches = [];
        foreach ($requests as [$request, $inTests]) {
            $throughClient = $this->isClientCall($request);
            if (!$throughClient && !$request->absolute) {
                continue;
            }

            [$path, $host, $literal] = $this->target($request);
            $matches = $this->matches($request->httpMethod, $path, $host);
            $sameMethodless = [];
            // The path of a route, but not its method: the call means it, and would fail as written.
            if ($matches === [] && $request->httpMethod !== null) {
                $sameMethodless = $this->matches(null, $path, $host);
                foreach (\array_slice($sameMethodless, 0, self::MAX_MATCHES) as $route) {
                    $this->graph->addEdge(new Edge($this->names->canonical($request->source), $route, Relation::Requests, Confidence::Ambiguous));
                }
                if ($sameMethodless !== [] && !$inTests) {
                    $mismatches[] = \sprintf('%s %s, declared for %s', $request->httpMethod, $path, $this->graph->node($sameMethodless[0])->label ?? '?');
                }
            }
            // A route restricted to a host, called without one known: maybe another host.
            $hostSure = $matches === [] || $this->hosts[$matches[0]] === null || ($host !== null && !str_contains($host, '*'));
            $confidence = \count($matches) === 1 && $throughClient && $literal && $hostSure ? Confidence::Inferred : Confidence::Ambiguous;
            $source = $this->names->canonical($request->source);
            if ($matches === [] && $sameMethodless === []) {
                $this->unmatched[] = [$source, $request->httpMethod, $this->segments($path), $throughClient && $literal, $inTests];
            }
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
            \count($missing),
            \array_slice(array_values($missing), 0, 10),
        );
    }

    /**
     * The HTTP calls reaching no route of the project: another API's, perhaps described by a document.
     *
     * @return list<array{string, ?string, list<string>, bool, bool}> source, method, path segments, written in full
     *                                                                 through a client, in test code
     */
    public function unmatchedRequests(): array
    {
        return $this->unmatched;
    }

    /**
     * The routes resolved: id => their methods, path segments and service.
     *
     * @return array<string, array{list<string>, list<string>, string}>
     */
    public function routes(): array
    {
        return $this->routes;
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
     * The path a call reaches, from the root of its host, the host when known, and whether the path is written in
     * full: relative to the base URL its client is given, unless the call names an absolute URL.
     *
     * @return array{string, ?string, bool}
     */
    private function target(PendingRequest $request): array
    {
        $base = $request->base;
        if ($base === null && $request->receiver?->member !== null && !$request->receiver->isProperty && $request->receiver->receiver !== null) {
            // The client a method of the project returns: `$this->client()->get('/labels')`.
            $class = $this->types->resolve($request->receiver->receiver);
            $method = $class === null ? null : $this->types->findMethod($class, strtolower($request->receiver->member));
            $base = $method === null ? null : $this->baseUrls[$method] ?? null;
        }
        if ($request->absolute || $base === null) {
            return [$request->path, $request->host, $request->literal];
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://([^/:?\#]*)[^/?\#]*([^?\#]*)#i', $base, $match) === 1) {
            return [RouteFact::normalizePath($match[2] . '/' . $request->path), strtolower($match[1]), $request->literal && !str_contains($match[2], '*')];
        }
        // A base computed at runtime (`config('services.billing.url')`): its host and path are unknown.
        if (str_starts_with($base, '*')) {
            return [$request->path, null, false];
        }

        return [RouteFact::normalizePath($base . '/' . $request->path), null, $request->literal];
    }

    /**
     * Routes whose method, path and host match: `{id}` in a route and `*` in a call match any segment; a route
     * restricted to a host is not reached by a call to another one.
     *
     * @param ?string $method null for any
     *
     * @return list<string> route ids
     */
    private function matches(?string $method, string $path, ?string $host): array
    {
        $segments = $this->segments($path);
        $matches = [];
        foreach ($this->routes as $id => [$methods, $routeSegments]) {
            if ($method !== null && $methods !== [] && !\in_array($method, $methods, true)) {
                continue;
            }
            if (!self::hostMatches($this->hosts[$id] ?? null, $host)) {
                continue;
            }
            if (\count($routeSegments) !== \count($segments)) {
                continue;
            }
            foreach ($segments as $index => $segment) {
                if (!self::segmentMatches($routeSegments[$index], $segment)) {
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

    /**
     * A route for any host, a call to an unknown host, or the same host: `{account}.example.com` and `*` match any
     * label.
     */
    private static function hostMatches(?string $route, ?string $call): bool
    {
        if ($route === null || $call === null || $route === '*' || $call === '*') {
            return true;
        }
        $labels = explode('.', $route);
        $callLabels = explode('.', $call);
        if (\count($labels) !== \count($callLabels)) {
            return false;
        }
        foreach ($labels as $index => $label) {
            if (!self::segmentMatches($label, $callLabels[$index])) {
                return false;
            }
        }

        return true;
    }

    public static function segmentMatches(string $route, string $call): bool
    {
        if ($call === '*' || $route === $call || preg_match('/^\{[^}]*\}$/', $route) === 1) {
            return true;
        }

        // A segment partly computed in the call (`order-*`) or partly a placeholder in the route (`{id}.json`).
        $pattern = '/^' . str_replace(['\*', '\{', '\}'], ['.*', '{', '}'], preg_quote($call, '/')) . '$/';
        $routePattern = '/^' . preg_replace('/\\\\\{[^}]*\\\\\}/', '[^\\/]+', preg_quote($route, '/')) . '$/';

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
