<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Edge;
use PhpGraph\Graph\Graph;
use PhpGraph\Graph\Node;
use PhpGraph\Graph\NodeKind;
use PhpGraph\Graph\Relation;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The API schemas of the project as contracts: the operations of OpenAPI documents, linked to the routes declaring
 * their method and path, and the rpc methods of protobuf services, linked to the classes implementing the service
 * generated from them. Read as files, never run; what no route or class implements is reported.
 */
final class ApiSchemas
{
    private const HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'];

    private const LISTED = 10;

    /** @var array<string, list<string>>|null lowercase name without service => interface node ids */
    private ?array $interfaces = null;

    public function __construct(private readonly Graph $graph)
    {
    }

    /**
     * @param list<string>                                             $files    OpenAPI documents and protobuf files
     * @param array<string, array{list<string>, list<string>, string}> $routes   route id => methods, path segments, service
     * @param list<array{string, ?string, list<string>, bool, bool}>    $requests HTTP calls reaching no route: source,
     *                                                                            method, path segments, sure, in tests
     */
    public function resolve(string $root, array $files, array $routes, ServiceMap $services, array $requests = []): ApiSchemaStats
    {
        $documents = $operations = $implemented = $rpcs = $rpcsImplemented = $otherApis = 0;
        $notImplemented = $rpcsNotImplemented = [];
        $covered = $documented = [];
        /** @var list<array{string, string, list<string>}> $described operation id, method, path segments */
        $described = [];

        foreach ($files as $file) {
            $content = @file_get_contents($root . '/' . $file);
            if (!\is_string($content)) {
                continue;
            }
            $service = $services->serviceOf($file);

            if (str_ends_with($file, '.proto')) {
                foreach (self::protoServices($content) as $protoService) {
                    foreach ($protoService['rpcs'] as [$rpc, $line]) {
                        ++$rpcs;
                        $id = NameCanonicalizer::qualify('operation:' . $file . '#' . $protoService['package'] . $protoService['name'] . '/' . $rpc, $service);
                        $this->graph->addNode(new Node($id, $protoService['name'] . '.' . $rpc, NodeKind::Operation, $file, $line, $service === '' ? null : $service));
                        if ($this->linkRpc($id, $protoService, $rpc)) {
                            ++$rpcsImplemented;
                        } else {
                            $rpcsNotImplemented[] = \sprintf('%s.%s (%s:%d)', $protoService['name'], $rpc, $file, $line);
                        }
                    }
                }
                continue;
            }

            $document = self::openApiOperations($file, $content);
            if ($document === null) {
                continue;
            }
            ++$documents;
            // A document at the root describes every service (an API gateway); in a service, that service only.
            $scope = $service === '' || $service === ServiceMap::ROOT ? null : $service;
            $served = false;
            $missing = [];
            foreach ($document as [$method, $path, $line]) {
                $label = strtoupper($method) . ' ' . $path;
                $id = NameCanonicalizer::qualify('operation:' . $file . '#' . $label, $service);
                $this->graph->addNode(new Node($id, $label, NodeKind::Operation, $file, $line, $service === '' ? null : $service));
                $described[] = [$id, strtoupper($method), self::segments($path)];
                $matches = $this->matchingRoutes($routes, strtoupper($method), $path, $scope);
                foreach ($matches as $route) {
                    $this->graph->addEdge(new Edge($id, $route, Relation::ImplementedBy, \count($matches) === 1 ? Confidence::Inferred : Confidence::Ambiguous));
                    $documented[$route] = true;
                }
                if ($matches !== []) {
                    ++$implemented;
                    $served = true;
                } else {
                    $missing[] = \sprintf('%s (%s:%d)', $label, $file, $line);
                }
            }
            // No route implements any of it: the document describes another API, one the code may call.
            if (!$served) {
                ++$otherApis;
                continue;
            }
            $operations += \count($document);
            array_push($notImplemented, ...$missing);
            foreach ($routes as $route => [, , $routeService]) {
                if ($scope === null || $routeService === $scope) {
                    $covered[$route] = true;
                }
            }
        }

        // Calls reaching no route of the project, to an API a document describes.
        $calls = 0;
        foreach ($requests as [$source, $method, $segments, $sure, $inTests]) {
            $matches = [];
            foreach ($described as [$operation, $operationMethod, $operationSegments]) {
                if (($method === null || $method === $operationMethod) && self::pathMatches($operationSegments, $segments)) {
                    $matches[] = $operation;
                }
            }
            foreach (\array_slice($matches, 0, 5) as $operation) {
                $this->graph->addEdge(new Edge($source, $operation, Relation::Requests, $sure && \count($matches) === 1 ? Confidence::Inferred : Confidence::Ambiguous));
            }
            if ($matches !== [] && !$inTests) {
                ++$calls;
            }
        }

        $undocumented = [];
        foreach (array_keys(array_diff_key($covered, $documented)) as $route) {
            $node = $this->graph->node($route);
            if ($node !== null) {
                $undocumented[] = \sprintf('%s%s (%s:%d)', $node->service === null ? '' : $node->service . ': ', $node->label, (string) $node->file, (int) $node->line);
            }
        }

        return new ApiSchemaStats(
            $documents,
            $operations,
            $implemented,
            \array_slice($notImplemented, 0, self::LISTED),
            \count($covered),
            \count($undocumented),
            \array_slice($undocumented, 0, self::LISTED),
            $rpcs,
            $rpcsImplemented,
            \array_slice($rpcsNotImplemented, 0, self::LISTED),
            $otherApis,
            $calls,
        );
    }

    /**
     * The operations of an OpenAPI (or Swagger 2) document: method, full path (with the server's or the base path)
     * and line; null when the file is no such document.
     *
     * @return list<array{string, string, int}>|null
     */
    public static function openApiOperations(string $file, string $content): ?array
    {
        try {
            $data = str_ends_with($file, '.json') ? json_decode($content, true) : Yaml::parse($content, Yaml::PARSE_CUSTOM_TAGS);
        } catch (ParseException) {
            return null;
        }
        if (!\is_array($data) || !\is_array($data['paths'] ?? null) || (!isset($data['openapi']) && !isset($data['swagger']))) {
            return null;
        }

        // Swagger 2 has a base path; OpenAPI 3 the path of its first server (`https://api.example.com/v1`, `/v1`).
        $base = \is_string($data['basePath'] ?? null) ? $data['basePath'] : '';
        $server = \is_array($data['servers'] ?? null) && \is_array($data['servers'][0] ?? null) ? $data['servers'][0]['url'] ?? null : null;
        if (\is_string($server) && !str_contains($server, '{')) {
            $base = (string) parse_url($server, \PHP_URL_PATH);
        }
        $base = rtrim($base, '/');

        $lines = preg_split('/\R/', $content) ?: [];
        $operations = [];
        foreach ($data['paths'] as $path => $item) {
            if (!\is_array($item)) {
                continue;
            }
            $line = self::lineOf($lines, (string) $path);
            foreach ($item as $method => $operation) {
                if (\is_string($method) && \in_array(strtolower($method), self::HTTP_METHODS, true) && \is_array($operation)) {
                    $operations[] = [strtolower($method), $base . '/' . ltrim((string) $path, '/'), $line];
                }
            }
        }

        return $operations;
    }

    /**
     * The services of a protobuf file: package, PHP namespace, name and rpc methods with their lines.
     *
     * @return list<array{package: string, namespace: ?string, name: string, rpcs: list<array{string, int}>}>
     */
    public static function protoServices(string $content): array
    {
        $package = preg_match('/^\s*package\s+([\w.]+)\s*;/m', $content, $match) === 1 ? $match[1] . '.' : '';
        $namespace = preg_match('/^\s*option\s+php_namespace\s*=\s*"([^"]+)"/m', $content, $match) === 1 ? stripslashes($match[1]) : null;

        $services = [];
        $rpcs = [];
        foreach (preg_split('/\R/', $content) ?: [] as $index => $line) {
            if (preg_match('/^\s*service\s+(\w+)\s*\{/', $line, $match) === 1) {
                $services[] = $match[1];
                $rpcs[] = [];
            } elseif ($services !== [] && preg_match('/^\s*rpc\s+(\w+)\s*\(/', $line, $match) === 1) {
                $rpcs[\count($rpcs) - 1][] = [$match[1], $index + 1];
            }
        }

        return array_map(static fn (string $name, array $methods): array => ['package' => $package, 'namespace' => $namespace, 'name' => $name, 'rpcs' => $methods], $services, $rpcs);
    }

    /**
     * Links an rpc to the methods implementing it: those of the classes implementing the interface generated for its
     * service (`CinemaServiceInterface`, in the file's PHP namespace or its package's), generated clients left out.
     *
     * @param array{package: string, namespace: ?string, name: string, rpcs: list<array{string, int}>} $service
     */
    private function linkRpc(string $operation, array $service, string $rpc): bool
    {
        $namespace = $service['namespace'] ?? implode('\\', array_map('ucfirst', array_filter(explode('.', $service['package']))));
        $interface = strtolower($namespace . '\\' . $service['name'] . 'Interface');

        if ($this->interfaces === null) {
            $this->interfaces = [];
            foreach ($this->graph->nodes() as $node) {
                if ($node->kind === NodeKind::PhpInterface) {
                    $name = str_contains($node->id, '@') ? substr($node->id, (int) strpos($node->id, '@') + 1) : $node->id;
                    $this->interfaces[strtolower($name)][] = $node->id;
                }
            }
        }

        $linked = false;
        foreach ($this->interfaces[$interface] ?? [] as $interfaceId) {
            foreach ($this->graph->incident($interfaceId) as $item) {
                if ($item['forward'] || $item['edge']->relation !== Relation::Implements || str_ends_with($item['other'], 'Client')) {
                    continue;
                }
                $method = $this->method($item['other'], $rpc);
                if ($method !== null) {
                    $this->graph->addEdge(new Edge($operation, $method, Relation::ImplementedBy, Confidence::Inferred));
                    $linked = true;
                }
            }
            // The interface method too: its callers are the clients of the rpc.
            $declared = $this->method($interfaceId, $rpc);
            if ($declared !== null) {
                $this->graph->addEdge(new Edge($operation, $declared, Relation::References, Confidence::Inferred));
            }
        }

        return $linked;
    }

    private function method(string $class, string $name): ?string
    {
        foreach ($this->graph->incident($class) as $item) {
            if ($item['forward'] && $item['edge']->relation === Relation::HasMethod && strcasecmp(substr($item['other'], (int) strrpos($item['other'], ':') + 1), $name) === 0) {
                return $item['other'];
            }
        }

        return null;
    }

    /**
     * The routes declaring a method and a path, placeholders matching whatever their names (`{hotel}`, `{id}`).
     *
     * @param array<string, array{list<string>, list<string>, string}> $routes
     *
     * @return list<string>
     */
    private function matchingRoutes(array $routes, string $method, string $path, ?string $scope): array
    {
        $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));
        $placeholder = static fn (string $segment): bool => preg_match('/^\{[^}]*\}$/', $segment) === 1;
        $matches = [];
        foreach ($routes as $route => [$methods, $routeSegments, $service]) {
            if (($scope !== null && $service !== $scope) || \count($routeSegments) !== \count($segments)
                || (!\in_array($method, $methods, true) && !\in_array('ANY', $methods, true))) {
                continue;
            }
            foreach ($segments as $index => $segment) {
                $other = $routeSegments[$index];
                if (strcasecmp($segment, $other) !== 0 && !($placeholder($segment) && $placeholder($other))) {
                    continue 2;
                }
            }
            $matches[] = $route;
        }

        return $matches;
    }

    /**
     * @return list<string>
     */
    private static function segments(string $path): array
    {
        return array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));
    }

    /**
     * An operation's path and a call's: `{id}` in the first and `*` in the second match any segment.
     *
     * @param list<string> $operation
     * @param list<string> $call
     */
    private static function pathMatches(array $operation, array $call): bool
    {
        if (\count($operation) !== \count($call)) {
            return false;
        }
        foreach ($operation as $index => $segment) {
            if (!HttpResolver::segmentMatches($segment, $call[$index])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $lines
     */
    private static function lineOf(array $lines, string $key): int
    {
        $pattern = '/^\s*["\']?' . preg_quote($key, '/') . '["\']?\s*:/';
        foreach ($lines as $index => $line) {
            if (preg_match($pattern, $line) === 1) {
                return $index + 1;
            }
        }

        return 1;
    }
}
