<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * The files a build reads, and a fingerprint telling whether any of them changed since.
 */
final class SourceFiles
{
    public const DEFAULT_EXCLUDES = ['vendor', 'node_modules', 'var', '.git', 'phpgraph-out'];

    public const CONFIG = 'phpgraph.yaml';

    /**
     * PHP sources, the composer.json files locating vendor/ directories, the YAML routing files (Symfony): those whose
     * path mentions routes or routing, and the YAML and XML files of a config directory, where Symfony declares its
     * services.
     *
     * @param list<string> $excludePatterns
     */
    public static function finder(string $root, array $excludePatterns = []): Finder
    {
        $finder = Finder::create()
            ->files()
            ->in($root)
            ->name('*.php')
            ->name('composer.json')
            ->name('*.yaml')
            ->name('*.yml')
            ->name('*.xml')
            ->notName('*.blade.php')
            ->exclude(self::DEFAULT_EXCLUDES)
            ->ignoreVCSIgnored(true)
            ->filter(static fn (SplFileInfo $file): bool => !self::isConfiguration($file->getFilename())
                || self::isRouting($file->getRelativePathname())
                || self::isInConfigDirectory($file->getRelativePathname()))
            ->sortByName();

        foreach ($excludePatterns as $pattern) {
            $finder->notPath($pattern);
        }

        return $finder;
    }

    public static function isYaml(string $name): bool
    {
        return str_ends_with($name, '.yaml') || str_ends_with($name, '.yml');
    }

    /**
     * A YAML or XML file: routing or container configuration, read by the builder.
     */
    public static function isConfiguration(string $name): bool
    {
        return self::isYaml($name) || str_ends_with($name, '.xml');
    }

    public static function isRouting(string $relativePath): bool
    {
        return stripos($relativePath, 'rout') !== false;
    }

    private static function isInConfigDirectory(string $relativePath): bool
    {
        return preg_match('#(^|/)config/#i', str_replace('\\', '/', $relativePath)) === 1;
    }

    /**
     * Path, modification time and size of every source file, of the composer.lock files and of the installed
     * dependencies: changes when a file is added, removed, edited, or when dependencies are updated.
     *
     * @param list<string> $excludePatterns
     */
    public static function fingerprint(string $root, array $excludePatterns = []): string
    {
        $hash = hash_init('xxh128');

        foreach (self::finder($root, $excludePatterns)->name('composer.lock')->name(self::CONFIG) as $file) {
            hash_update($hash, $file->getRelativePathname() . ':' . $file->getMTime() . ':' . $file->getSize() . "\n");

            if ($file->getFilename() === 'composer.json') {
                $installed = $file->getPath() . '/vendor/composer/installed.json';
                hash_update($hash, $installed . ':' . (@filemtime($installed) ?: 0) . "\n");
            }
        }

        return hash_final($hash);
    }
}
