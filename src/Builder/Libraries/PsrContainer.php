<?php

declare(strict_types=1);

namespace PhpGraph\Builder\Libraries;

use PhpGraph\Builder\TypeResolver;
use PhpGraph\Extractor\TypeExpr;

/**
 * PSR-11: a container asked for a class gives an instance of it, `$container->get(Mailer::class)`, and so does a
 * container building one, `$app->make(Kernel::class)` (Laravel, PHP-DI: documented `mixed` before Laravel 10). A
 * service id that is no class constant (`get('mailer')`) says nothing.
 */
final class PsrContainer implements MethodReturnProvider
{
    private const CONTAINER = 'Psr\\Container\\ContainerInterface';

    public function methods(): array
    {
        return ['get', 'make', 'makewith'];
    }

    public function returnType(string $class, string $method, TypeExpr $call, TypeResolver $types): ?string
    {
        $argument = $call->arguments[0] ?? '';

        return str_starts_with($argument, 'class:') && $types->isA($class, self::CONTAINER) ? substr($argument, 6) : null;
    }
}
