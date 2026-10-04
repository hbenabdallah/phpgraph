<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

use PhpParser\NameContext;
use PhpParser\Node as AstNode;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

/**
 * Reads the class types written in docblocks (`@return`, `@var`) and stores them, resolved against the `use`
 * statements in force, as node attributes. Runs right after NameResolver, which owns the name context.
 *
 * Only a single class is kept: `Foo`, `?Foo`, `Foo|null`, `Collection<Foo>` (as Collection), `self`, `static`
 * or `$this` (as TypeExpr::STATIC). Unions, arrays, scalars and template parameters are dropped.
 */
final class DocTypeResolver extends NodeVisitorAbstract
{
    public const RETURN_TYPE = 'phpgraph.returnType';

    /**
     * array<string, string>: variable name (empty for an unnamed `@var`) to type.
     */
    public const VAR_TYPES = 'phpgraph.varTypes';

    /**
     * array<string, string>: the class of the elements of a collection, by parameter name on a method (`@param
     * Rule[] $rules`, `iterable<Rule>`, `array<int, Rule>`, `list<Rule>`), or under '' on a property (`@var Rule[]`).
     */
    public const ELEMENT_TYPES = 'phpgraph.elementTypes';

    private const BUILTIN = [
        'array', 'bool', 'boolean', 'callable', 'false', 'float', 'double', 'int', 'integer', 'iterable', 'mixed',
        'never', 'null', 'object', 'resource', 'string', 'true', 'void', 'list', 'scalar', 'numeric',
    ];

    /** @var list<list<string>> */
    private array $classTemplates = [];

    public function __construct(private readonly NameContext $names)
    {
    }

    public function enterNode(AstNode $node)
    {
        $doc = $node->getDocComment()?->getText();

        if ($node instanceof Stmt\ClassLike) {
            $this->classTemplates[] = $doc === null ? [] : $this->templates($doc);

            return null;
        }

        if ($doc === null || !$node instanceof Stmt) {
            return null;
        }

        if ($node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_) {
            if (preg_match('/@return\s+(.+)/', $doc, $match) === 1) {
                $type = $this->resolve($match[1], $this->templates($doc));
                if ($type !== null) {
                    $node->setAttribute(self::RETURN_TYPE, $type);
                }
            }
            $elements = [];
            foreach (preg_split('/\R/', $doc) ?: [] as $line) {
                if (preg_match('/@(?:phpstan-|psalm-)?param\s+(.+)/', $line, $match) === 1) {
                    $token = $this->firstType(trim($match[1]));
                    $element = $this->elementType($token, $this->templates($doc));
                    if ($element !== null && preg_match('/^\s*(?:\.\.\.)?\$(\w+)/', substr(trim($match[1]), \strlen($token)), $variable) === 1) {
                        $elements[$variable[1]] = $element;
                    }
                }
            }
            if ($elements !== []) {
                $node->setAttribute(self::ELEMENT_TYPES, $elements);
            }

            return null;
        }

        $types = [];
        foreach (preg_split('/\R/', $doc) ?: [] as $line) {
            if (preg_match('/@(?:phpstan-|psalm-)?var\s+(.+)/', $line, $match) !== 1) {
                continue;
            }
            $text = trim($match[1]);
            if (preg_match('/^\$(\w+)\s+(.+)/', $text, $reversed) === 1) {
                $name = $reversed[1];
                $type = $this->resolve($reversed[2]);
            } else {
                $token = $this->firstType($text);
                $rest = trim(substr($text, \strlen($token)));
                $name = preg_match('/^\$(\w+)/', $rest, $variable) === 1 ? $variable[1] : '';
                $type = $this->resolve($token);
            }
            if ($type !== null) {
                $types[$name] = $type;
            }
        }

        if ($types !== []) {
            $node->setAttribute(self::VAR_TYPES, $types);
        }
        if ($node instanceof Stmt\Property && preg_match('/@(?:phpstan-|psalm-)?var\s+(.+)/', $doc, $match) === 1) {
            $element = $this->elementType($this->firstType(trim($match[1])));
            if ($element !== null) {
                $node->setAttribute(self::ELEMENT_TYPES, ['' => $element]);
            }
        }

        return null;
    }

    /**
     * The class of the elements of a collection type: `Rule[]`, `array<Rule>`, `array<int, Rule>`, `list<Rule>`,
     * `iterable<Rule>`, `Traversable<int, Rule>`; null for anything else.
     *
     * @param list<string> $templates
     */
    private function elementType(string $type, array $templates = []): ?string
    {
        $type = ltrim($type, '?');
        if (str_ends_with($type, '[]') && !str_contains($type, '<')) {
            return $this->resolve(substr($type, 0, -2), $templates);
        }
        if (preg_match('/^\\?(array|list|non-empty-list|non-empty-array|iterable|Traversable|Iterator|IteratorAggregate|Generator)<(.+)>$/i', $type, $match) !== 1) {
            return null;
        }
        // The last argument outside nested brackets: `array<int, Rule>` gives Rule.
        $depth = 0;
        $start = 0;
        $arguments = $match[2];
        for ($i = 0, $length = \strlen($arguments); $i < $length; ++$i) {
            $depth += match ($arguments[$i]) {
                '<', '{', '(' => 1,
                '>', '}', ')' => -1,
                default => 0,
            };
            if ($depth === 0 && $arguments[$i] === ',') {
                $start = $i + 1;
            }
        }

        return $this->resolve(trim(substr($arguments, $start)), $templates);
    }

    public function leaveNode(AstNode $node)
    {
        if ($node instanceof Stmt\ClassLike) {
            array_pop($this->classTemplates);
        }

        return null;
    }

    /**
     * @param list<string> $templates
     */
    private function resolve(string $text, array $templates = []): ?string
    {
        $type = $this->firstType(trim($text));
        do {
            $type = preg_replace('/<[^<>]*>|\{[^{}]*\}|\([^()]*\)/', '', $type, -1, $count) ?? '';
        } while ($count > 0);

        $parts = array_values(array_filter(
            explode('|', ltrim($type, '?')),
            static fn (string $part): bool => strtolower($part) !== 'null',
        ));
        if (\count($parts) !== 1) {
            return null;
        }

        $name = $parts[0];
        $lower = strtolower($name);
        if ($lower === 'static' || $lower === '$this') {
            return TypeExpr::STATIC;
        }
        if ($lower === 'self') {
            return 'self';
        }
        if (\in_array($lower, self::BUILTIN, true) || preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $name) !== 1) {
            return null;
        }
        if (\in_array($name, $templates, true) || \in_array($name, array_merge(...$this->classTemplates), true)) {
            return null;
        }

        $resolved = str_starts_with($name, '\\')
            ? new Name\FullyQualified(substr($name, 1))
            : $this->names->getResolvedClassName(new Name($name));

        return $resolved->toString();
    }

    /**
     * The first type of a tag body: stops at the first space outside `<>`, `{}` and `()`.
     */
    private function firstType(string $text): string
    {
        $depth = 0;
        $length = \strlen($text);
        for ($i = 0; $i < $length; ++$i) {
            $char = $text[$i];
            if ($char === '<' || $char === '{' || $char === '(') {
                ++$depth;
            } elseif ($char === '>' || $char === '}' || $char === ')') {
                --$depth;
            } elseif ($depth <= 0 && ($char === ' ' || $char === "\t" || $char === '*')) {
                return substr($text, 0, $i);
            }
        }

        return $text;
    }

    /**
     * @return list<string>
     */
    private function templates(string $doc): array
    {
        preg_match_all('/@(?:phpstan-|psalm-)?template(?:-covariant|-contravariant)?\s+(\w+)/', $doc, $matches);

        return $matches[1];
    }
}
