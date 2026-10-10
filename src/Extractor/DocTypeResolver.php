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
 * The plain attributes keep a single class: `Foo`, `?Foo`, `Foo|null`, `Collection<Foo>` (as Collection), `self`,
 * `static` or `$this` (as TypeExpr::STATIC). Unions, arrays, scalars and template parameters are dropped there.
 * The generic attributes keep the arguments apart, as GenericType strings: the class's templates, what it gives its
 * parents (`@extends`, `@template-extends`), and the `@return`, `@var` and `@param` types that say more than a class.
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

    /**
     * array<string, string>: the class of a parameter, by name (`@param CreateOrderQuery $query`), narrower than its
     * native type at times (`QueryInterface $query`).
     */
    public const PARAM_TYPES = 'phpgraph.paramTypes';

    /**
     * string: the class of the elements of the collection a method returns (`@return list<Violation>`).
     */
    public const RETURN_ELEMENT = 'phpgraph.returnElement';

    /**
     * list<string>: the template parameters of a class (`@template T`), in order.
     */
    public const TEMPLATES = 'phpgraph.templates';

    /**
     * array<string, list<string>>: the arguments a class gives its parents' templates, by parent
     * (`@extends NodeDefinition<TParent>`, `@implements Repository<Order>`), as GenericType strings.
     */
    public const PARENT_ARGUMENTS = 'phpgraph.parentArguments';

    /**
     * string: the GenericType a method returns, when it says more than its class: `ScalarNodeDefinition<static>`, `@T`.
     */
    public const RETURN_GENERIC = 'phpgraph.returnGeneric';

    /**
     * array<string, string>: the GenericType of a variable (`@var`, '' unnamed) or of a parameter (`@param`), by name,
     * when it says more than its class: `Collection<?,App\Item>`.
     */
    public const GENERICS = 'phpgraph.generics';

    /**
     * Iterables whose last argument is their elements; Generator has its own place for them.
     */
    private const ITERABLES = ['array', 'list', 'non-empty-list', 'non-empty-array', 'iterable'];

    private const BUILTIN = [
        'array', 'bool', 'boolean', 'callable', 'false', 'float', 'double', 'int', 'integer', 'iterable', 'mixed',
        'never', 'null', 'object', 'resource', 'string', 'true', 'void', 'list', 'scalar', 'numeric',
    ];

    /** @var list<list<string>> */
    private array $classTemplates = [];

    /** @var list<?string> */
    private array $classNames = [];

    public function __construct(private readonly NameContext $names)
    {
    }

    public function enterNode(AstNode $node)
    {
        $doc = $node->getDocComment()?->getText();

        if ($node instanceof Stmt\ClassLike) {
            $templates = $doc === null ? [] : $this->templates($doc);
            $this->classTemplates[] = $templates;
            $this->classNames[] = $node->namespacedName?->toString();
            if ($templates !== []) {
                $node->setAttribute(self::TEMPLATES, $templates);
            }
            $parents = [];
            preg_match_all('/@(?:phpstan-|psalm-)?(?:template-)?(?:extends|implements|use)\s+(\S+<.+)/', $doc ?? '', $matches);
            foreach ($matches[1] as $text) {
                [$base, $arguments] = GenericType::parse((string) $this->generic($this->firstType(trim($text)), []));
                if ($arguments !== [] && GenericType::isClass($base)) {
                    $parents[$base] = $arguments;
                }
            }
            if ($parents !== []) {
                $node->setAttribute(self::PARENT_ARGUMENTS, $parents);
            }

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
                $element = $this->elementType($this->firstType(trim($match[1])), $this->templates($doc));
                if ($element !== null) {
                    $node->setAttribute(self::RETURN_ELEMENT, $element);
                }
                $generic = $this->generic($this->firstType(trim($match[1])), $this->templates($doc));
                if ($generic !== null && GenericType::isGeneric($generic)) {
                    $node->setAttribute(self::RETURN_GENERIC, $generic);
                }
            }
            $elements = $types = $generics = [];
            foreach (preg_split('/\R/', $doc) ?: [] as $line) {
                if (preg_match('/@(?:phpstan-|psalm-)?param\s+(.+)/', $line, $match) === 1) {
                    $token = $this->firstType(trim($match[1]));
                    if (preg_match('/^\s*(?:\.\.\.)?\$(\w+)/', substr(trim($match[1]), \strlen($token)), $variable) !== 1) {
                        continue;
                    }
                    $element = $this->elementType($token, $this->templates($doc));
                    if ($element !== null) {
                        $elements[$variable[1]] = $element;
                    }
                    $type = $this->resolve($token, $this->templates($doc));
                    if ($type !== null && $type !== TypeExpr::STATIC) {
                        $types[$variable[1]] = $type;
                    }
                    $generic = $this->generic($token, $this->templates($doc));
                    if ($generic !== null && GenericType::isGeneric($generic)) {
                        $generics[$variable[1]] = $generic;
                    }
                }
            }
            if ($generics !== []) {
                $node->setAttribute(self::GENERICS, $generics);
            }
            if ($elements !== []) {
                $node->setAttribute(self::ELEMENT_TYPES, $elements);
            }
            if ($types !== []) {
                $node->setAttribute(self::PARAM_TYPES, $types);
            }

            return null;
        }

        $types = $generics = [];
        foreach (preg_split('/\R/', $doc) ?: [] as $line) {
            if (preg_match('/@(?:phpstan-|psalm-)?var\s+(.+)/', $line, $match) !== 1) {
                continue;
            }
            $text = trim($match[1]);
            if (preg_match('/^\$(\w+)\s+(.+)/', $text, $reversed) === 1) {
                $name = $reversed[1];
                $token = $this->firstType(trim($reversed[2]));
            } else {
                $token = $this->firstType($text);
                $rest = trim(substr($text, \strlen($token)));
                $name = preg_match('/^\$(\w+)/', $rest, $variable) === 1 ? $variable[1] : '';
            }
            $type = $this->resolve($token);
            if ($type !== null) {
                $types[$name] = $type;
            }
            $generic = $this->generic($token, []);
            if ($generic !== null && GenericType::isGeneric($generic)) {
                $generics[$name] = $generic;
            }
        }

        if ($types !== []) {
            $node->setAttribute(self::VAR_TYPES, $types);
        }
        if ($generics !== []) {
            $node->setAttribute(self::GENERICS, $generics);
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
        if (preg_match('/^\\\\?(array|list|non-empty-list|non-empty-array|iterable|Traversable|Iterator|IteratorAggregate|Generator)<(.+)>$/i', $type, $match) !== 1) {
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
            array_pop($this->classNames);
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
     * A type as a GenericType string: `Collection<int, Item>` gives `Collection<?,App\Item>`, `Item[]` gives
     * `array<App\Item>`, a template of the class `@T`; null when it holds no class at all (`int`, `array{a: int}`).
     *
     * @param list<string> $methodTemplates templates of the method: unknown, bound by its arguments
     */
    private function generic(string $text, array $methodTemplates): ?string
    {
        $text = trim($text);
        while (str_starts_with($text, '(') && str_ends_with($text, ')') && $this->closingParenthesis($text) === \strlen($text) - 1) {
            $text = trim(substr($text, 1, -1));
        }
        $text = ltrim($text, '?');

        $parts = array_values(array_filter(
            $this->split($text, '|'),
            static fn (string $part): bool => !\in_array(strtolower(trim($part)), ['null', 'false', 'void'], true),
        ));
        if (\count($parts) !== 1) {
            return null;
        }
        // An intersection, `NodeDefinition&ParentNodeDefinitionInterface`: its first class.
        $text = trim($this->split($parts[0], '&')[0]);
        if ($text !== trim($parts[0])) {
            return $this->generic($text, $methodTemplates);
        }

        if (str_ends_with($text, '[]')) {
            $element = $this->generic(substr($text, 0, -2), $methodTemplates);

            return GenericType::format(GenericType::ARRAY, [$element ?? GenericType::UNKNOWN]);
        }

        $name = $text;
        $arguments = [];
        $open = strpos($text, '<');
        if ($open !== false && str_ends_with($text, '>')) {
            $name = substr($text, 0, $open);
            foreach ($this->split(substr($text, $open + 1, -1), ',') as $argument) {
                $arguments[] = $this->generic($argument, $methodTemplates) ?? GenericType::UNKNOWN;
            }
        } elseif (preg_match('/[{(]/', $text) === 1) {
            $name = (string) preg_replace('/[{(].*$/s', '', $text);
            if (strtolower($name) !== 'closure') {
                return null;
            }
        }

        $lower = strtolower($name);
        if (\in_array($lower, self::ITERABLES, true)) {
            return GenericType::format(GenericType::ARRAY, $arguments === [] ? [] : [end($arguments)]);
        }
        if ($lower === 'static' || $lower === '$this') {
            return TypeExpr::STATIC;
        }
        if ($lower === 'self') {
            $self = end($this->classNames);

            return \is_string($self) ? GenericType::format($self, $arguments) : null;
        }
        if (\in_array($name, $methodTemplates, true)) {
            return null;
        }
        if (\in_array($name, (array) end($this->classTemplates), true)) {
            return '@' . $name;
        }
        if (\in_array($lower, self::BUILTIN, true) || preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $name) !== 1) {
            return null;
        }

        $global = array_search(strtolower(ltrim($name, '\\')), array_map('strtolower', GenericType::PHP_ITERABLES), true);
        $resolved = match (true) {
            $global !== false => GenericType::PHP_ITERABLES[$global],
            str_starts_with($name, '\\') => substr($name, 1),
            default => $this->names->getResolvedClassName(new Name($name))->toString(),
        };

        return GenericType::format($resolved, $arguments);
    }

    /**
     * Splits on a separator outside `<>`, `{}` and `()`.
     *
     * @return list<string>
     */
    private function split(string $text, string $separator): array
    {
        $parts = [];
        $depth = 0;
        $start = 0;
        for ($i = 0, $length = \strlen($text); $i < $length; ++$i) {
            $depth += match ($text[$i]) {
                '<', '{', '(' => 1,
                '>', '}', ')' => -1,
                default => 0,
            };
            if ($depth === 0 && $text[$i] === $separator) {
                $parts[] = substr($text, $start, $i - $start);
                $start = $i + 1;
            }
        }
        $parts[] = substr($text, $start);

        return array_map('trim', $parts);
    }

    private function closingParenthesis(string $text): int
    {
        $depth = 0;
        for ($i = 0, $length = \strlen($text); $i < $length; ++$i) {
            if ($text[$i] === '(') {
                ++$depth;
            } elseif ($text[$i] === ')' && --$depth === 0) {
                return $i;
            }
        }

        return -1;
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

        return array_values(array_unique($matches[1]));
    }
}
