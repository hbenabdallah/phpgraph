<?php

declare(strict_types=1);

namespace PhpGraph\Builder\Libraries;

use PhpGraph\Builder\Generics;
use PhpGraph\Builder\TypeResolver;
use PhpGraph\Extractor\TypeExpr;

/**
 * The knowledge of libraries their signatures do not carry, one provider per library: TypeResolver asks them, and
 * knows none of them.
 */
final class Libraries
{
    /** @var array<string, list<MethodReturnProvider>> lowercase method name => the providers that may type it */
    private array $methods = [];

    /**
     * @param list<MethodReturnProvider> $methods
     * @param list<PropertyProvider>     $properties
     */
    public function __construct(array $methods = [], private readonly array $properties = [])
    {
        foreach ($methods as $provider) {
            foreach ($provider->methods() as $name) {
                $this->methods[$name][] = $provider;
            }
        }
    }

    public static function defaults(Generics $generics): self
    {
        return new self([new PsrContainer()], [new Eloquent($generics->casts)]);
    }

    /**
     * @param string $method the method found, `Class::name`
     */
    public function methodReturn(string $class, string $method, TypeExpr $call, TypeResolver $types): ?string
    {
        foreach ($this->methods[strtolower(substr($method, (int) strrpos($method, '::') + 2))] ?? [] as $provider) {
            $type = $provider->returnType($class, $method, $call, $types);
            if ($type !== null) {
                return $type;
            }
        }

        return null;
    }

    public function propertyType(string $class, TypeExpr $read, TypeResolver $types): ?string
    {
        foreach ($this->properties as $provider) {
            $type = $provider->propertyType($class, $read, $types);
            if ($type !== null) {
                return $type;
            }
        }

        return null;
    }
}
