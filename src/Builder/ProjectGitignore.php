<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

use Symfony\Component\Finder\Gitignore;

/**
 * The .gitignore rules of the analysed project: those of its root and of the directories below, never those of a
 * repository above it. Symfony Finder's ignoreVCSIgnored() climbs to the enclosing repository instead, and a project
 * copied into a directory that repository ignores (`<repo>/var/bench/project` when the repository ignores /var/)
 * would then be read as empty.
 *
 * The matching follows Finder's: the last matching rule of the deepest .gitignore decides, and nothing inside an
 * ignored directory can be re-included.
 */
final class ProjectGitignore
{
    private readonly string $root;

    /** @var array<string, list<array{string, bool, bool}>|null> .gitignore path => [regex, negated, directory only] */
    private array $rules = [];

    /** @var array<string, bool> */
    private array $ignored = [];

    /**
     * @param string $root as given to the Finder: the paths it returns start with it, relative or not
     */
    public function __construct(string $root)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
    }

    public function isIgnored(string $path): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        if (isset($this->ignored[$path])) {
            return $this->ignored[$path];
        }
        if ($path === $this->root || !str_starts_with($path, $this->root . '/')) {
            return $this->ignored[$path] = false;
        }

        $parent = \dirname($path);
        // Inside an ignored directory: ignored, and no rule can bring it back.
        if ($parent !== $this->root && $this->isIgnored($parent)) {
            return $this->ignored[$path] = true;
        }

        $isDirectory = is_dir($path);
        for ($directory = $parent; ; $directory = \dirname($directory)) {
            $relative = substr($path, \strlen($directory) + 1);
            foreach ($this->rules($directory . '/.gitignore') as [$regex, $negated, $directoryOnly]) {
                if ((!$directoryOnly || $isDirectory) && preg_match($regex, $relative, $match) === 1 && $match[0] === $relative) {
                    return $this->ignored[$path] = !$negated;
                }
            }
            if ($directory === $this->root) {
                break;
            }
        }

        return $this->ignored[$path] = false;
    }

    /**
     * @return list<array{string, bool, bool}> the rules of one .gitignore file, the last one first
     */
    private function rules(string $file): array
    {
        if (\array_key_exists($file, $this->rules)) {
            return $this->rules[$file] ?? [];
        }

        $content = is_file($file) ? @file_get_contents($file) : false;
        if (!\is_string($content)) {
            $this->rules[$file] = null;

            return [];
        }

        $rules = [];
        foreach (preg_split('~\r\n?|\n~', $content) ?: [] as $line) {
            // Only a line starting with "#" is a comment, and only trailing spaces are stripped.
            if (str_starts_with($line, '#')) {
                continue;
            }
            $line = preg_replace('~(?<!\\\\) +$~', '', $line) ?? $line;
            $negated = str_starts_with($line, '!');
            if ($negated) {
                $line = substr($line, 1);
            }
            $directoryOnly = str_ends_with($line, '/');
            if ($directoryOnly) {
                $line = substr($line, 0, -1);
            }
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '#')) {
                $line = '\\' . $line;
            }
            $rules[] = [Gitignore::toRegex($line), $negated, $directoryOnly];
        }

        return $this->rules[$file] = array_reverse($rules);
    }
}
