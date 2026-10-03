<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

use PhpGraph\Extractor\RouteFact;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Routes declared in YAML routing files (Symfony): `path` with `controller` (or `defaults._controller`) and
 * `methods`, and the `resource` imports adding a `prefix` to the routes of another file, relative or `@SomeBundle/...`.
 */
final class YamlRoutes
{
    private const MAX_IMPORT_DEPTH = 6;

    /** @var array<string, array<string, list<string>>> routing file => loader type => prefixes */
    private array $loaderPrefixes = [];

    /**
     * @param list<string>          $files   routing files relative to the root
     * @param array<string, string> $bundles bundle name => its directory relative to the root (SyliusShopBundle => src/...)
     *
     * @return list<RouteFact>
     */
    public function read(string $root, array $files, array $bundles): array
    {
        $definitions = [];
        /** @var array<string, list<array{string, string}>> $importedBy file => [importing file, prefix] */
        $importedBy = [];

        foreach ($files as $file) {
            $content = (string) @file_get_contents($root . '/' . $file);
            try {
                $data = Yaml::parse($content, Yaml::PARSE_CUSTOM_TAGS);
            } catch (ParseException) {
                continue;
            }
            if (!\is_array($data)) {
                continue;
            }

            foreach ($data as $name => $definition) {
                if (!\is_array($definition)) {
                    continue;
                }
                $definition['line'] = $this->line($content, (string) $name);
                // `api_platform: { resource: ., type: api_platform, prefix: /api }`: a loader of routes declared elsewhere.
                if (\is_string($definition['type'] ?? null) && $definition['type'] !== 'attribute' && $definition['type'] !== 'annotation') {
                    $this->loaderPrefixes[$file][$definition['type']][] = \is_string($definition['prefix'] ?? null) ? $definition['prefix'] : '';
                    continue;
                }
                if (\is_string($definition['resource'] ?? null)) {
                    $target = $this->resolve($file, $definition['resource'], $bundles);
                    if ($target !== null) {
                        $importedBy[$target][] = [$file, \is_string($definition['prefix'] ?? null) ? $definition['prefix'] : ''];
                    }
                    continue;
                }
                $path = $definition['path'] ?? null;
                $path = \is_array($path) ? (string) reset($path) : $path;
                if (\is_string($path)) {
                    $definitions[$file][] = $definition + ['path' => $path];
                }
            }
        }

        $routes = [];
        foreach ($definitions as $file => $fileDefinitions) {
            foreach ($this->prefixes($file, $importedBy, 0) as $prefix) {
                foreach ($fileDefinitions as $definition) {
                    [$controller, $action, $serviceId] = $this->controller($definition['controller'] ?? $definition['defaults']['_controller'] ?? null);
                    $routes[] = new RouteFact(
                        $this->methods($definition['methods'] ?? null),
                        RouteFact::normalizePath($prefix . '/' . $definition['path']),
                        $controller,
                        $action,
                        $file,
                        $definition['line'],
                        $serviceId,
                    );
                }
            }
        }

        return $routes;
    }

    /**
     * The prefixes the route loaders imported by the files read get (`type: api_platform`), by file.
     *
     * @return array<string, array<string, list<string>>>
     */
    public function loaderPrefixes(): array
    {
        return $this->loaderPrefixes;
    }

    /**
     * The line of a top-level key: the route name.
     */
    private function line(string $content, string $name): ?int
    {
        if (preg_match('/^[\'"]?' . preg_quote($name, '/') . '[\'"]?\s*:/m', $content, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return substr_count($content, "\n", 0, $match[0][1]) + 1;
    }

    /**
     * The prefixes a file's routes get: those of every import of it, or none for a file loaded directly.
     *
     * @param array<string, list<array{string, string}>> $importedBy
     *
     * @return list<string>
     */
    private function prefixes(string $file, array $importedBy, int $depth): array
    {
        if (!isset($importedBy[$file]) || $depth > self::MAX_IMPORT_DEPTH) {
            return [''];
        }

        $prefixes = [];
        foreach ($importedBy[$file] as [$parent, $prefix]) {
            foreach ($this->prefixes($parent, $importedBy, $depth + 1) as $parentPrefix) {
                $prefixes[] = rtrim($parentPrefix, '/') . '/' . trim($prefix, '/');
            }
        }

        return array_values(array_unique($prefixes));
    }

    /**
     * @param array<string, string> $bundles
     */
    private function resolve(string $file, string $resource, array $bundles): ?string
    {
        if (!SourceFiles::isYaml($resource)) {
            return null;
        }

        if (preg_match('#^@(\w+)/(.+)$#', $resource, $match) === 1) {
            $directory = $bundles[$match[1]] ?? null;

            return $directory === null ? null : $this->normalize($directory . '/' . $match[2]);
        }

        return $this->normalize(\dirname($file) . '/' . $resource);
    }

    /**
     * `App\Controller\OrderController::show`, `App\Controller\ShowOrder` (invokable), or a container service id
     * (`pim_enrich.controller.rest.product:getAction`), resolved later through the service definitions.
     *
     * @return array{?string, ?string, ?string} class, method, service id
     */
    private function controller(mixed $controller): array
    {
        if (!\is_string($controller) || $controller === '') {
            return [null, null, null];
        }

        $parts = preg_split('/::?/', $controller, 2) ?: [$controller];
        $action = isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null;

        return str_contains($parts[0], '\\')
            ? [ltrim($parts[0], '\\'), $action, null]
            : [null, $action, $parts[0]];
    }

    /**
     * @return list<string>
     */
    private function methods(mixed $methods): array
    {
        $list = \is_string($methods) ? explode('|', $methods) : (\is_array($methods) ? $methods : []);

        return array_values(array_map(static fn (mixed $method): string => strtoupper((string) $method), array_filter($list, 'is_string')));
    }

    private function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }
}
