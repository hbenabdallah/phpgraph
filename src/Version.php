<?php

declare(strict_types=1);

namespace PhpGraph;

use Composer\InstalledVersions;

/**
 * The version of phpgraph: written into the PHAR and the Docker image when they are built from a release tag, read
 * from Composer when phpgraph is installed as a dependency, `dev` otherwise.
 */
final class Version
{
    public const PACKAGE = 'hbenabdallah/phpgraph';

    /**
     * Replaced by tools/build-phar.php. A property, not a constant: the check below must stay a runtime one.
     */
    private static string $built = '@phpgraph_version@';

    /**
     * The code that produces a graph: a graph.json built by other code is rebuilt, even when the sources did not
     * change, so an upgrade never serves a graph without what the new version finds. Works in the PHAR too.
     */
    public static function builder(): string
    {
        static $hash = null;
        if ($hash !== null) {
            return $hash;
        }

        $context = hash_init('xxh128');
        foreach (['Builder', 'Extractor', 'Graph', 'Project', 'Vendor'] as $directory) {
            $files = scandir(__DIR__ . '/' . $directory) ?: [];
            sort($files);
            foreach ($files as $file) {
                if (str_ends_with($file, '.php')) {
                    hash_update($context, $directory . '/' . $file . ':' . (string) @file_get_contents(__DIR__ . '/' . $directory . '/' . $file));
                }
            }
        }

        return $hash = hash_final($context);
    }

    public static function get(): string
    {
        if (!str_starts_with(self::$built, '@')) {
            return self::$built;
        }

        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled(self::PACKAGE)) {
            return InstalledVersions::getPrettyVersion(self::PACKAGE) ?? 'dev';
        }

        return 'dev';
    }
}
