<?php

declare(strict_types=1);

namespace PhpGraph\Project\Rules;

/**
 * A pattern of class names, as `phpgraph.yaml` rules write them: a glob (`App\*\Controller\*`: `*` within one
 * segment, `**` across segments, `?` one character), or a namespace ending with `\`, everything in it at any depth
 * (`Doctrine\`, `App\*\Infrastructure\`). Case does not matter, as in PHP.
 */
final class Pattern
{
    /** @var array<string, string> pattern => regular expression */
    private static array $compiled = [];

    public static function matches(string $pattern, string $name): bool
    {
        $name = ltrim($name, '\\');
        $pattern = ltrim($pattern, '\\');
        // `App\*\Infrastructure\`: everything in the namespaces the glob matches.
        if (str_ends_with($pattern, '\\')) {
            $pattern .= '**';
        }

        return preg_match(self::$compiled[$pattern] ??= self::compile($pattern), $name) === 1;
    }

    /**
     * @param list<string> $patterns
     */
    public static function any(array $patterns, string $name): bool
    {
        foreach ($patterns as $pattern) {
            if (self::matches($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    private static function compile(string $pattern): string
    {
        $regex = strtr(preg_quote($pattern, '/'), ['\\*\\*' => '.*', '\\*' => '[^\\\\]*', '\\?' => '.']);

        return '/^' . $regex . '$/i';
    }
}
