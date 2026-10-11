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
 * The plain attributes keep a single class: `Foo`, `?Foo`, `Foo|null`, `Foo|false`, `Collection<Foo>` (as
 * Collection), `self`, `static` or `$this` (as TypeExpr::STATIC). Unions, arrays, scalars and template parameters
 * are dropped there.
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
     * string: a conditional `@return` as JSON, `($content is null ? ResponseFactory : Response)`: a tree of
     * `{"param": position, "test": "null"|"class", "then": tree, "else": tree}`, `{"type": class}` and
     * `{"arg": position}` (the class an argument names, `app(Mailer::class)`), evaluated against the arguments of a call.
     */
    public const RETURN_CONDITIONAL = 'phpgraph.returnConditional';

    /**
     * array<string, array{string, ?string, ?string}>: the methods a class documents with `@method` (magic methods,
     * facades), by lowercase name: name, class returned (or TypeExpr::STATIC), GenericType returned.
     */
    public const DOC_METHODS = 'phpgraph.docMethods';

    /**
     * array<string, array{?string, ?string}>: the properties a class documents with `@property` or `@property-read`
     * (magic properties, Eloquent attributes and relations), by name: class (or TypeExpr::STATIC), GenericType.
     */
    public const DOC_PROPERTIES = 'phpgraph.docProperties';

    /**
     * list<string>: the classes a class forwards its unknown calls to (`@mixin \Illuminate\Database\Query\Builder`).
     */
    public const MIXINS = 'phpgraph.mixins';

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
     * array<int|string, list<string>>: the parameters of the closure a method takes, by position and by name of its
     * parameter, as GenericType strings (`?` unknown): `@param (callable($this, TWhenParameter): mixed)|null $callback`
     * gives `['static', '?']`.
     */
    public const CALLABLE_PARAMETERS = 'phpgraph.callableParameters';

    /**
     * array<string, array{string, int}>: how a call binds the templates of the method, by template: `class` (`@param
     * class-string<T> $id`, the class the argument names), `value` (`@param T $value`, the type of the argument) or
     * `closure` (`@param callable(): T $callback`, what the closure returns), and the position of the argument. The
     * returned GenericType names such a template `@#T`.
     */
    public const TEMPLATE_BINDERS = 'phpgraph.templateBinders';

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

    /** @var list<Stmt\ClassLike> */
    private array $classNodes = [];

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
            $this->classNodes[] = $node;
            if ($templates !== []) {
                $node->setAttribute(self::TEMPLATES, $templates);
            }
            $parents = $this->parentArguments($doc ?? '');
            if ($parents !== []) {
                $node->setAttribute(self::PARENT_ARGUMENTS, $parents);
            }

            $methods = [];
            preg_match_all('/@(?:phpstan-|psalm-)?method\s+(?:static\s+)?(?:(\S+(?:<[^>]*>)?)\s+)?(\w+)\s*\(/', $doc ?? '', $matches, \PREG_SET_ORDER);
            foreach ($matches as $match) {
                $type = $match[1] === '' ? null : $this->resolve($match[1], $templates);
                $generic = $match[1] === '' ? null : $this->generic($match[1], []);
                $methods[strtolower($match[2])] = [$match[2], $type, $generic !== null && GenericType::isGeneric($generic) ? $generic : null];
            }
            if ($methods !== []) {
                $node->setAttribute(self::DOC_METHODS, $methods);
            }
            $properties = [];
            preg_match_all('/@(?:phpstan-|psalm-)?property(?:-read)?\s+(\S.*?)\s*\$(\w+)/', $doc ?? '', $matches, \PREG_SET_ORDER);
            foreach ($matches as $match) {
                $token = $this->firstType($match[1]);
                $type = $this->resolve($token, $templates) ?? $this->single($token, []);
                $generic = $this->generic($token, []);
                if ($type !== null || ($generic !== null && GenericType::isGeneric($generic))) {
                    $properties[$match[2]] = [$type, $generic !== null && GenericType::isGeneric($generic) ? $generic : null];
                }
            }
            if ($properties !== []) {
                $node->setAttribute(self::DOC_PROPERTIES, $properties);
            }
            preg_match_all('/@(?:phpstan-|psalm-)?mixin\s+(\S+)/', $doc ?? '', $matches);
            $mixins = array_values(array_filter(array_map(fn (string $mixin): ?string => $this->resolve($mixin, $templates), $matches[1])));
            if ($mixins !== []) {
                $node->setAttribute(self::MIXINS, $mixins);
            }

            return null;
        }

        if ($doc === null || !$node instanceof Stmt) {
            return null;
        }

        // `/** @use HasFactory<HotelFactory> */` on the trait use, in the class body.
        if ($node instanceof Stmt\TraitUse) {
            $class = end($this->classNodes);
            $parents = $this->parentArguments($doc);
            if ($class !== false && $parents !== []) {
                $declared = $class->getAttribute(self::PARENT_ARGUMENTS);
                $class->setAttribute(self::PARENT_ARGUMENTS, (\is_array($declared) ? $declared : []) + $parents);
            }

            return null;
        }

        if ($node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_) {
            $positions = [];
            foreach ($node->params as $position => $param) {
                if ($param->var instanceof AstNode\Expr\Variable && \is_string($param->var->name)) {
                    $positions[$param->var->name] = $position;
                }
            }
            $binders = $this->binders($doc, $positions);
            if ($binders !== []) {
                $node->setAttribute(self::TEMPLATE_BINDERS, $binders);
            }
            if (($match = $this->returnTag($doc, array_keys($binders))) !== null) {
                $type = $this->resolve($match[1], $this->templates($doc)) ?? $this->single($this->firstType(trim($match[1])), $this->templates($doc));
                // `@phpstan-return T|false` beside `@return WP_List_Table|false`: the class when T is not bound.
                if ($type === null && preg_match('/@return\s+(.+)/', $doc, $plain) === 1) {
                    $type = $this->resolve($plain[1], $this->templates($doc)) ?? $this->single($this->firstType(trim($plain[1])), $this->templates($doc));
                }
                if ($type !== null) {
                    $node->setAttribute(self::RETURN_TYPE, $type);
                }
                $element = $this->elementType($this->firstType(trim($match[1])), $this->templates($doc));
                if ($element !== null) {
                    $node->setAttribute(self::RETURN_ELEMENT, $element);
                }
                $generic = $this->generic($this->firstType(trim($match[1])), $this->templates($doc), array_keys($binders));
                if ($generic !== null && GenericType::isGeneric($generic)) {
                    $node->setAttribute(self::RETURN_GENERIC, $generic);
                }
                $conditional = $this->conditional($this->firstType(trim($match[1])), $positions);
                if ($conditional !== null && $conditional !== ['type' => null]) {
                    $node->setAttribute(self::RETURN_CONDITIONAL, json_encode($conditional));
                }
            }
            $elements = $types = $generics = $callables = [];
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
                    $type = $this->resolve($token, $this->templates($doc)) ?? $this->single($token, $this->templates($doc));
                    if ($type !== null && $type !== TypeExpr::STATIC) {
                        $types[$variable[1]] = $type;
                    }
                    $generic = $this->generic($token, $this->templates($doc));
                    if ($generic !== null && GenericType::isGeneric($generic)) {
                        $generics[$variable[1]] = $generic;
                    }
                    $callable = $this->callableParameters($token, $this->templates($doc));
                    if ($callable !== null) {
                        $callables[$variable[1]] = $callable;
                    }
                }
            }
            foreach ($node->params as $position => $param) {
                if ($param->var instanceof AstNode\Expr\Variable && \is_string($param->var->name) && isset($callables[$param->var->name])) {
                    $callables[$position] = $callables[$param->var->name];
                }
            }
            if ($callables !== []) {
                $node->setAttribute(self::CALLABLE_PARAMETERS, $callables);
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
            $type = $this->resolve($token) ?? $this->single($token, []);
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
            array_pop($this->classNodes);
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
            // `Foo|false`, as PHP's own functions return: a Foo when it is an object at all.
            static fn (string $part): bool => !\in_array(strtolower($part), ['null', 'false'], true),
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
     * @param list<string> $bound           those a call binds (TEMPLATE_BINDERS), kept as `@#T`
     */
    private function generic(string $text, array $methodTemplates, array $bound = []): ?string
    {
        $text = trim($text);
        while (str_starts_with($text, '(') && str_ends_with($text, ')') && $this->closingParenthesis($text) === \strlen($text) - 1) {
            $text = trim(substr($text, 1, -1));
        }
        $text = ltrim($text, '?');
        // A condition the arguments do not decide, `(TAsync is false ? Response : PromiseInterface)`: either branch.
        if (preg_match('/^\$?\w+\s+is\s+(?:not\s+)?\S+?\s*\?(.*)$/s', $text, $condition) === 1) {
            $branches = $this->split($condition[1], ':');
            if (\count($branches) < 2) {
                return null;
            }
            $then = array_shift($branches);

            return $this->union([$then, implode(':', $branches)], $methodTemplates);
        }

        $parts = array_values(array_filter(
            $this->split($text, '|'),
            static fn (string $part): bool => !\in_array(strtolower(trim($part)), ['null', 'false', 'void'], true),
        ));
        if (\count($parts) > 1) {
            return $this->union($parts, $methodTemplates);
        }
        if ($parts === []) {
            return null;
        }
        // An intersection, `NodeDefinition&ParentNodeDefinitionInterface`: its first class.
        $text = trim($this->split($parts[0], '&')[0]);
        if ($text !== trim($parts[0])) {
            return $this->generic($text, $methodTemplates, $bound);
        }

        if (str_ends_with($text, '[]')) {
            $element = $this->generic(substr($text, 0, -2), $methodTemplates, $bound);

            return GenericType::format(GenericType::ARRAY, [$element ?? GenericType::UNKNOWN]);
        }

        $name = $text;
        $arguments = [];
        $open = strpos($text, '<');
        if ($open !== false && str_ends_with($text, '>')) {
            $name = substr($text, 0, $open);
            foreach ($this->split(substr($text, $open + 1, -1), ',') as $argument) {
                $arguments[] = $this->generic($argument, $methodTemplates, $bound) ?? GenericType::UNKNOWN;
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
        if (\in_array($name, $bound, true)) {
            return '@#' . $name;
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
     * The `@return` tag to read: `@phpstan-return` or `@psalm-return` say more, written for the other tools, unless
     * they say nothing this reader understands.
     *
     * @param list<string> $bound
     *
     * @return array{string, string}|null
     */
    private function returnTag(string $doc, array $bound): ?array
    {
        $plain = preg_match('/@return\s+(.+)/', $doc, $match) === 1 ? $match : null;
        if (preg_match('/@(?:phpstan|psalm)-return\s+(.+)/', $doc, $match) !== 1) {
            return $plain;
        }
        $token = $this->firstType(trim($match[1]));
        $understood = $this->resolve($token, $this->templates($doc)) !== null || $this->generic($token, $this->templates($doc), $bound) !== null
            || str_contains($token, ' is ');

        return $understood || $plain === null ? $match : $plain;
    }

    /**
     * How a call binds the templates of the method (TEMPLATE_BINDERS), read from its `@param` tags.
     *
     * @param array<string, int> $positions parameter name => position
     *
     * @return array<string, array{string, int}>
     */
    private function binders(string $doc, array $positions): array
    {
        $templates = $this->templates($doc);
        if ($templates === []) {
            return [];
        }
        $binders = [];
        foreach (preg_split('/\R/', $doc) ?: [] as $line) {
            if (preg_match('/@(?:phpstan-|psalm-)?param\s+(.+)/', $line, $match) !== 1) {
                continue;
            }
            $token = $this->firstType(trim($match[1]));
            if (preg_match('/^\s*(?:\.\.\.)?\$(\w+)/', substr(trim($match[1]), \strlen($token)), $variable) !== 1 || !isset($positions[$variable[1]])) {
                continue;
            }
            $position = $positions[$variable[1]];
            $text = trim($token);
            while (str_starts_with($text, '(') && $this->closingParenthesis($text) === \strlen($text) - 1) {
                $text = trim(substr($text, 1, -1));
            }
            // `class-string<T>|null`, `?T`: the template bound, whatever else the argument may be.
            $members = array_values(array_filter(
                $this->split(ltrim($text, '?'), '|'),
                static fn (string $member): bool => strtolower(trim($member, '() ')) !== 'null',
            ));
            if (\count($members) !== 1) {
                continue;
            }
            $member = trim($members[0]);
            while (str_starts_with($member, '(') && $this->closingParenthesis($member) === \strlen($member) - 1) {
                $member = trim(substr($member, 1, -1));
            }
            if (preg_match('/^class-string<(\w+)>$/', $member, $found) === 1 && \in_array($found[1], $templates, true)) {
                $binders[$found[1]] = ['class', $position];
            } elseif (\in_array($member, $templates, true)) {
                $binders[$member] ??= ['value', $position];
            } elseif (preg_match('/^\\\\?(?:callable|Closure)\(/i', $member) === 1) {
                $close = $this->closingParenthesis($member);
                if ($close > 0 && preg_match('/^\s*:\s*(\w+)$/', substr($member, $close + 1), $found) === 1 && \in_array($found[1], $templates, true)) {
                    $binders[$found[1]] ??= ['closure', $position];
                }
            }
        }

        return $binders;
    }

    /**
     * The arguments a docblock gives the templates of parents: `@extends Repository<Order>`, `@use HasFactory<F>`.
     *
     * @return array<string, list<string>>
     */
    private function parentArguments(string $doc): array
    {
        $parents = [];
        preg_match_all('/@(?:phpstan-|psalm-)?(?:template-)?(?:extends|implements|use)\s+(\S+<.+)/', $doc, $matches);
        foreach ($matches[1] as $text) {
            [$base, $arguments] = GenericType::parse((string) $this->generic($this->firstType(trim($text)), []));
            if ($arguments !== [] && GenericType::isClass($base)) {
                $parents[$base] = $arguments;
            }
        }

        return $parents;
    }

    /**
     * The parameters of a closure type, `callable(Builder<TModel>, int): void` or `Closure($this): mixed`, alone or in
     * a union; null when it says nothing about them.
     *
     * @param list<string> $methodTemplates
     *
     * @return list<string>|null
     */
    private function callableParameters(string $text, array $methodTemplates): ?array
    {
        if (preg_match('/(?:^|[(|])\s*\\\\?(?:callable|Closure)\(/i', $text, $match, \PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $open = $match[0][1] + \strlen($match[0][0]) - 1;
        $close = $this->closingParenthesis(substr($text, $open));
        if ($close < 1) {
            return null;
        }
        $parameters = [];
        foreach ($this->split(substr($text, $open + 1, $close - 1), ',') as $parameter) {
            // `callable(Builder $query): void`: the type, without the name.
            $parameter = (string) preg_replace('/(?<=\S)\s+&?(?:\.\.\.)?\$\w+$/', '', trim($parameter));
            $parameters[] = $parameter === '' ? GenericType::UNKNOWN : $this->generic($this->firstType($parameter), $methodTemplates) ?? GenericType::UNKNOWN;
        }

        return array_filter($parameters, static fn (string $type): bool => $type !== GenericType::UNKNOWN) === [] ? null : $parameters;
    }

    /**
     * The one class a union leaves once its scalars and the templates of the method are set aside: `Foo|string`,
     * `$this|TWhenReturnType`.
     *
     * @param list<string> $methodTemplates
     */
    private function single(string $text, array $methodTemplates): ?string
    {
        $generic = $this->generic($text, $methodTemplates);

        return $generic !== null && !GenericType::isGeneric($generic) && ($generic === TypeExpr::STATIC || GenericType::isClass($generic)) ? $generic : null;
    }

    /**
     * A union as a GenericType: the members a method can be called on, `Response|ResponseFactory`. Scalars and arrays
     * are left out, and so is a template of the method, bound by the arguments (`$this|TWhenReturnType`: the closure
     * passed to when() returns the builder it receives, most of the time). `mixed` or `object` may be anything: null,
     * except beside the receiver alone (`static|mixed`, Eloquent's `scopes()`: the chain goes on on the receiver).
     *
     * @param list<string> $parts
     * @param list<string> $methodTemplates
     */
    private function union(array $parts, array $methodTemplates): ?string
    {
        $members = [];
        $anything = false;
        foreach ($parts as $part) {
            $lower = strtolower(trim($part, " ()\t"));
            if ($lower === 'mixed' || $lower === 'object') {
                $anything = true;
                continue;
            }
            if (\in_array(trim($part), $methodTemplates, true)) {
                continue;
            }
            $member = $this->generic($part, $methodTemplates);
            if ($member !== null && GenericType::base($member) !== GenericType::ARRAY) {
                array_push($members, ...GenericType::members($member));
            }
        }
        if ($anything) {
            return array_unique($members) === [TypeExpr::STATIC] ? TypeExpr::STATIC : null;
        }

        return GenericType::union($members);
    }

    /**
     * A conditional return type as a tree, null when the text is none: `($abstract is null ? Application : mixed)`.
     *
     * @param array<string, int> $parameters name => position
     *
     * @return array<string, mixed>|null
     */
    private function conditional(string $text, array $parameters, int $depth = 0): ?array
    {
        $text = trim($text);
        while (str_starts_with($text, '(') && str_ends_with($text, ')') && $this->closingParenthesis($text) === \strlen($text) - 1) {
            $text = trim(substr($text, 1, -1));
        }
        if ($depth > 5 || preg_match('/^\$(\w+)\s+is\s+(not\s+)?(\S+?)\s*\?(.*)$/s', $text, $match) !== 1 || !isset($parameters[$match[1]])) {
            return null;
        }
        // The two branches: split at the `:` outside brackets and nested conditions.
        $branches = $this->split($match[4], ':');
        if (\count($branches) < 2) {
            return null;
        }
        $then = array_shift($branches);
        $else = implode(':', $branches);
        $template = preg_match('/^class-string<(\w+)>$/', $match[3], $found) === 1 ? $found[1] : null;
        $test = strtolower($match[3]) === 'null' ? 'null' : ($template !== null ? 'class' : null);
        if ($test === null) {
            return null;
        }
        $leaf = function (string $branch) use ($parameters, $depth, $match, $template): array {
            if ($template !== null && trim($branch) === $template) {
                return ['arg' => $parameters[$match[1]]];
            }

            return $this->conditional($branch, $parameters, $depth + 1) ?? ['type' => $this->resolve($branch)];
        };
        [$then, $else] = $match[2] === '' ? [$leaf($then), $leaf($else)] : [$leaf($else), $leaf($then)];

        return ['param' => $parameters[$match[1]], 'test' => $test, 'then' => $then, 'else' => $else];
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
