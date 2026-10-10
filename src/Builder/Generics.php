<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

/**
 * What the project's docblocks declare beyond the code, as project ids: the generic types TypeResolver binds, the
 * magic methods (`@method`) and the classes calls are forwarded to (`@mixin`).
 */
final readonly class Generics
{
    /**
     * @param array<string, list<string>>                $templates       class => its template parameters, in order
     * @param array<string, array<string, list<string>>> $parentArguments class => parent => the arguments it gives
     * @param array<string, string>                      $returns         method id => GenericType returned
     * @param array<string, string>                      $properties      "Class::property" => GenericType
     * @param array<string, array<string, string>>       $documentedMethods class => lowercase name => id of a `@method`
     * @param array<string, list<string>>                $mixins          class => the classes of its `@mixin` tags
     * @param array<string, string>                      $conditionals    callable id => conditional return type (JSON)
     */
    public function __construct(
        public array $templates = [],
        public array $parentArguments = [],
        public array $returns = [],
        public array $properties = [],
        public array $documentedMethods = [],
        public array $mixins = [],
        public array $conditionals = [],
    ) {
    }
}
