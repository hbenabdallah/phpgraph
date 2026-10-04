<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

use PhpParser\Node as AstNode;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;

/**
 * Symfony service configuration written in helpers: a method or function taking the configurator, called from the
 * configuration files with literal arguments.
 *
 *     public static function wire(ServicesConfigurator $services, string $prefix, string $namespace): void {
 *         $services->load($namespace . '\Rules\', ...)->tag($prefix . '.rule');
 *         $services->set($prefix . '.validator', Validator::class)->args([tagged_iterator($prefix . '.rule')]);
 *     }
 *     Wiring::wire($services, 'sales_order', 'App\SalesOrder');
 *
 * The facts of a helper are recorded as templates of its parameters, and the calls with their arguments; the builder
 * evaluates them (ConfigurationEvaluator). Nothing is run: a template is a string expression, and the conditions of
 * the helper are ignored.
 *
 * A template is a nested array: ['lit', 'text'], ['param', 'prefix'], ['dir'] (`__DIR__`), ['const', 'Class::NAME'],
 * ['concat', [...]], ['call', 'str_replace', [...]].
 */
final class ConfigurationHelpers
{
    /**
     * String functions a template may call: pure, evaluated by the builder.
     */
    public const FUNCTIONS = ['str_replace', 'sprintf', 'trim', 'ltrim', 'rtrim', 'strtolower', 'strtoupper', 'ucfirst', 'lcfirst', 'dirname', 'basename', 'implode'];

    /** @var array<string, array{params: list<string>, facts: list<array<string, mixed>>}> helper id => what it declares */
    private array $helpers = [];

    /** @var list<array{caller: ?string, callee: string, args: list<array{?string, ?array<mixed>}>, line: int}> */
    private array $calls = [];

    private ?string $helper = null;

    /** @var list<string> */
    private array $params = [];

    /**
     * @param \Closure(Name): ?string $resolve
     */
    public function __construct(private readonly \Closure $resolve)
    {
    }

    /**
     * @param array<AstNode\Param> $params
     * @param list<?string>        $types  the class of each parameter
     */
    public function enterCallable(string $id, array $params, array $types): void
    {
        $this->helper = null;
        $this->params = [];
        foreach ($types as $type) {
            if ($type !== null && preg_match('/(Services|Container)Configurator$/', $type) === 1) {
                $this->helper = $id;
            }
        }
        if ($this->helper === null) {
            return;
        }

        foreach ($params as $param) {
            $this->params[] = $param->var instanceof Expr\Variable && \is_string($param->var->name) ? $param->var->name : '';
        }
        $this->helpers[$id] = ['params' => $this->params, 'facts' => []];
    }

    public function leaveCallable(): void
    {
        $this->helper = null;
        $this->params = [];
    }

    /**
     * A call that may be one to a helper: inside a helper, or in a configuration file.
     *
     * @param array<AstNode\Arg|AstNode\ArgPlaceholder|AstNode\VariadicPlaceholder> $args
     */
    public function onCall(string $callee, array $args, bool $inConfiguration, int $line): void
    {
        if ($this->helper === null && !$inConfiguration) {
            return;
        }
        // A helper is passed the configurator: a call without any variable argument is not one.
        $variables = array_filter($args, static fn ($argument): bool => $argument instanceof AstNode\Arg && $argument->value instanceof Expr\Variable);
        if ($variables === []) {
            return;
        }

        $templates = [];
        foreach ($args as $argument) {
            if ($argument instanceof AstNode\Arg && !$argument->unpack) {
                $templates[] = [$argument->name?->toString(), $this->template($argument->value)];
            }
        }
        $this->calls[] = ['caller' => $this->helper, 'callee' => $callee, 'args' => $templates, 'line' => $line];
    }

    /**
     * A configurator call inside a helper: `->set()`, `->load()`, `->instanceof()` with `->tag()`, `->args()`, `->arg()`.
     */
    public function onMethodCall(Expr\MethodCall $node): void
    {
        if ($this->helper === null || !$node->name instanceof Identifier) {
            return;
        }

        $name = strtolower($node->name->toString());
        $first = $this->argument($node->args, 0);
        $second = $this->argument($node->args, 1);
        $definition = $this->definition($node->var);

        $template = $first === null ? null : $this->template($first);
        if ($name === 'set' && $template !== null) {
            $this->fact(['kind' => 'set', 'id' => $template, 'class' => $second === null ? null : $this->template($second)]);
        } elseif ($name === 'tag' && $template !== null && $definition !== null) {
            $this->fact(['kind' => 'tag', 'definition' => $definition, 'name' => $template]);
        } elseif (($name === 'args' || $name === 'arg') && $definition !== null && $definition[0] === 'set') {
            foreach ($this->injections($name === 'args' ? $first : $second) as $injection) {
                $this->fact(['kind' => 'argument', 'id' => $definition[1]] + $injection);
            }
        }
    }

    /**
     * @return array<string, array{params: list<string>, facts: list<array<string, mixed>>}>
     */
    public function helpers(): array
    {
        return $this->helpers;
    }

    /**
     * @return list<array{caller: ?string, callee: string, args: list<array{?string, ?array<mixed>}>, line: int}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * @param array<string, mixed> $fact
     */
    private function fact(array $fact): void
    {
        if ($this->helper !== null) {
            $this->helpers[$this->helper]['facts'][] = $fact;
        }
    }

    /**
     * The definition a chain configures: ['set', id], ['load', namespace] or ['instanceof', type], as templates.
     *
     * @return array{string, array<mixed>}|null
     */
    private function definition(Expr $chain): ?array
    {
        for (; $chain instanceof Expr\MethodCall; $chain = $chain->var) {
            if (!$chain->name instanceof Identifier) {
                continue;
            }
            $name = strtolower($chain->name->toString());
            $first = $this->argument($chain->args, 0);
            $template = $first === null ? null : $this->template($first);
            if ($template !== null && \in_array($name, ['set', 'load', 'instanceof'], true)) {
                return [$name, $template];
            }
        }

        return null;
    }

    /**
     * `tagged_iterator(...)`, `tagged_locator(...)`, `service(...)`, alone or in an array.
     *
     * @return list<array{tag: ?array<mixed>, service: ?array<mixed>, locator?: bool}>
     */
    private function injections(?Expr $value): array
    {
        if ($value instanceof Expr\Array_) {
            $injections = [];
            foreach ($value->items as $item) {
                array_push($injections, ...$this->injections($item->value));
            }

            return $injections;
        }
        if (!$value instanceof Expr\FuncCall || !$value->name instanceof Name) {
            return [];
        }

        $argument = $this->argument($value->args, 0);
        $template = $argument === null ? null : $this->template($argument);
        if ($template === null) {
            return [];
        }

        return match (strtolower($value->name->getLast())) {
            'tagged_iterator' => [['tag' => $template, 'service' => null]],
            'tagged_locator' => [['tag' => $template, 'service' => null, 'locator' => true]],
            'service' => [['tag' => null, 'service' => $template]],
            default => [],
        };
    }

    /**
     * @return array<mixed>|null null for an expression a template cannot hold
     */
    private function template(Expr $expr): ?array
    {
        if ($expr instanceof Scalar\String_) {
            return ['lit', $expr->value];
        }
        if ($expr instanceof Scalar\Int_) {
            return ['lit', (string) $expr->value];
        }
        if ($expr instanceof Scalar\MagicConst\Dir) {
            return ['dir'];
        }
        if ($expr instanceof Expr\Variable && \is_string($expr->name) && \in_array($expr->name, $this->params, true)) {
            return ['param', $expr->name];
        }
        if ($expr instanceof Expr\ClassConstFetch && $expr->class instanceof Name && $expr->name instanceof Identifier) {
            $class = ($this->resolve)($expr->class);
            if ($class === null) {
                return null;
            }

            return strtolower($expr->name->toString()) === 'class' ? ['lit', ltrim($class, '\\')] : ['const', $class . '::' . $expr->name->toString()];
        }
        if ($expr instanceof Expr\BinaryOp\Concat) {
            $left = $this->template($expr->left);
            $right = $this->template($expr->right);

            return $left === null || $right === null ? null : ['concat', [$left, $right]];
        }
        if ($expr instanceof Scalar\InterpolatedString) {
            $parts = [];
            foreach ($expr->parts as $part) {
                $parts[] = $part instanceof AstNode\InterpolatedStringPart ? ['lit', $part->value] : $this->template($part);
            }

            return \in_array(null, $parts, true) ? null : ['concat', $parts];
        }
        if ($expr instanceof Expr\FuncCall && $expr->name instanceof Name && \in_array(strtolower($expr->name->getLast()), self::FUNCTIONS, true)) {
            $arguments = [];
            foreach ($expr->args as $argument) {
                if (!$argument instanceof AstNode\Arg || $argument->unpack || $argument->name !== null) {
                    return null;
                }
                $arguments[] = $argument->value instanceof Expr\Array_ ? $this->listTemplate($argument->value) : $this->template($argument->value);
            }

            return \in_array(null, $arguments, true) ? null : ['call', strtolower($expr->name->getLast()), $arguments];
        }

        return null;
    }

    /**
     * An array of strings, for str_replace() and implode(): ['list', [...]].
     *
     * @return array<mixed>|null
     */
    private function listTemplate(Expr\Array_ $array): ?array
    {
        $items = [];
        foreach ($array->items as $item) {
            $items[] = $item->key === null ? $this->template($item->value) : null;
        }

        return \in_array(null, $items, true) ? null : ['list', $items];
    }

    /**
     * @param array<AstNode\Arg|AstNode\ArgPlaceholder|AstNode\VariadicPlaceholder> $args
     */
    private function argument(array $args, int $position): ?Expr
    {
        $argument = $args[$position] ?? null;

        return $argument instanceof AstNode\Arg && !$argument->unpack ? $argument->value : null;
    }
}
