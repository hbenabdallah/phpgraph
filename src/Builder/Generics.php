<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

/**
 * The generic types the project declares, as project ids: what TypeResolver needs to bind template parameters.
 */
final readonly class Generics
{
    /**
     * @param array<string, list<string>>                $templates       class => its template parameters, in order
     * @param array<string, array<string, list<string>>> $parentArguments class => parent => the arguments it gives
     * @param array<string, string>                      $returns         method id => GenericType returned
     * @param array<string, string>                      $properties      "Class::property" => GenericType
     */
    public function __construct(
        public array $templates = [],
        public array $parentArguments = [],
        public array $returns = [],
        public array $properties = [],
    ) {
    }
}
