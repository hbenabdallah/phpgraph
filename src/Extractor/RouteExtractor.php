<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

use PhpParser\Node as AstNode;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;

/**
 * HTTP routes declared in PHP, and HTTP calls, in one file. Symfony attributes and Laravel route files are read the
 * same way: a path, methods, and a controller when it is a class.
 */
final class RouteExtractor
{
    private const HTTP_VERBS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head'];

    /**
     * Laravel resource routes: action => [method, path suffix].
     */
    private const RESOURCE_ACTIONS = [
        'index' => ['GET', ''],
        'create' => ['GET', '/create'],
        'store' => ['POST', ''],
        'show' => ['GET', '/{id}'],
        'edit' => ['GET', '/{id}/edit'],
        'update' => ['PUT|PATCH', '/{id}'],
        'destroy' => ['DELETE', '/{id}'],
    ];

    /** @var list<RouteFact> */
    private array $routes = [];

    /** @var list<PendingRequest> */
    private array $requests = [];

    /** @var list<string> path prefixes of the enclosing Laravel route groups */
    private array $prefixes = [];

    /**
     * @param list<string> $prefixes
     */
    public function __construct(private readonly string $file, array $prefixes = [])
    {
        $this->prefixes = $prefixes;
    }

    /**
     * Symfony #[Route] on a controller method, prefixed by the #[Route] of its class; a class #[Route] alone
     * routes to __invoke.
     *
     * @param list<array{name: string, path: ?string, methods: list<string>}> $methodRoutes
     * @param list<array{name: string, path: ?string, methods: list<string>}> $classRoutes
     */
    public function onControllerMethod(string $class, string $method, array $methodRoutes, array $classRoutes, int $line): void
    {
        $prefix = $classRoutes[0]['path'] ?? '';
        foreach ($methodRoutes as $route) {
            if ($route['path'] !== null) {
                $this->routes[] = new RouteFact($route['methods'], RouteFact::normalizePath($prefix . '/' . $route['path']), $class, $method, $this->file, $line);
            }
        }

        if ($methodRoutes === [] && strtolower($method) === '__invoke') {
            foreach ($classRoutes as $route) {
                if ($route['path'] !== null) {
                    $this->routes[] = new RouteFact($route['methods'], RouteFact::normalizePath($route['path']), $class, null, $this->file, $line);
                }
            }
        }
    }

    public function pushPrefix(string $prefix): void
    {
        $this->prefixes[] = $prefix;
    }

    public function popPrefix(): void
    {
        array_pop($this->prefixes);
    }

    /**
     * Laravel: Route::get('/orders', [OrderController::class, 'index']), Route::match(['GET', 'POST'], ...),
     * Route::resource('photos', PhotoController::class).
     *
     * @param array<AstNode> $args
     * @param \Closure(Name): ?string $resolve
     */
    public function onLaravelRoute(string $verb, array $args, int $line, \Closure $resolve): void
    {
        $verb = strtolower($verb);
        $values = array_values(array_filter($args, static fn (AstNode $arg): bool => $arg instanceof AstNode\Arg));
        /** @var list<AstNode\Arg> $values */

        if ($verb === 'resource' || $verb === 'apiresource') {
            $path = $this->literal($values[0]->value ?? null);
            $controller = $this->classConstant($values[1]->value ?? null, $resolve);
            if ($path === null || $controller === null) {
                return;
            }
            foreach (self::RESOURCE_ACTIONS as $action => [$methods, $suffix]) {
                if ($verb === 'apiresource' && \in_array($action, ['create', 'edit'], true)) {
                    continue;
                }
                $this->addLaravelRoute(explode('|', $methods), $path . $suffix, $controller, $action, $line);
            }

            return;
        }

        [$methods, $pathArg, $handlerArg] = match ($verb) {
            'match' => [$this->literals($values[0]->value ?? null), $values[1] ?? null, $values[2] ?? null],
            'any' => [[], $values[0] ?? null, $values[1] ?? null],
            default => \in_array($verb, self::HTTP_VERBS, true) ? [[strtoupper($verb)], $values[0] ?? null, $values[1] ?? null] : [null, null, null],
        };
        $path = $this->literal($pathArg?->value);
        if ($methods === null || $path === null) {
            return;
        }

        [$controller, $action] = $this->laravelHandler($handlerArg?->value, $resolve);
        $this->addLaravelRoute(array_map('strtoupper', $methods), $path, $controller, $action, $line);
    }

    public function onRequest(PendingRequest $request): void
    {
        $this->requests[] = $request;
    }

    /**
     * An HTTP call when the URL looks like one: `request('POST', $url)`, `get('/orders')`, `post("$base/orders")`.
     *
     * @param array<AstNode> $args
     *
     * @return array{?string, Expr}|null the HTTP method and the URL argument
     */
    public static function requestArguments(string $method, array $args): ?array
    {
        $method = strtolower($method);
        $values = array_values(array_filter($args, static fn (AstNode $arg): bool => $arg instanceof AstNode\Arg && !$arg->unpack));
        /** @var list<AstNode\Arg> $values */
        if ($method === 'request' && isset($values[1])) {
            $verb = $values[0]->value instanceof Scalar\String_ ? strtoupper($values[0]->value->value) : null;

            return [$verb, $values[1]->value];
        }

        return \in_array($method, self::HTTP_VERBS, true) && isset($values[0]) ? [strtoupper($method), $values[0]->value] : null;
    }

    /**
     * The path pattern of a URL expression, `*` for what is computed: "$base/orders/$id" gives `/orders/*`.
     *
     * @return array{path: string, literal: bool, absolute: bool}|null null when it does not look like a URL or a path
     */
    public static function urlPattern(Expr $url): ?array
    {
        $pattern = self::pattern($url);
        $absolute = preg_match('#^[a-z][a-z0-9+.-]*://#i', $pattern) === 1;
        $path = $absolute ? (preg_replace('#^[a-z][a-z0-9+.-]*://[^/]*#i', '', $pattern) ?? '') : $pattern;
        // A base URL held in a variable: "$base/orders" is `*/orders`.
        if (str_starts_with($path, '*/')) {
            $path = substr($path, 1);
        }
        $path = explode('?', explode('#', $path)[0])[0];
        if (!str_starts_with($path, '/') || $path === '/*') {
            return null;
        }

        return ['path' => RouteFact::normalizePath($path), 'literal' => !str_contains($path, '*'), 'absolute' => $absolute];
    }

    /**
     * @return list<RouteFact>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * @return list<PendingRequest>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * @param list<string> $methods
     */
    private function addLaravelRoute(array $methods, string $path, ?string $controller, ?string $action, int $line): void
    {
        $this->routes[] = new RouteFact($methods, RouteFact::normalizePath(implode('/', $this->prefixes) . '/' . $path), $controller, $action, $this->file, $line);
    }

    /**
     * [OrderController::class, 'index'], 'OrderController@index', or OrderController::class (invokable).
     *
     * @param \Closure(Name): ?string $resolve
     *
     * @return array{?string, ?string}
     */
    private function laravelHandler(?Expr $handler, \Closure $resolve): array
    {
        if ($handler instanceof Expr\Array_ && \count($handler->items) === 2) {
            return [$this->classConstant($handler->items[0]->value, $resolve), $this->literal($handler->items[1]->value)];
        }
        if ($handler instanceof Scalar\String_ && str_contains($handler->value, '@')) {
            [$class, $action] = explode('@', $handler->value, 2);

            return [ltrim($class, '\\'), $action];
        }

        return [$this->classConstant($handler, $resolve), null];
    }

    /**
     * @param \Closure(Name): ?string $resolve
     */
    private function classConstant(?Expr $expr, \Closure $resolve): ?string
    {
        return $expr instanceof Expr\ClassConstFetch && $expr->class instanceof Name && $expr->name instanceof AstNode\Identifier
            && strtolower($expr->name->toString()) === 'class'
            ? $resolve($expr->class)
            : null;
    }

    private function literal(?Expr $expr): ?string
    {
        return $expr instanceof Scalar\String_ ? $expr->value : null;
    }

    /**
     * @return list<string>|null
     */
    private function literals(?Expr $expr): ?array
    {
        if (!$expr instanceof Expr\Array_) {
            $single = $this->literal($expr);

            return $single === null ? null : [$single];
        }

        $values = [];
        foreach ($expr->items as $item) {
            $value = $this->literal($item->value);
            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values;
    }

    private static function pattern(Expr $expr): string
    {
        return match (true) {
            $expr instanceof Scalar\String_ => $expr->value,
            $expr instanceof Scalar\InterpolatedString => implode('', array_map(
                static fn (AstNode $part): string => $part instanceof AstNode\InterpolatedStringPart ? $part->value : '*',
                $expr->parts,
            )),
            $expr instanceof Expr\BinaryOp\Concat => self::pattern($expr->left) . self::pattern($expr->right),
            default => '*',
        };
    }
}
