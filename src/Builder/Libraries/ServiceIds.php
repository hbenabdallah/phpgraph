<?php

declare(strict_types=1);

namespace PhpGraph\Builder\Libraries;

use PhpGraph\Builder\ContainerServices;
use PhpGraph\Builder\TypeResolver;
use PhpGraph\Extractor\TypeExpr;

/**
 * A Symfony container asked for a service by its id, `$container->get('app.mailer')`, gives an instance of the class
 * the project's configuration defines for it (aliases and parameters followed). An id the configuration does not
 * define (framework, bundles, ids built at run time) says nothing.
 */
final readonly class ServiceIds implements MethodReturnProvider
{
    private const CONTAINER = 'Psr\\Container\\ContainerInterface';

    public function __construct(private ContainerServices $container)
    {
    }

    public function methods(): array
    {
        return ['get'];
    }

    public function returnType(string $class, string $method, TypeExpr $call, TypeResolver $types): ?string
    {
        $argument = $call->arguments[0] ?? '';
        if (!str_starts_with($argument, TypeExpr::SERVICE_ID) || !$types->isA($class, self::CONTAINER)) {
            return null;
        }

        return $this->container->classOf(substr($argument, \strlen(TypeExpr::SERVICE_ID)));
    }
}
