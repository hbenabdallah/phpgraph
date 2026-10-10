<?php

declare(strict_types=1);

namespace PhpGraph\Vendor;

/**
 * What the type resolver needs from a dependency class: its parents and declared types, no bodies.
 */
final readonly class ClassSignature
{
    /**
     * @param list<string>          $parents       extended classes, implemented interfaces and used traits
     * @param array<string, string> $methods       lowercase method name => method id
     * @param array<string, string> $returnTypes   method id => class, or TypeExpr::STATIC
     * @param array<string, string> $propertyTypes property name => class
     * @param list<string>          $templates       template parameters, in order
     * @param array<string, list<string>> $parentArguments parent => the arguments it gives the parent's templates
     * @param array<string, string> $genericReturns  method id => GenericType returned
     * @param array<string, string> $genericProperties property name => GenericType
     */
    public function __construct(
        public string $name,
        public array $parents,
        public array $methods,
        public array $returnTypes,
        public array $propertyTypes,
        public array $templates = [],
        public array $parentArguments = [],
        public array $genericReturns = [],
        public array $genericProperties = [],
    ) {
    }
}
