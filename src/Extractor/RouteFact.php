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
     */
    public function __construct(
        public array $methods,
        public string $path,
        public ?string $controller,
        public ?string $action,
        public string $file,
        public ?int $line,
        public ?string $serviceId = null,
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

    public static function normalizePath(string $path): string
    {
        $path = '/' . trim(preg_replace('#/+#', '/', $path) ?? $path, '/');

        return $path;
    }
}
