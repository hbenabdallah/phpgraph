<?php

declare(strict_types=1);

namespace PhpGraph\Builder\Libraries;

use PhpGraph\Graph\Graph;
use PhpGraph\Graph\Node;
use PhpGraph\Graph\NodeKind;

/**
 * PrestaShop's legacy classes: `classes/Context.php` declares `ContextCore`, and the autoloader of
 * `prestashop/autoload` declares `class Context extends ContextCore {}` at run time, unless `override/` declares it.
 * The code names `Context`: it means `ContextCore`.
 */
final class PrestaShopClasses
{
    private const PACKAGE = 'prestashop/autoload';

    /**
     * @param string       $root          the project root
     * @param list<string> $composerFiles the project's composer.json files, relative to the root
     */
    public static function isUsed(string $root, array $composerFiles): bool
    {
        foreach ($composerFiles as $file) {
            $composer = json_decode((string) @file_get_contents($root . '/' . $file), true);
            $required = \is_array($composer) && \is_array($composer['require'] ?? null) ? $composer['require'] : [];
            if (isset($required[self::PACKAGE])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The names the autoloader declares: `Context` => `ContextCore`, for each class of the global namespace named
     * `…Core`. Kept only where the project declares no class of that name (NameCanonicalizer::alias).
     *
     * @return array<string, string>
     */
    public static function aliases(Graph $graph): array
    {
        $aliases = [];
        foreach ($graph->nodes() as $node) {
            if (self::isLegacyClass($node)) {
                $aliases[substr($node->id, 0, -4)] = $node->id;
            }
        }

        return $aliases;
    }

    private static function isLegacyClass(Node $node): bool
    {
        $isGlobal = !str_contains($node->id, '\\') && !str_contains($node->id, '@');

        return $node->kind === NodeKind::PhpClass && $isGlobal && str_ends_with($node->id, 'Core') && \strlen($node->id) > 4;
    }
}
