<?php

declare(strict_types=1);

namespace PhpGraph\Project;

use PhpGraph\Builder\TestFiles;

/**
 * The notable packages of each Composer application in the project, read from composer.lock (exact versions) or,
 * without a lock, from composer.json (constraints). Packages composing the project itself (path repositories,
 * monorepo parts) are not listed: below the root, only a composer.json with a lock or an installed vendor/ counts.
 */
final class StackDetector
{
    /**
     * Package name or prefix (ending with "/" or "-") => role. The first match wins.
     */
    private const CATALOG = [
        'symfony/framework-bundle' => 'framework',
        'laravel/framework' => 'framework',
        'laravel/lumen-framework' => 'framework',
        'slim/slim' => 'framework',
        'laminas/laminas-mvc' => 'framework',
        'cakephp/cakephp' => 'framework',
        'yiisoft/yii2' => 'framework',
        'codeigniter4/framework' => 'framework',
        'ecotone/ecotone' => 'framework',
        'api-platform/core' => 'framework',
        'api-platform/symfony' => 'framework',
        'sylius/resource-bundle' => 'framework',
        'doctrine/orm' => 'persistence',
        'doctrine/dbal' => 'persistence',
        'doctrine/mongodb-odm' => 'persistence',
        'illuminate/database' => 'persistence',
        'propel/propel' => 'persistence',
        'ecotone/dbal' => 'persistence',
        'symfony/messenger' => 'messaging',
        'league/tactician' => 'messaging',
        'prooph/' => 'messaging',
        'broadway/broadway' => 'messaging',
        'enqueue/' => 'messaging',
        'php-amqplib/php-amqplib' => 'messaging',
        'ecotone/amqp' => 'messaging',
        'symfony/workflow' => 'messaging',
        'winzou/state-machine' => 'messaging',
        'phpunit/phpunit' => 'tests',
        'phpspec/phpspec' => 'tests',
        'behat/behat' => 'tests',
        'codeception/codeception' => 'tests',
        'pestphp/pest' => 'tests',
        'phpstan/phpstan' => 'analysis',
        'vimeo/psalm' => 'analysis',
        'qossmic/deptrac' => 'analysis',
        'deptrac/deptrac' => 'analysis',
        'qossmic/deptrac-shim' => 'analysis',
        'phparkitect/phparkitect' => 'analysis',
        'rector/rector' => 'analysis',
    ];

    /**
     * @param list<string> $composerFiles composer.json paths relative to the root
     *
     * @return list<array{directory: string, php: ?string, locked: bool, packages: array<string, array{version: string, role: string}>}>
     */
    public function detect(string $root, array $composerFiles): array
    {
        $applications = [];

        foreach ($composerFiles as $file) {
            // Fixtures of the test suite (modules, sample apps) are not the project's stack.
            if (TestFiles::isTest($file)) {
                continue;
            }

            $composer = $this->json($root . '/' . $file);
            if ($composer === null) {
                continue;
            }

            $directory = \dirname($file);
            $base = $root . '/' . ($directory === '.' ? '' : $directory . '/');
            $lock = $this->json($base . 'composer.lock');

            // Below the root, a composer.json without lock nor installed vendor/ is a part of the project (a monorepo
            // package), not an application of its own.
            if ($directory !== '.' && $lock === null && !is_file($base . 'vendor/composer/installed.json')) {
                continue;
            }

            $versions = $lock === null ? $this->constraints($composer) : $this->lockedVersions($lock);

            $packages = [];
            foreach ($versions as $name => $version) {
                $role = $this->role($name);
                if ($role !== null) {
                    $packages[$name] = ['version' => $version, 'role' => $role];
                }
            }

            $require = \is_array($composer['require'] ?? null) ? $composer['require'] : [];
            $applications[] = [
                'directory' => $directory,
                'php' => \is_string($require['php'] ?? null) ? $require['php'] : null,
                'locked' => $lock !== null,
                'packages' => $packages,
            ];
        }

        return $applications;
    }

    private function role(string $package): ?string
    {
        foreach (self::CATALOG as $name => $role) {
            $isPrefix = str_ends_with($name, '/') || str_ends_with($name, '-');
            if ($isPrefix ? str_starts_with($package, $name) : $package === $name) {
                return $role;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $lock
     *
     * @return array<string, string>
     */
    private function lockedVersions(array $lock): array
    {
        $versions = [];
        foreach (['packages', 'packages-dev'] as $section) {
            foreach (\is_array($lock[$section] ?? null) ? $lock[$section] : [] as $package) {
                if (\is_array($package) && \is_string($package['name'] ?? null) && \is_string($package['version'] ?? null)) {
                    $versions[$package['name']] = $package['version'];
                }
            }
        }

        return $versions;
    }

    /**
     * @param array<mixed> $composer
     *
     * @return array<string, string>
     */
    private function constraints(array $composer): array
    {
        $versions = [];
        foreach (['require', 'require-dev'] as $section) {
            foreach (\is_array($composer[$section] ?? null) ? $composer[$section] : [] as $name => $constraint) {
                if (\is_string($constraint)) {
                    $versions[(string) $name] = $constraint;
                }
            }
        }

        return $versions;
    }

    /**
     * @return array<mixed>|null
     */
    private function json(string $path): ?array
    {
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return \is_array($data) ? $data : null;
    }
}
