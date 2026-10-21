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
     * @param array<string, array<int|string, list<string>>> $callables   method id => the parameters of the closures it
     *                                                                     takes, by position and name, GenericType strings
     * @param array<string, array<string, array{string, int}>> $binders callable id => how a call binds its templates
     * @param array<string, string>                      $casts           "Class::attribute" => the class an Eloquent cast gives it
     * @param array<string, list<array{string, int, string}>> $assertions callable id => what a call tells of its arguments
     */
    public function __construct(
        public array $templates = [],
        public array $parentArguments = [],
        public array $returns = [],
        public array $properties = [],
        public array $documentedMethods = [],
        public array $mixins = [],
        public array $conditionals = [],
        public array $callables = [],
        public array $binders = [],
        public array $casts = [],
        public array $assertions = [],
    ) {
    }
}
