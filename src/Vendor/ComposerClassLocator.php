<?php

declare(strict_types=1);

namespace PhpGraph\Vendor;

use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;

/**
 * Finds the file declaring a dependency class, from the metadata Composer writes in vendor/composer.
 *
 * Nothing from the analysed project is executed: installed.json is read as JSON, and the classmap
 * (autoload_classmap.php) is parsed, not included.
 */
final class ComposerClassLocator
{
    /** @var array<string, list<string>> namespace prefix => directories */
    private array $psr4 = [];

    /** @var array<string, list<string>> namespace or class prefix => directories */
    private array $psr0 = [];

    /** @var array<string, string> lowercase class => file */
    private array $classmap = [];

    /**
     * @param list<string> $vendorDirectories absolute paths of vendor/ directories holding composer/installed.json
     */
    public function __construct(array $vendorDirectories)
    {
        foreach ($vendorDirectories as $vendor) {
            $this->readInstalled($vendor);
            $this->readClassmap($vendor);
        }

        uksort($this->psr4, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));
        uksort($this->psr0, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));
    }

    public function isEmpty(): bool
    {
        return $this->psr4 === [] && $this->psr0 === [] && $this->classmap === [];
    }

    public function locate(string $class): ?string
    {
        $class = ltrim($class, '\\');

        $mapped = $this->classmap[strtolower($class)] ?? null;
        if ($mapped !== null) {
            return $mapped;
        }

        foreach ($this->psr4 as $prefix => $directories) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';
            foreach ($directories as $directory) {
                if (is_file($directory . '/' . $relative)) {
                    return $directory . '/' . $relative;
                }
            }
        }

        $position = strrpos($class, '\\');
        $relative = $position === false
            ? str_replace('_', '/', $class) . '.php'
            : str_replace('\\', '/', substr($class, 0, $position + 1)) . str_replace('_', '/', substr($class, $position + 1)) . '.php';
        foreach ($this->psr0 as $prefix => $directories) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            foreach ($directories as $directory) {
                if (is_file($directory . '/' . $relative)) {
                    return $directory . '/' . $relative;
                }
            }
        }

        return null;
    }

    private function readInstalled(string $vendor): void
    {
        $json = @file_get_contents($vendor . '/composer/installed.json');
        $data = \is_string($json) ? json_decode($json, true) : null;
        if (!\is_array($data)) {
            return;
        }

        // Composer 2 wraps the list in "packages", Composer 1 writes the list itself.
        $packages = \is_array($data['packages'] ?? null) ? $data['packages'] : $data;

        foreach ($packages as $package) {
            if (!\is_array($package) || !\is_array($package['autoload'] ?? null)) {
                continue;
            }
            $installPath = \is_string($package['install-path'] ?? null)
                ? $vendor . '/composer/' . $package['install-path']
                : $vendor . '/' . (\is_string($package['name'] ?? null) ? $package['name'] : '');

            foreach (['psr-4', 'psr-0'] as $standard) {
                $rules = $package['autoload'][$standard] ?? null;
                if (!\is_array($rules)) {
                    continue;
                }
                foreach ($rules as $prefix => $paths) {
                    foreach ((array) $paths as $path) {
                        if (!\is_string($path)) {
                            continue;
                        }
                        $directory = rtrim($installPath . '/' . $path, '/');
                        if ($standard === 'psr-4') {
                            $this->psr4[(string) $prefix][] = $directory;
                        } else {
                            $this->psr0[(string) $prefix][] = $directory;
                        }
                    }
                }
            }
        }
    }

    private function readClassmap(string $vendor): void
    {
        $code = @file_get_contents($vendor . '/composer/autoload_classmap.php');
        if (!\is_string($code)) {
            return;
        }

        try {
            $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [];
        } catch (\PhpParser\Error) {
            return;
        }

        foreach ($statements as $statement) {
            if (!$statement instanceof Stmt\Return_ || !$statement->expr instanceof Expr\Array_) {
                continue;
            }
            foreach ($statement->expr->items as $item) {
                // 'Class' => $vendorDir . '/path.php'; project classes ($baseDir) are parsed anyway.
                if ($item->key instanceof String_
                    && $item->value instanceof Expr\BinaryOp\Concat
                    && $item->value->left instanceof Expr\Variable
                    && $item->value->left->name === 'vendorDir'
                    && $item->value->right instanceof String_
                ) {
                    $this->classmap[strtolower($item->key->value)] ??= $vendor . $item->value->right->value;
                }
            }
        }
    }
}
