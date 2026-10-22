<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

use PhpParser\NameContext;
use PhpParser\Node as AstNode;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use PHPStan\PhpDocParser\Ast\PhpDoc\AssertTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ExtendsTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\GenericTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ImplementsTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\InvalidTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\MethodTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\MixinTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PropertyTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\UsesTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeParameterNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeForParameterNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ThisTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\ParserException;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;

/**
 * Reads the class types written in docblocks (`@return`, `@var`) and stores them, resolved against the `use`
 * statements in force, as node attributes. Runs right after NameResolver, which owns the name context. Docblocks
 * are read by phpstan/phpdoc-parser: types written over several lines, nested generics, shapes and conditions are
 * its trees, which this class interprets.
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
     * list<array{string, int, string}>: what a call tells of an argument, `@phpstan-assert`, `@psalm-assert` and their
     * `-if-true` and `-if-false` forms: when it holds (`always`, once the call returns; `true` or `false`, what it
     * returns), the position of the parameter, the GenericType it is then (a template a call binds is `@#T`, as in
     * `@psalm-assert ExpectedType $value` with `@psalm-param class-string<ExpectedType> $class`). Negations
     * (`!null`) and types no class is in (`array`, `'prefetch'`) are left out.
     */
    public const ASSERTIONS = 'phpgraph.assertions';

    /**
     * Iterables whose last argument is their elements; Generator has its own place for them.
     */
    private const ITERABLES = ['array', 'list', 'non-empty-list', 'non-empty-array', 'iterable'];

    /**
     * The collections whose last argument `@param` and `@return` element types are read from.
     */
    private const COLLECTIONS = ['array', 'list', 'non-empty-list', 'non-empty-array', 'iterable', 'traversable', 'iterator', 'iteratoraggregate', 'generator'];

    private const BUILTIN = [
        'array', 'bool', 'boolean', 'callable', 'false', 'float', 'double', 'int', 'integer', 'iterable', 'mixed',
        'never', 'null', 'object', 'resource', 'string', 'true', 'void', 'list', 'scalar', 'numeric', 'empty',
    ];

    /**
     * The size of a docblock past which its tags are parsed one by one.
     */
    private const LARGE_DOC = 16384;

    /**
     * Tags read with a type the parser does not know by these names: their value is parsed as a type.
     */
    private const UNPARSED_TAGS = ['@phpstan-mixin', '@psalm-mixin', '@psalm-extends', '@psalm-implements', '@psalm-use'];

    /** @var list<list<string>> */
    private array $classTemplates = [];

    /** @var list<?string> */
    private array $classNames = [];

    /** @var list<Stmt\ClassLike> */
    private array $classNodes = [];

    private readonly Lexer $lexer;

    private readonly PhpDocParser $parser;

    private readonly TypeParser $typeParser;

    public function __construct(private readonly NameContext $names)
    {
        $config = new ParserConfig([]);
        $this->lexer = new Lexer($config);
        $expressions = new ConstExprParser($config);
        $this->typeParser = new TypeParser($config, $expressions);
        $this->parser = new PhpDocParser($config, $this->typeParser, $expressions);
    }

    public function enterNode(AstNode $node)
    {
        $text = $node->getDocComment()?->getText() ?? $this->inlineVar($node);
        $doc = $text === null ? null : $this->parse($text);

        if ($node instanceof Stmt\ClassLike) {
            $templates = $doc === null ? [] : $this->templates($doc);
            $this->classTemplates[] = $templates;
            $this->classNames[] = $node->namespacedName?->toString();
            $this->classNodes[] = $node;
            if ($doc === null) {
                return null;
            }
            if ($templates !== []) {
                $node->setAttribute(self::TEMPLATES, $templates);
            }
            $parents = $this->parentArguments($doc);
            if ($parents !== []) {
                $node->setAttribute(self::PARENT_ARGUMENTS, $parents);
            }

            $methods = $properties = $mixins = [];
            foreach ($doc->getTags() as $tag) {
                $value = $tag->value;
                if ($value instanceof MethodTagValueNode) {
                    $returned = $value->returnType;
                    $generic = $returned === null ? null : $this->generic($returned, []);
                    $methods[strtolower($value->methodName)] = [
                        $value->methodName,
                        $returned === null ? null : $this->resolve($returned, $templates),
                        $generic !== null && GenericType::isGeneric($generic) ? $generic : null,
                    ];
                } elseif ($value instanceof PropertyTagValueNode && !str_ends_with($tag->name, '-write')) {
                    $type = $this->resolve($value->type, $templates) ?? $this->single($value->type, []);
                    $generic = $this->generic($value->type, []);
                    if ($type !== null || ($generic !== null && GenericType::isGeneric($generic))) {
                        $properties[substr($value->propertyName, 1)] = [$type, $generic !== null && GenericType::isGeneric($generic) ? $generic : null];
                    }
                } elseif (\in_array($tag->name, ['@mixin', '@phpstan-mixin', '@psalm-mixin'], true)) {
                    $mixin = $this->tagType($tag);
                    $mixin = $mixin === null ? null : $this->resolve($mixin, $templates);
                    if ($mixin !== null) {
                        $mixins[] = $mixin;
                    }
                }
            }
            if ($methods !== []) {
                $node->setAttribute(self::DOC_METHODS, $methods);
            }
            if ($properties !== []) {
                $node->setAttribute(self::DOC_PROPERTIES, $properties);
            }
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
            $this->onCallable($node, $doc);

            return null;
        }

        $types = $generics = [];
        $first = null;
        foreach ($this->vars($doc) as [$name, $type]) {
            $first ??= $type;
            $class = $this->resolve($type) ?? $this->single($type, []);
            if ($class !== null) {
                $types[$name] = $class;
            }
            $generic = $this->generic($type, []);
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
        if ($node instanceof Stmt\Property && $first !== null) {
            $element = $this->elementType($first);
            if ($element !== null) {
                $node->setAttribute(self::ELEMENT_TYPES, ['' => $element]);
            }
        }

        return null;
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

    private function onCallable(Stmt\ClassMethod|Stmt\Function_ $node, PhpDocNode $doc): void
    {
        $positions = [];
        foreach ($node->params as $position => $param) {
            if ($param->var instanceof AstNode\Expr\Variable && \is_string($param->var->name)) {
                $positions[$param->var->name] = $position;
            }
        }
        $templates = $this->templates($doc);
        $binders = $this->binders($doc, $positions, $templates);
        if ($binders !== []) {
            $node->setAttribute(self::TEMPLATE_BINDERS, $binders);
        }
        [$returned, $plain] = $this->returnTag($doc, $templates, array_keys($binders));
        if ($returned !== null) {
            $type = $this->resolve($returned, $templates) ?? $this->single($returned, $templates);
            // `@phpstan-return T|false` beside `@return WP_List_Table|false`: the class when T is not bound.
            if ($type === null && $plain !== null) {
                $type = $this->resolve($plain, $templates) ?? $this->single($plain, $templates);
            }
            if ($type !== null) {
                $node->setAttribute(self::RETURN_TYPE, $type);
            }
            $element = $this->elementType($returned, $templates);
            if ($element !== null) {
                $node->setAttribute(self::RETURN_ELEMENT, $element);
            }
            $generic = $this->generic($returned, $templates, array_keys($binders));
            if ($generic !== null && GenericType::isGeneric($generic)) {
                $node->setAttribute(self::RETURN_GENERIC, $generic);
            }
            $conditional = $this->conditional($returned, $positions);
            // A condition whose branches name no class says nothing: the plain `@return` is read instead.
            $tree = $conditional === null ? '' : (string) json_encode($conditional);
            if (str_contains($tree, '"type":"') || str_contains($tree, '"arg":')) {
                $node->setAttribute(self::RETURN_CONDITIONAL, $tree);
            }
        }

        $elements = $types = $generics = $callables = [];
        foreach ($this->params($doc) as [$name, $type]) {
            $element = $this->elementType($type, $templates);
            if ($element !== null) {
                $elements[$name] = $element;
            }
            $class = $this->resolve($type, $templates) ?? $this->single($type, $templates);
            if ($class !== null && $class !== TypeExpr::STATIC) {
                $types[$name] = $class;
            }
            $generic = $this->generic($type, $templates);
            if ($generic !== null && GenericType::isGeneric($generic)) {
                $generics[$name] = $generic;
            }
            $callable = $this->callableParameters($type, $templates);
            if ($callable !== null) {
                $callables[$name] = $callable;
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
        $assertions = $this->assertions($doc, $positions, $templates, array_keys($binders));
        if ($assertions !== []) {
            $node->setAttribute(self::ASSERTIONS, $assertions);
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
    }

    /**
     * The docblock as phpstan/phpdoc-parser reads it; one it cannot read has no tags.
     */
    private function parse(string $text): PhpDocNode
    {
        if (!str_contains($text, '@')) {
            return new PhpDocNode([]);
        }
        // A method's return type after its parameters, as older code writes it: `@method static STORE(): Method`.
        if (str_contains($text, 'method')) {
            $text = (string) preg_replace('/(@(?:phpstan-|psalm-)?method\s+(?:static\s+)?)(\w+)\s*(\([^)]*\))\s*:\s*([^\s*]+)/', '$1$4 $2$3', $text);
        }
        // A tag starts a line: `/** @template T @return T */` holds two.
        if (!str_contains($text, "\n")) {
            $text = (string) preg_replace('/\s(?=@[a-z])/i', "\n * ", $text);
        }
        // The parser takes quadratic time on a docblock of thousands of tags (an SDK client's `@method` list, 700 KB):
        // each tag is then read alone, cut where a line starts one. A tag ends where the next starts anyway.
        if (\strlen($text) > self::LARGE_DOC) {
            $children = [];
            foreach (preg_split('/\R(?=\s*\*\s*@)/', $text) ?: [] as $chunk) {
                array_push($children, ...$this->parseTags('/** ' . ltrim(ltrim($chunk), '/*') . "\n */")->children);
            }

            return new PhpDocNode($children);
        }

        return $this->parseTags($text);
    }

    /**
     * A `@var` in a plain comment before a statement, as older code writes it (`/* @var $line JacBean *\/`): read as
     * the docblock the statement lacks. Only when it names its variable: an unnamed one in a plain comment is a note
     * more often than a type (PrestaShop's `/* @var PriceSpecification *\/`, a class it does not import). Not before
     * a class or a callable, whose docblocks say more than a variable.
     */
    private function inlineVar(AstNode $node): ?string
    {
        if (!$node instanceof Stmt || $node instanceof Stmt\ClassLike || $node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_) {
            return null;
        }
        foreach (array_reverse($node->getComments()) as $comment) {
            $text = $comment->getText();
            if (str_starts_with($text, '/*') && !str_starts_with($text, '/**') && preg_match('/@var\s+(?:\$\w+|\S+\s+\$\w+)/', $text) === 1) {
                return '/**' . substr($text, 2);
            }
        }

        return null;
    }

    private function parseTags(string $text): PhpDocNode
    {
        try {
            $doc = $this->parser->parse(new TokenIterator($this->lexer->tokenize($text)));
        } catch (ParserException) {
            return new PhpDocNode([]);
        }
        foreach ($doc->children as $index => $child) {
            if ($child instanceof PhpDocTagNode && $child->value instanceof InvalidTagValueNode) {
                $doc->children[$index] = $this->repaired($child, $child->value->value);
            }
        }

        return $doc;
    }

    /**
     * A tag written as older code writes it, which PHPDoc does not allow, read as it means: a callable without a
     * return type (`callable(\Throwable $e)|null`), a nullable member of a union (`?Response|RedirectResponse`), a
     * class followed by parentheses (`EmailDraft()`). The tag as it was otherwise.
     */
    private function repaired(PhpDocTagNode $tag, string $value): PhpDocTagNode
    {
        $text = $value;
        if (str_starts_with($text, '?')) {
            $text = substr($text, 1);
        }
        // A class written as a call: `@return EmailDraft() $lEmailDraft`, `@param Client () $pClient`.
        $text = (string) preg_replace('/^(?!(?:callable|Closure)\b)(\\\\?[A-Za-z_][\w\\\\]*(?:\[\])*)\s*\(\)/i', '$1', $text);
        $offset = 0;
        while (preg_match('/\b(?:callable|Closure)\(/i', $text, $match, \PREG_OFFSET_CAPTURE, $offset) === 1) {
            $open = $match[0][1] + \strlen($match[0][0]) - 1;
            $depth = 0;
            for ($close = $open, $length = \strlen($text); $close < $length; ++$close) {
                $depth += $text[$close] === '(' ? 1 : ($text[$close] === ')' ? -1 : 0);
                if ($depth === 0) {
                    break;
                }
            }
            if ($close >= \strlen($text)) {
                break;
            }
            if (!str_starts_with(ltrim(substr($text, $close + 1)), ':')) {
                $text = substr($text, 0, $close + 1) . ': mixed' . substr($text, $close + 1);
            }
            $offset = $close + 1;
        }
        if ($text === $value) {
            return $tag;
        }
        try {
            $repaired = $this->parser->parse(new TokenIterator($this->lexer->tokenize("/**\n * " . $tag->name . ' ' . $text . "\n */")))->getTags()[0] ?? null;
        } catch (ParserException) {
            return $tag;
        }

        return $repaired === null || $repaired->value instanceof InvalidTagValueNode ? $tag : $repaired;
    }

    /**
     * The type a tag gives, for a tag the parser reads (`@mixin`) or one whose value it leaves as text (`@psalm-mixin`).
     */
    private function tagType(PhpDocTagNode $tag): ?TypeNode
    {
        $value = $tag->value;
        if ($value instanceof MixinTagValueNode || $value instanceof ExtendsTagValueNode || $value instanceof ImplementsTagValueNode || $value instanceof UsesTagValueNode) {
            return $value->type;
        }
        if ($value instanceof GenericTagValueNode && \in_array($tag->name, self::UNPARSED_TAGS, true)) {
            return $this->typeIn($value->value);
        }

        return null;
    }

    /**
     * The type a text starts with: the value of a tag the parser does not know, the rest of `@var $name Type`.
     */
    private function typeIn(string $text): ?TypeNode
    {
        try {
            return $this->typeParser->parse(new TokenIterator($this->lexer->tokenize($text)));
        } catch (ParserException) {
            return null;
        }
    }

    /**
     * The `@var` tags, in order: variable name (empty when it names none) and type. `@var $name Type`, the order
     * older code writes, is read too.
     *
     * @return list<array{string, TypeNode}>
     */
    private function vars(PhpDocNode $doc): array
    {
        $vars = [];
        foreach ($doc->getTags() as $tag) {
            if (!\in_array($tag->name, ['@var', '@phpstan-var', '@psalm-var'], true)) {
                continue;
            }
            $value = $tag->value;
            if ($value instanceof VarTagValueNode) {
                $vars[] = [ltrim($value->variableName, '$'), $value->type];
            } elseif ($value instanceof InvalidTagValueNode && str_starts_with($value->value, '$')) {
                $space = strcspn($value->value, " \t");
                $type = $this->typeIn(substr($value->value, $space));
                if ($type !== null) {
                    $vars[] = [substr($value->value, 1, $space - 1), $type];
                }
            }
        }

        return $vars;
    }

    /**
     * The `@param` tags naming a parameter, in order: name and type.
     *
     * @return list<array{string, TypeNode}>
     */
    private function params(PhpDocNode $doc): array
    {
        $params = [];
        foreach ($doc->getTags() as $tag) {
            if ($tag->value instanceof ParamTagValueNode && \in_array($tag->name, ['@param', '@phpstan-param', '@psalm-param'], true)) {
                $params[] = [substr($tag->value->parameterName, 1), $tag->value->type];
            }
        }

        return $params;
    }

    /**
     * The class of the elements of a collection type: `Rule[]`, `array<Rule>`, `array<int, Rule>`, `list<Rule>`,
     * `iterable<Rule>`, `Traversable<int, Rule>`; null for anything else.
     *
     * @param list<string> $templates
     */
    private function elementType(TypeNode $type, array $templates = []): ?string
    {
        if ($type instanceof NullableTypeNode) {
            $type = $type->type;
        }
        // `Node\Stmt[]|null`, `array<int, Rule>|array<string, Rule>`: the elements its collections agree on.
        if ($type instanceof UnionTypeNode) {
            $elements = array_values(array_unique(array_filter(array_map(fn (TypeNode $member): ?string => $this->elementType($member, $templates), $type->types))));

            return \count($elements) === 1 ? $elements[0] : null;
        }
        if ($type instanceof ArrayTypeNode && !$type->type instanceof GenericTypeNode) {
            return $this->resolve($type->type, $templates);
        }
        if (!$type instanceof GenericTypeNode || !\in_array(strtolower(ltrim($type->type->name, '\\')), self::COLLECTIONS, true) || $type->genericTypes === []) {
            return null;
        }

        return $this->resolve($type->genericTypes[\count($type->genericTypes) - 1], $templates);
    }

    /**
     * The one class a type names, null for anything else: `Foo`, `?Foo`, `Foo|null`, `Foo|false` (as PHP's own
     * functions return: a Foo when it is an object at all), `Collection<Foo>` (as Collection), `self`, `static` and
     * `$this` (TypeExpr::STATIC). Templates are no class.
     *
     * @param list<string> $templates
     */
    private function resolve(TypeNode $type, array $templates = []): ?string
    {
        if ($type instanceof NullableTypeNode) {
            $type = $type->type;
        }
        if ($type instanceof UnionTypeNode) {
            $members = array_values(array_filter(
                $type->types,
                static fn (TypeNode $member): bool => !($member instanceof IdentifierTypeNode && \in_array(strtolower($member->name), ['null', 'false'], true)),
            ));
            if (\count($members) !== 1) {
                return null;
            }
            $type = $members[0];
        }
        if ($type instanceof ThisTypeNode) {
            return TypeExpr::STATIC;
        }
        $name = match (true) {
            $type instanceof IdentifierTypeNode => $type->name,
            $type instanceof GenericTypeNode => $type->type->name,
            $type instanceof CallableTypeNode => $type->identifier->name,
            default => null,
        };
        if ($name === null) {
            return null;
        }
        $lower = strtolower($name);
        if ($lower === 'static' || $lower === '$this') {
            return TypeExpr::STATIC;
        }
        if ($lower === 'self') {
            return 'self';
        }
        if (\in_array($lower, self::BUILTIN, true) || !self::isName($name)) {
            return null;
        }
        if (\in_array($name, $templates, true) || \in_array($name, array_merge(...$this->classTemplates), true)) {
            return null;
        }

        return str_starts_with($name, '\\') ? substr($name, 1) : $this->names->getResolvedClassName(new Name($name))->toString();
    }

    /**
     * A type as a GenericType string: `Collection<int, Item>` gives `Collection<?,App\Item>`, `Item[]` gives
     * `array<App\Item>`, a template of the class `@T`; null when it holds no class at all (`int`, `array{a: int}`).
     *
     * @param list<string> $methodTemplates templates of the method: unknown, bound by its arguments
     * @param list<string> $bound           those a call binds (TEMPLATE_BINDERS), kept as `@#T`
     */
    private function generic(TypeNode $type, array $methodTemplates, array $bound = []): ?string
    {
        if ($type instanceof NullableTypeNode) {
            return $this->generic($type->type, $methodTemplates, $bound);
        }
        // A condition the arguments do not decide, `(TAsync is false ? Response : PromiseInterface)`: either branch.
        if ($type instanceof ConditionalTypeNode || $type instanceof ConditionalTypeForParameterNode) {
            return $this->union([$type->if, $type->else], $methodTemplates);
        }
        if ($type instanceof UnionTypeNode) {
            $parts = array_values(array_filter(
                $type->types,
                static fn (TypeNode $part): bool => !($part instanceof IdentifierTypeNode && \in_array(strtolower($part->name), ['null', 'false', 'void'], true)),
            ));

            return match (\count($parts)) {
                0 => null,
                1 => $this->generic($parts[0], $methodTemplates, $bound),
                default => $this->union($parts, $methodTemplates),
            };
        }
        // An intersection, `NodeDefinition&ParentNodeDefinitionInterface`: as a union, a call goes to the classes
        // declaring its method.
        if ($type instanceof IntersectionTypeNode) {
            return $this->union($type->types, $methodTemplates);
        }
        if ($type instanceof ArrayTypeNode) {
            return GenericType::format(GenericType::ARRAY, [$this->generic($type->type, $methodTemplates, $bound) ?? GenericType::UNKNOWN]);
        }
        if ($type instanceof ThisTypeNode) {
            return TypeExpr::STATIC;
        }

        $arguments = [];
        if ($type instanceof GenericTypeNode) {
            $name = $type->type->name;
            foreach ($type->genericTypes as $argument) {
                $arguments[] = $this->generic($argument, $methodTemplates, $bound) ?? GenericType::UNKNOWN;
            }
        } elseif ($type instanceof CallableTypeNode && strtolower(ltrim($type->identifier->name, '\\')) === 'closure') {
            $name = $type->identifier->name;
        } elseif ($type instanceof IdentifierTypeNode) {
            $name = $type->name;
        } else {
            return null;
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
        if (\in_array($lower, self::BUILTIN, true) || !self::isName($name)) {
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
     * The `@return` type to read, and the plain `@return` beside it: `@phpstan-return` or `@psalm-return` say more,
     * written for the other tools, unless they say nothing this reader understands.
     *
     * @param list<string> $templates
     * @param list<string> $bound
     *
     * @return array{?TypeNode, ?TypeNode}
     */
    private function returnTag(PhpDocNode $doc, array $templates, array $bound): array
    {
        $plain = $tool = null;
        foreach ($doc->getTags() as $tag) {
            if (!$tag->value instanceof ReturnTagValueNode) {
                continue;
            }
            if ($tag->name === '@return') {
                $plain ??= $tag->value->type;
            } elseif (\in_array($tag->name, ['@phpstan-return', '@psalm-return'], true)) {
                $tool ??= $tag->value->type;
            }
        }
        if ($tool === null) {
            return [$plain, $plain];
        }
        $understood = $this->resolve($tool, $templates) !== null || $this->generic($tool, $templates, $bound) !== null
            || $tool instanceof ConditionalTypeNode || $tool instanceof ConditionalTypeForParameterNode;

        return [$understood || $plain === null ? $tool : $plain, $plain];
    }

    /**
     * How a call binds the templates of the method (TEMPLATE_BINDERS), read from its `@param` tags.
     *
     * @param array<string, int> $positions parameter name => position
     * @param list<string>       $templates
     *
     * @return array<string, array{string, int}>
     */
    private function binders(PhpDocNode $doc, array $positions, array $templates): array
    {
        if ($templates === []) {
            return [];
        }
        $binders = [];
        foreach ($this->params($doc) as [$name, $type]) {
            if (!isset($positions[$name])) {
                continue;
            }
            $position = $positions[$name];
            // `class-string<T>|null`, `?T`: the template bound, whatever else the argument may be.
            if ($type instanceof NullableTypeNode) {
                $type = $type->type;
            }
            $members = $type instanceof UnionTypeNode ? array_values(array_filter(
                $type->types,
                static fn (TypeNode $member): bool => !($member instanceof IdentifierTypeNode && strtolower($member->name) === 'null'),
            )) : [$type];
            if (\count($members) !== 1) {
                continue;
            }
            $member = $members[0];
            if ($member instanceof GenericTypeNode && $member->type->name === 'class-string' && \count($member->genericTypes) === 1
                && $member->genericTypes[0] instanceof IdentifierTypeNode && \in_array($member->genericTypes[0]->name, $templates, true)) {
                $binders[$member->genericTypes[0]->name] = ['class', $position];
            } elseif ($member instanceof IdentifierTypeNode && \in_array($member->name, $templates, true)) {
                $binders[$member->name] ??= ['value', $position];
            } elseif ($member instanceof CallableTypeNode && \in_array(strtolower(ltrim($member->identifier->name, '\\')), ['callable', 'closure'], true)
                && $member->returnType instanceof IdentifierTypeNode && \in_array($member->returnType->name, $templates, true)) {
                $binders[$member->returnType->name] ??= ['closure', $position];
            }
        }

        return $binders;
    }

    /**
     * @param array<string, int> $positions parameter name => position
     * @param list<string>       $templates
     * @param list<string>       $bound     the templates of the method a call binds
     *
     * @return list<array{string, int, string}>
     */
    private function assertions(PhpDocNode $doc, array $positions, array $templates, array $bound): array
    {
        $assertions = [];
        foreach ($doc->getTags() as $tag) {
            $value = $tag->value;
            // `=ExpectedType`, Psalm's exact type, is the type all the same.
            if (!$value instanceof AssertTagValueNode || $value->isNegated || !isset($positions[substr($value->parameter, 1)])) {
                continue;
            }
            $type = $this->generic($value->type, $templates, $bound);
            $classes = $type === null ? [] : array_filter(
                GenericType::members($type),
                static fn (string $member): bool => GenericType::isClass(GenericType::base($member)) || str_starts_with($member, '@#'),
            );
            if ($type !== null && \count($classes) === \count(GenericType::members($type))) {
                $assertions[] = [match (true) {
                    str_ends_with($tag->name, '-if-true') => 'true',
                    str_ends_with($tag->name, '-if-false') => 'false',
                    default => 'always',
                }, $positions[substr($value->parameter, 1)], $type];
            }
        }

        return $assertions;
    }

    /**
     * The arguments a docblock gives the templates of parents: `@extends Repository<Order>`, `@use HasFactory<F>`.
     *
     * @return array<string, list<string>>
     */
    private function parentArguments(PhpDocNode $doc): array
    {
        $parents = [];
        foreach ($doc->getTags() as $tag) {
            $type = $tag->value instanceof MixinTagValueNode || \in_array($tag->name, ['@phpstan-mixin', '@psalm-mixin'], true) ? null : $this->tagType($tag);
            if (!$type instanceof GenericTypeNode) {
                continue;
            }
            [$base, $arguments] = GenericType::parse((string) $this->generic($type, []));
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
    private function callableParameters(TypeNode $type, array $methodTemplates): ?array
    {
        if ($type instanceof NullableTypeNode) {
            $type = $type->type;
        }
        // `(callable(TValue): float|int)|string|null` holds a union in a union.
        $members = [$type];
        $callable = null;
        while ($members !== [] && $callable === null) {
            $member = array_shift($members);
            if ($member instanceof UnionTypeNode) {
                array_push($members, ...$member->types);
            } elseif ($member instanceof CallableTypeNode && \in_array(strtolower(ltrim($member->identifier->name, '\\')), ['callable', 'closure'], true)) {
                $callable = $member;
            }
        }
        if ($callable === null) {
            return null;
        }
        $parameters = array_values(array_map(
            fn (CallableTypeParameterNode $parameter): string => $this->generic($parameter->type, $methodTemplates) ?? GenericType::UNKNOWN,
            $callable->parameters,
        ));

        return array_filter($parameters, static fn (string $type): bool => $type !== GenericType::UNKNOWN) === [] ? null : $parameters;
    }

    /**
     * The one class a union leaves once its scalars and the templates of the method are set aside: `Foo|string`,
     * `$this|TWhenReturnType`.
     *
     * @param list<string> $methodTemplates
     */
    private function single(TypeNode $type, array $methodTemplates): ?string
    {
        $generic = $this->generic($type, $methodTemplates);

        return $generic !== null && !GenericType::isGeneric($generic) && ($generic === TypeExpr::STATIC || GenericType::isClass($generic)) ? $generic : null;
    }

    /**
     * A union as a GenericType: the members a method can be called on, `Response|ResponseFactory`. Scalars and arrays
     * are left out, and so is a template of the method, bound by the arguments (`$this|TWhenReturnType`: the closure
     * passed to when() returns the builder it receives, most of the time). `mixed` or `object` may be anything: null,
     * except beside the receiver alone (`static|mixed`, Eloquent's `scopes()`: the chain goes on on the receiver).
     *
     * @param array<TypeNode> $parts
     * @param list<string>    $methodTemplates
     */
    private function union(array $parts, array $methodTemplates): ?string
    {
        $members = [];
        $anything = false;
        foreach ($parts as $part) {
            if ($part instanceof IdentifierTypeNode && \in_array(strtolower($part->name), ['mixed', 'object'], true)) {
                $anything = true;
                continue;
            }
            if ($part instanceof IdentifierTypeNode && \in_array($part->name, $methodTemplates, true)) {
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
     * A conditional return type as a tree, null when the type is none: `($abstract is null ? Application : mixed)`.
     *
     * @param array<string, int> $parameters name => position
     *
     * @return array<string, mixed>|null
     */
    private function conditional(TypeNode $type, array $parameters, int $depth = 0): ?array
    {
        if ($depth > 5 || !$type instanceof ConditionalTypeForParameterNode || !isset($parameters[substr($type->parameterName, 1)])) {
            return null;
        }
        $position = $parameters[substr($type->parameterName, 1)];
        $target = $type->targetType;
        $template = $target instanceof GenericTypeNode && $target->type->name === 'class-string' && \count($target->genericTypes) === 1
            && $target->genericTypes[0] instanceof IdentifierTypeNode ? $target->genericTypes[0]->name : null;
        $test = match (true) {
            $target instanceof IdentifierTypeNode && strtolower($target->name) === 'null' => 'null',
            $template !== null => 'class',
            default => null,
        };
        if ($test === null) {
            return null;
        }
        $leaf = function (TypeNode $branch) use ($parameters, $depth, $position, $template): array {
            if ($template !== null && $branch instanceof IdentifierTypeNode && $branch->name === $template) {
                return ['arg' => $position];
            }

            return $this->conditional($branch, $parameters, $depth + 1) ?? ['type' => $this->resolve($branch)];
        };
        [$then, $else] = $type->negated ? [$leaf($type->else), $leaf($type->if)] : [$leaf($type->if), $leaf($type->else)];

        return ['param' => $position, 'test' => $test, 'then' => $then, 'else' => $else];
    }

    /**
     * Whether the text is a class name a docblock may write: `Foo`, `\App\Foo`.
     */
    private static function isName(string $name): bool
    {
        foreach (explode('\\', str_starts_with($name, '\\') ? substr($name, 1) : $name) as $segment) {
            $rest = str_replace('_', '', $segment);
            if ($segment === '' || !(ctype_alpha($segment[0]) || $segment[0] === '_') || ($rest !== '' && !ctype_alnum($rest))) {
                return false;
            }
        }

        return true;
    }

    /**
     * The template parameters a docblock declares, in order: `@template T of Model`.
     *
     * @return list<string>
     */
    private function templates(PhpDocNode $doc): array
    {
        $templates = [];
        foreach ($doc->getTags() as $tag) {
            if ($tag->value instanceof TemplateTagValueNode && !\in_array($tag->value->name, $templates, true)) {
                $templates[] = $tag->value->name;
            }
        }

        return $templates;
    }
}
