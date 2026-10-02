<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

/**
 * The services of a repository and the service of each file.
 *
 * A service is a directory holding a composer.json that is not part of an enclosing project: no composer.json above
 * it autoloads its code, declares it as a path repository, replaces its package or one it requires (monorepo packages
 * are parts), and it is not test code.
 * The repository is split only when it holds at least two of them: one lone tool or bundled library below the root
 * stays with the root code. phpgraph.yaml can name the services instead.
 *
 * In a single-service repository, node ids are the plain names (App\Domain\Order). Otherwise each id carries its
 * service: `billing@App\Domain\Order`; the code outside every service belongs to the `root` service.
 */
final class ServiceMap
{
    public const ROOT = 'root';

    /**
     * @param list<string> $directories service directories relative to the root, longest first; empty when single
     */
    private function __construct(private readonly array $directories)
    {
    }

    public static function single(): self
    {
        return new self([]);
    }

    /**
     * @param list<string>      $composerFiles composer.json paths relative to the root
     * @param list<string>|null $forced        service directories named in phpgraph.yaml
     */
    public static function detect(string $root, array $composerFiles, ?array $forced = null): self
    {
        if ($forced !== null) {
            $directories = array_map(static fn (string $directory): string => trim(str_replace('\\', '/', $directory), '/'), $forced);

            return self::sorted(array_values(array_filter($directories, static fn (string $directory): bool => $directory !== '' && $directory !== '.')));
        }

        $composer = [];
        foreach ($composerFiles as $file) {
            if (!TestFiles::isTest($file)) {
                $data = json_decode((string) @file_get_contents($root . '/' . $file), true);
                $composer[\dirname($file)] = \is_array($data) ? $data : [];
            }
        }

        $services = [];
        foreach ($composer as $directory => $data) {
            if ($directory !== '.' && !self::isPartOfEnclosingProject((string) $directory, $composer)) {
                $services[] = (string) $directory;
            }
        }

        return \count($services) >= 2 ? self::sorted($services) : self::single();
    }

    public function isMultiService(): bool
    {
        return $this->directories !== [];
    }

    /**
     * The service of a file, '' in a single-service repository.
     */
    public function serviceOf(string $relativePath): string
    {
        foreach ($this->directories as $directory) {
            if (str_starts_with($relativePath, $directory . '/')) {
                return $directory;
            }
        }

        return $this->directories === [] ? '' : self::ROOT;
    }

    /**
     * @return list<string> service names (their directories) in alphabetical order, then the root service
     */
    public function names(): array
    {
        $names = $this->directories;
        sort($names);

        return $names === [] ? [] : [...$names, self::ROOT];
    }

    /**
     * @param array<string, array<mixed>> $composer directory => decoded composer.json
     */
    private static function isPartOfEnclosingProject(string $directory, array $composer): bool
    {
        $parent = $directory;
        do {
            $parent = \dirname($parent);
            if (isset($composer[$parent]) && self::covers($parent, $composer[$parent], $directory, $composer[$directory] ?? [])) {
                return true;
            }
        } while ($parent !== '.');

        return false;
    }

    /**
     * Whether a composer.json autoloads code in the directory, or below or above it, declares it as a path
     * repository, or replaces the package it holds or one it requires: a sibling package of the same monorepo.
     *
     * @param array<mixed> $composer
     * @param array<mixed> $child    composer.json of the directory
     */
    private static function covers(string $parent, array $composer, string $directory, array $child = []): bool
    {
        $replaced = \is_array($composer['replace'] ?? null) ? $composer['replace'] : [];
        $name = $child['name'] ?? null;
        if (\is_string($name) && isset($replaced[$name])) {
            return true;
        }
        $required = array_merge(
            \is_array($child['require'] ?? null) ? $child['require'] : [],
            \is_array($child['require-dev'] ?? null) ? $child['require-dev'] : [],
        );
        if (array_intersect_key($required, $replaced) !== []) {
            return true;
        }

        $paths = [];
        foreach (['autoload', 'autoload-dev'] as $section) {
            $autoload = \is_array($composer[$section] ?? null) ? $composer[$section] : [];
            foreach (['psr-4', 'psr-0', 'classmap', 'files'] as $standard) {
                foreach (\is_array($autoload[$standard] ?? null) ? $autoload[$standard] : [] as $entry) {
                    foreach ((array) $entry as $path) {
                        $paths[] = $path;
                    }
                }
            }
        }
        foreach (\is_array($composer['repositories'] ?? null) ? $composer['repositories'] : [] as $repository) {
            if (\is_array($repository) && ($repository['type'] ?? null) === 'path') {
                $paths[] = $repository['url'] ?? '';
            }
        }

        foreach ($paths as $path) {
            if (!\is_string($path)) {
                continue;
            }
            $full = self::normalize(($parent === '.' ? '' : $parent . '/') . $path);
            if ($full === '' || $full === $directory || str_starts_with($directory, $full . '/') || str_starts_with($full, $directory . '/')) {
                return true;
            }
            if (str_contains($full, '*') && preg_match('#^' . str_replace('\*', '[^/]+', preg_quote($full, '#')) . '$#', $directory) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(string $path): string
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

    /**
     * @param list<string> $directories
     */
    private static function sorted(array $directories): self
    {
        usort($directories, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a) ?: $a <=> $b);

        return new self($directories);
    }
}
