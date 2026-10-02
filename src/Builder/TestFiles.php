<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

/**
 * Tells test code from application code by its path only, so measurements can be read for the application alone.
 *
 * A file is test code when a directory on its path is named like a test directory, or when its name follows a
 * test framework convention (PHPUnit, PhpSpec, Codeception). `Testing` and `Fixtures` directories are not test
 * code: projects use them for application code and data.
 */
final class TestFiles
{
    private const DIRECTORIES = ['tests', 'test', 'spec', 'specs', '__tests__', 'behat'];

    private const SUFFIXES = ['Test.php', 'TestCase.php', 'Spec.php', 'Cest.php'];

    public static function isTest(string $relativePath): bool
    {
        $segments = explode('/', str_replace('\\', '/', $relativePath));
        $file = array_pop($segments);

        foreach ($segments as $directory) {
            if (\in_array(strtolower($directory), self::DIRECTORIES, true)) {
                return true;
            }
        }

        foreach (self::SUFFIXES as $suffix) {
            if (str_ends_with($file, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
