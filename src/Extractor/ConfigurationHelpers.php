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
 * The facts of a helper are recorded as templates of its parameters (Template), and the calls with their arguments;
 * the builder evaluates them (ConfigurationEvaluator). Nothing is run: a template is a string expression, and the
 * conditions of the helper are ignored.
 */
final class ConfigurationHelpers
{
    /**
     * String functions a template may call: pure, evaluated by the builder.
     */
    public const FUNCTIONS = ['str_replace', 'sprintf', 'trim', 'ltrim', 'rtrim', 'strtolower', 'strtoupper', 'ucfirst', 'lcfirst', 'dirname', 'basename', 'implode'];

    /** @var array<string, list<string>> helper id => its parameters */
    private array $helperParams = [];

    /** @var array<string, list<ServiceDefinitionFact|ServiceTagFact|ServiceArgumentFact>> helper id => what it declares */
    private array $helperFacts = [];

    /** @var list<PendingHelperCall> */
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
        $this->helperParams[$id] = $this->params;
        $this->helperFacts[$id] = [];
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
        $variables = array_filter($args, static fn (AstNode\Arg|AstNode\ArgPlaceholder|AstNode\VariadicPlaceholder $argument): bool => $argument instanceof AstNode\Arg && $argument->value instanceof Expr\Variable);
        if ($variables === []) {
            return;
        }

        $arguments = [];
        foreach ($args as $argument) {
            if ($argument instanceof AstNode\Arg && !$argument->unpack) {
                $arguments[] = new HelperArgument($argument->name?->toString(), $this->template($argument->value));
            }
        }
        $this->calls[] = new PendingHelperCall($this->helper, $callee, $arguments, $line);
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
            $this->fact(new ServiceDefinitionFact($template, $second === null ? null : $this->template($second)));
        } elseif ($name === 'tag' && $template !== null && $definition !== null) {
            $this->fact(new ServiceTagFact($definition[0], $definition[1], $template));
        } elseif (($name === 'args' || $name === 'arg') && $definition !== null && $definition[0] === DefinitionKind::Set) {
            foreach ($this->injections($definition[1], $name === 'args' ? $first : $second) as $injection) {
                $this->fact($injection);
            }
        }
    }

    /**
     * @return array<string, ConfigurationHelper> helper id => what it declares
     */
    public function helpers(): array
    {
        $helpers = [];
        foreach ($this->helperParams as $id => $params) {
            $helpers[$id] = new ConfigurationHelper($params, $this->helperFacts[$id] ?? []);
        }

        return $helpers;
    }

    /**
     * @return list<PendingHelperCall>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    private function fact(ServiceDefinitionFact|ServiceTagFact|ServiceArgumentFact $fact): void
    {
        if ($this->helper !== null) {
            $this->helperFacts[$this->helper][] = $fact;
        }
    }

    /**
     * The definition a chain configures: a service id, a namespace or a type, as a template.
     *
     * @return array{DefinitionKind, Template}|null
     */
    private function definition(Expr $chain): ?array
    {
        for (; $chain instanceof Expr\MethodCall; $chain = $chain->var) {
            if (!$chain->name instanceof Identifier) {
                continue;
            }
            $kind = DefinitionKind::tryFrom(strtolower($chain->name->toString()));
            $first = $this->argument($chain->args, 0);
            $template = $first === null ? null : $this->template($first);
            if ($template !== null && $kind !== null) {
                return [$kind, $template];
            }
        }

        return null;
    }

    /**
     * `tagged_iterator(...)`, `tagged_locator(...)`, `service(...)`, alone or in an array, injected into a service.
     *
     * @return list<ServiceArgumentFact>
     */
    private function injections(Template $id, ?Expr $value): array
    {
        if ($value instanceof Expr\Array_) {
            $injections = [];
            foreach ($value->items as $item) {
                array_push($injections, ...$this->injections($id, $item->value));
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
            'tagged_iterator' => [new ServiceArgumentFact($id, $template, null)],
            'tagged_locator' => [new ServiceArgumentFact($id, $template, null, locator: true)],
            'service' => [new ServiceArgumentFact($id, null, $template)],
            default => [],
        };
    }

    /**
     * @return ?Template null for an expression a template cannot hold
     */
    private function template(Expr $expr): ?Template
    {
        if ($expr instanceof Scalar\String_) {
            return Template::literal($expr->value);
        }
        if ($expr instanceof Scalar\Int_) {
            return Template::literal((string) $expr->value);
        }
        if ($expr instanceof Scalar\MagicConst\Dir) {
            return Template::directory();
        }
        if ($expr instanceof Expr\Variable && \is_string($expr->name) && \in_array($expr->name, $this->params, true)) {
            return Template::parameter($expr->name);
        }
        if ($expr instanceof Expr\ClassConstFetch && $expr->class instanceof Name && $expr->name instanceof Identifier) {
            $class = ($this->resolve)($expr->class);
            if ($class === null) {
                return null;
            }

            return strtolower($expr->name->toString()) === 'class' ? Template::literal(ltrim($class, '\\')) : Template::constant($class . '::' . $expr->name->toString());
        }
        if ($expr instanceof Expr\BinaryOp\Concat) {
            $left = $this->template($expr->left);
            $right = $this->template($expr->right);

            return $left === null || $right === null ? null : Template::concat([$left, $right]);
        }
        if ($expr instanceof Scalar\InterpolatedString) {
            $parts = [];
            foreach ($expr->parts as $part) {
                $template = $part instanceof AstNode\InterpolatedStringPart ? Template::literal($part->value) : $this->template($part);
                if ($template === null) {
                    return null;
                }
                $parts[] = $template;
            }

            return Template::concat($parts);
        }
        if ($expr instanceof Expr\FuncCall && $expr->name instanceof Name && \in_array(strtolower($expr->name->getLast()), self::FUNCTIONS, true)) {
            $arguments = [];
            foreach ($expr->args as $argument) {
                if (!$argument instanceof AstNode\Arg || $argument->unpack || $argument->name !== null) {
                    return null;
                }
                $template = $argument->value instanceof Expr\Array_ ? $this->listTemplate($argument->value) : $this->template($argument->value);
                if ($template === null) {
                    return null;
                }
                $arguments[] = $template;
            }

            return Template::call(strtolower($expr->name->getLast()), $arguments);
        }

        return null;
    }

    /**
     * An array of strings, for str_replace() and implode().
     */
    private function listTemplate(Expr\Array_ $array): ?Template
    {
        $items = [];
        foreach ($array->items as $item) {
            $template = $item->key === null ? $this->template($item->value) : null;
            if ($template === null) {
                return null;
            }
            $items[] = $template;
        }

        return Template::list($items);
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
