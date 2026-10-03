<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

/**
 * An HTTP route declared in the code or in a routing file, with the controller handling it when it is a class.
 */
final readonly class RouteFact
{
    /**
     * @param list<string> $methods    uppercase HTTP methods, empty for any
     * @param string       $path       path pattern, `/orders/{id}`
     * @param ?string      $controller controller class, null for a closure or a service id
     * @param ?string      $action     controller method name, null for an invokable controller
     * @param ?string      $serviceId  container service id naming the controller instead of its class
     * @param list<string> $references other classes the declaration names (an API Platform resource, its input, output...)
     * @param bool         $byFramework handled by the framework itself when no controller is named (API Platform with Doctrine)
     * @param ?string      $loader     the route loader adding its prefix to the path (`api_platform`), null for none
     */
    public function __construct(
        public array $methods,
        public string $path,
        public ?string $controller,
        public ?string $action,
        public string $file,
        public ?int $line,
        public ?string $serviceId = null,
        public array $references = [],
        public bool $byFramework = false,
        public ?string $loader = null,
    ) {
    }

    /**
     * The route node id: the declaring file and the label. Two applications of one repository may both declare
     * `GET /health-check`: they are two routes.
     */
    public function id(): string
    {
        return 'route:' . $this->file . '#' . $this->label();
    }

    /**
     * `GET|POST /orders/{id}`, the route node label.
     */
    public function label(): string
    {
        return ($this->methods === [] ? 'ANY' : implode('|', $this->methods)) . ' ' . $this->path;
    }

    /**
     * The same route with the class constants of its path replaced, `{const:Class::NAME}`; an unknown one is left
     * as `{Class::NAME}`.
     *
     * @param \Closure(string): ?string $constant value of `Class::NAME`
     */
    public function withConstants(\Closure $constant): self
    {
        if (!str_contains($this->path, '{const:')) {
            return $this;
        }

        $path = preg_replace_callback('/\{const:([^}]+)\}/', static fn (array $match): string => $constant($match[1]) ?? '{' . $match[1] . '}', $this->path);

        return $this->withPath($path ?? $this->path);
    }

    public function withPath(string $path): self
    {
        return new self(
            $this->methods,
            self::normalizePath($path),
            $this->controller,
            $this->action,
            $this->file,
            $this->line,
            $this->serviceId,
            $this->references,
            $this->byFramework,
            $this->loader,
        );
    }

    public static function normalizePath(string $path): string
    {
        $path = '/' . trim(preg_replace('#/+#', '/', $path) ?? $path, '/');

        return $path;
    }
}
