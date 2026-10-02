<?php

declare(strict_types=1);

/*
 * Builds build/phpgraph.phar: phpgraph and its production dependencies in one executable file.
 *
 *   php -d phar.readonly=0 tools/build-phar.php     (composer phar)
 */

const ROOT = __DIR__ . '/..';
const BUILD = ROOT . '/build';
const STAGING = BUILD . '/phar-src';
const OUTPUT = BUILD . '/phpgraph.phar';

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function run(string $command): void
{
    passthru($command, $code);
    if ($code !== 0) {
        fail('Command failed: ' . $command);
    }
}

function copyTree(string $from, string $to): void
{
    @mkdir($to, 0775, true);
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($items as $item) {
        assert($item instanceof SplFileInfo);
        $target = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
        $item->isDir() ? @mkdir($target, 0775, true) : copy($item->getPathname(), $target);
    }
}

function removeTree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        assert($item instanceof SplFileInfo);
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($directory);
}

if (ini_get('phar.readonly') === '1') {
    fail('Run with: php -d phar.readonly=0 tools/build-phar.php');
}

// A clean copy with production dependencies only.
removeTree(STAGING);
copyTree(ROOT . '/src', STAGING . '/src');
foreach (['composer.json', 'composer.lock', 'LICENSE'] as $file) {
    if (is_file(ROOT . '/' . $file)) {
        copy(ROOT . '/' . $file, STAGING . '/' . $file);
    }
}
run(sprintf('composer install --working-dir=%s --no-dev --classmap-authoritative --no-scripts --no-plugins --prefer-dist --no-interaction --quiet', escapeshellarg(STAGING)));

// The version, from the release tag in CI (PHPGRAPH_VERSION=1.2.0), else from git.
$version = getenv('PHPGRAPH_VERSION') ?: trim((string) shell_exec('git -C ' . escapeshellarg(ROOT) . ' describe --tags --always 2>/dev/null')) ?: 'dev';
$versionFile = STAGING . '/src/Version.php';
file_put_contents($versionFile, str_replace("'@phpgraph_version@'", var_export(ltrim($version, 'v'), true), (string) file_get_contents($versionFile)));

// The entry point without its shebang line: required from the stub, a shebang would be printed.
@mkdir(STAGING . '/bin', 0775, true);
$entry = (string) file_get_contents(ROOT . '/bin/phpgraph');
file_put_contents(STAGING . '/bin/phpgraph.php', preg_replace('/^#!.*\n/', '', $entry));

@unlink(OUTPUT);
$phar = new Phar(OUTPUT, 0, 'phpgraph.phar');
$phar->startBuffering();
$phar->buildFromDirectory(STAGING);
$phar->setStub("#!/usr/bin/env php\n<?php\nPhar::mapPhar('phpgraph.phar');\nrequire 'phar://phpgraph.phar/bin/phpgraph.php';\n__HALT_COMPILER();\n");
$phar->stopBuffering();
chmod(OUTPUT, 0755);

removeTree(STAGING);
printf("%s %s (%.1f MB)\n", realpath(OUTPUT), ltrim($version, 'v'), filesize(OUTPUT) / 1048576);
