<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

use PhpParser\Node as AstNode;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;

/**
 * API Platform resources: `#[ApiResource(operations: [new Get(...), new Post(...)])]`, or operation attributes alone
 * (`#[Get]`), become routes. Each one is handled by its controller, else its processor (writes) or provider (reads),
 * else by API Platform itself (Doctrine).
 *
 * Without a uriTemplate, the path is the one API Platform generates by default: the short name in snake_case,
 * pluralized (`/purchase_orders`, `/purchase_orders/{id}`). The prefix of the `api_platform` route import (`/api`) is
 * added by the builder.
 */
final class ApiPlatformResources
{
    public const LOADER = 'api_platform';

    private const NAMESPACE = 'ApiPlatform\\Metadata\\';

    /**
     * Operation class => [HTTP method, collection or item].
     */
    private const OPERATIONS = [
        'Get' => ['GET', false],
        'GetCollection' => ['GET', true],
        'Post' => ['POST', true],
        'Put' => ['PUT', false],
        'Patch' => ['PATCH', false],
        'Delete' => ['DELETE', false],
        'HttpOperation' => [null, false],
    ];

    /**
     * Created when a resource lists no operation (API Platform 3.1 and later).
     */
    private const DEFAULT_OPERATIONS = ['Get', 'GetCollection', 'Post', 'Patch', 'Delete'];

    /**
     * @param \Closure(Name): ?string $resolve
     * @param \Closure(Expr): ?string $path    a string, or a class constant holding one
     */
    public function __construct(
        private readonly string $file,
        private readonly \Closure $resolve,
        private readonly \Closure $path,
    ) {
    }

    /**
     * @param array<AstNode\AttributeGroup> $groups the attributes of the class
     *
     * @return list<RouteFact>
     */
    public function routes(string $class, array $groups): array
    {
        $routes = [];
        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                $name = $this->operationName($attribute->name);
                if ($name === 'ApiResource') {
                    $resource = $this->arguments($attribute->args);
                    $operations = $resource['operations'] ?? null;
                    $list = $operations instanceof Expr\Array_
                        ? array_map(static fn (AstNode\ArrayItem $item): Expr => $item->value, $operations->items)
                        : array_map(static fn (string $operation): string => $operation, self::DEFAULT_OPERATIONS);
                    foreach ($list as $operation) {
                        if (\is_string($operation)) {
                            $route = $this->route($class, $operation, [], $resource, $attribute->getStartLine());
                        } elseif ($operation instanceof Expr\New_ && $operation->class instanceof Name) {
                            $route = $this->route($class, $this->operationName($operation->class), $this->arguments($operation->args), $resource, $operation->getStartLine());
                        } else {
                            $route = null;
                        }
                        if ($route !== null) {
                            $routes[] = $route;
                        }
                    }
                } elseif ($name !== null) {
                    $route = $this->route($class, $name, $this->arguments($attribute->args), [], $attribute->getStartLine());
                    if ($route !== null) {
                        $routes[] = $route;
                    }
                }
            }
        }

        return $routes;
    }

    /**
     * @param array<string, Expr> $operation
     * @param array<string, Expr> $resource
     */
    private function route(string $class, ?string $name, array $operation, array $resource, int $line): ?RouteFact
    {
        if ($name === null || !isset(self::OPERATIONS[$name])) {
            return null;
        }
        [$method, $collection] = self::OPERATIONS[$name];
        $method ??= isset($operation['method']) ? self::httpMethod((string) $this->string($operation['method'])) : 'GET';
        $option = fn (string $key): ?Expr => $operation[$key] ?? $resource[$key] ?? null;

        $template = $option('uriTemplate');
        $path = $template === null ? $this->defaultPath($class, $resource, $collection) : $this->string($template);
        if ($path === null || $path === '') {
            return null;
        }
        $path = preg_replace('/(\.\{_format\}|\{\._format\})$/', '', $path) ?? $path;
        $prefix = $option('routePrefix');
        $path = ($prefix === null ? '' : (string) $this->string($prefix)) . '/' . $path;

        $controller = $this->classValue($option('controller'));
        $handler = \in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) ? ['provider', 'provide'] : ['processor', 'process'];
        $state = $this->classValue($option($handler[0]));

        // The classes the operation names: the resource, its input and output, and any other `X::class`.
        $references = [$class];
        foreach ([...$resource, ...$operation] as $key => $value) {
            // Not `operations`: the other operations of the resource are other routes.
            if (!\in_array($key, ['controller', 'provider', 'processor', 'operations'], true)) {
                array_push($references, ...$this->classes($value));
            }
        }

        return new RouteFact(
            [$method],
            RouteFact::normalizePath($path),
            $controller ?? $state,
            $controller !== null ? null : ($state === null ? null : $handler[1]),
            $this->file,
            $line,
            references: array_values(array_unique($references)),
            byFramework: $controller === null && $state === null,
            loader: self::LOADER,
        );
    }

    /**
     * @param array<string, Expr> $resource
     */
    private function defaultPath(string $class, array $resource, bool $collection): string
    {
        $shortName = isset($resource['shortName']) ? $this->string($resource['shortName']) : null;
        $shortName ??= substr($class, (int) strrpos('\\' . $class, '\\'));
        // Doctrine's tableize: PurchaseOrder gives purchase_order.
        $segment = self::pluralize(strtolower(preg_replace('/(?<=\w)([A-Z])/', '_$1', $shortName) ?? $shortName));

        return '/' . $segment . ($collection ? '' : '/{id}');
    }

    /**
     * `POST`, or a constant naming it: `Request::METHOD_POST` (Symfony's, read by its name).
     */
    private static function httpMethod(string $value): string
    {
        if (preg_match('/::METHOD_([A-Z]+)\}?$/i', $value, $match) === 1) {
            return strtoupper($match[1]);
        }

        return preg_match('/^[A-Za-z]+$/', $value) === 1 ? strtoupper($value) : 'GET';
    }

    /**
     * English plural of the last word, as Doctrine's inflector gives it for the usual cases.
     */
    public static function pluralize(string $word): string
    {
        return match (true) {
            preg_match('/[^aeiou]y$/', $word) === 1 => substr($word, 0, -1) . 'ies',
            preg_match('/(s|x|z|ch|sh)$/', $word) === 1 => $word . 'es',
            default => $word . 's',
        };
    }

    private function operationName(Name $name): ?string
    {
        $resolved = ($this->resolve)($name);
        if ($resolved === null || !str_starts_with($resolved, self::NAMESPACE)) {
            return null;
        }
        $short = substr($resolved, \strlen(self::NAMESPACE));

        return $short === 'ApiResource' || isset(self::OPERATIONS[$short]) ? $short : null;
    }

    /**
     * Named arguments only: API Platform's attributes are written with them.
     *
     * @param array<AstNode\Arg|AstNode\ArgPlaceholder|AstNode\VariadicPlaceholder> $args
     *
     * @return array<string, Expr>
     */
    private function arguments(array $args): array
    {
        $values = [];
        foreach ($args as $argument) {
            if ($argument instanceof AstNode\Arg && $argument->name !== null) {
                $values[$argument->name->toString()] = $argument->value;
            }
        }

        return $values;
    }

    private function string(Expr $expr): ?string
    {
        return ($this->path)($expr);
    }

    private function classValue(?Expr $expr): ?string
    {
        return $expr instanceof Expr\ClassConstFetch && $expr->class instanceof Name && $expr->name instanceof AstNode\Identifier
            && strtolower($expr->name->toString()) === 'class'
            ? ($this->resolve)($expr->class)
            : null;
    }

    /**
     * @return list<string> every `X::class` written in an expression
     */
    private function classes(Expr $expr): array
    {
        $class = $this->classValue($expr);
        if ($class !== null) {
            return [$class];
        }

        $classes = [];
        $children = match (true) {
            $expr instanceof Expr\Array_ => array_merge(...array_map(
                static fn (AstNode\ArrayItem $item): array => array_values(array_filter([$item->key, $item->value])),
                $expr->items,
            )),
            $expr instanceof Expr\New_ => array_map(
                static fn (AstNode\Arg $argument): Expr => $argument->value,
                array_values(array_filter($expr->args, static fn ($argument): bool => $argument instanceof AstNode\Arg)),
            ),
            default => [],
        };
        foreach ($children as $child) {
            array_push($classes, ...$this->classes($child));
        }

        return $classes;
    }
}
