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
     * The extensions of the source files in other languages, by language.
     */
    private const LANGUAGES = [
        'ts' => 'TypeScript', 'tsx' => 'TypeScript', 'js' => 'JavaScript', 'jsx' => 'JavaScript', 'mjs' => 'JavaScript',
        'cjs' => 'JavaScript', 'vue' => 'Vue', 'svelte' => 'Svelte', 'java' => 'Java', 'kt' => 'Kotlin', 'kts' => 'Kotlin',
        'go' => 'Go', 'py' => 'Python', 'rb' => 'Ruby', 'cs' => 'C#', 'rs' => 'Rust', 'swift' => 'Swift', 'scala' => 'Scala',
        'dart' => 'Dart', 'ex' => 'Elixir', 'exs' => 'Elixir', 'c' => 'C', 'cpp' => 'C++', 'cc' => 'C++',
    ];

    /**
     * PHP sources, the composer.json files locating vendor/ directories, the YAML routing files (Symfony): those whose
     * path mentions routes or routing, and the YAML and XML files of a config directory, where Symfony declares its
     * services.
     *
     * @param list<string> $excludePatterns
     */
    public static function finder(string $root, array $excludePatterns = []): Finder
    {
        $gitignore = new ProjectGitignore($root);
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
            // The project's own .gitignore rules, not those of a repository above it (see ProjectGitignore).
            ->filter(static fn (SplFileInfo $file): bool => !$gitignore->isIgnored($file->getPathname()))
            ->filter(static fn (SplFileInfo $file): bool => !self::isConfiguration($file->getFilename())
                || self::isRouting($file->getRelativePathname())
                || self::isInConfigDirectory($file->getRelativePathname()))
            ->sortByName();

        foreach ($excludePatterns as $pattern) {
            $finder->notPath($pattern);
        }

        return $finder;
    }

    /**
     * The source files of other languages the project holds, by language, most first: what the graph does not see.
     * Built and minified files and the project's ignored files are left out.
     *
     * @param list<string> $excludePatterns
     *
     * @return array<string, int> language => files
     */
    public static function otherLanguages(string $root, array $excludePatterns = []): array
    {
        $gitignore = new ProjectGitignore($root);
        $finder = Finder::create()
            ->files()
            ->in($root)
            ->name(array_map(static fn (string $extension): string => '*.' . $extension, array_keys(self::LANGUAGES)))
            ->notName(['*.min.js', '*.d.ts'])
            ->exclude([...self::DEFAULT_EXCLUDES, 'dist', 'build', 'target', 'coverage', 'bower_components'])
            ->filter(static fn (SplFileInfo $file): bool => !$gitignore->isIgnored($file->getPathname()));
        foreach ($excludePatterns as $pattern) {
            $finder->notPath($pattern);
        }

        $languages = [];
        foreach ($finder as $file) {
            $language = self::LANGUAGES[strtolower($file->getExtension())] ?? null;
            if ($language !== null) {
                $languages[$language] = ($languages[$language] ?? 0) + 1;
            }
        }
        arsort($languages);

        return $languages;
    }

    /**
     * PHP files of the directory, outside the default exclusions, whatever .gitignore and the exclude patterns say:
     * when a build reads none of them, the graph is empty because of those rules, not because there is no code.
     */
    public static function countPhpFiles(string $root): int
    {
        return Finder::create()->files()->in($root)->name('*.php')->exclude(self::DEFAULT_EXCLUDES)->ignoreDotFiles(false)->count();
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
