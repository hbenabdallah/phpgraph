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
