<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

/**
 * An HTTP call that may reach a route of the project: `$client->request('POST', 'http://billing/orders')`,
 * `Http::get("$base/orders/$id")`. The URL is kept as a path pattern, `*` standing for what is computed at runtime.
 */
final readonly class PendingRequest
{
    /**
     * @param ?string   $httpMethod  uppercase HTTP method, null when computed at runtime
     * @param string    $path        path pattern without scheme nor host: `/orders/*`
     * @param bool      $literal     the whole path is written in the code
     * @param bool      $absolute    the URL starts with a scheme: an HTTP call whatever the receiver
     */
    public function __construct(
        public string $source,
        public ?TypeExpr $receiver,
        public ?string $staticClass,
        public ?string $httpMethod,
        public string $path,
        public bool $literal,
        public bool $absolute,
    ) {
    }
}
