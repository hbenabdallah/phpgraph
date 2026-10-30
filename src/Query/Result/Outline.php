<?php

declare(strict_types=1);

namespace PhpGraph\Query\Result;

use PhpGraph\Extractor\ClassFacts;
use PhpGraph\Extractor\Hint;

/**
 * The structural outline of a feature: its classes and what they declare, the families around them, how the
 * application runs into it, its wiring, its users and its tests.
 */
final readonly class Outline
{
    /**
     * @param list<string>                                                                                     $seeds    classes the question names
     * @param list<string>                                                                                     $core     classes of the feature, in the order they joined
     * @param array<string, ClassFacts>                                                                        $facts    class => what its source declares, when readable
     * @param list<array{head: string, members: int, directories: array<string, int>, receivers: list<string>}> $families
     * @param list<array{route: string, chain: list<string>}>                                                  $routes   from the route's handler down into the core
     * @param array<string, list<string>>                                                                      $entries  method of the core => the methods outside calling it
     * @param array<string, array<string, list<string>>>                                                       $fromFamilies method of the core => family head => its members' methods calling it
     * @param array<string, array<string, list<string>>>                                                       $closures method of the core => the method writing a closure it runs => what the closure calls
     * @param array<string, list<string>>                                                                      $inside   method of the core => the methods of other core classes it calls
     * @param list<array{string, Hint}>                                                                        $hints    class, hint
     * @param list<array{file: string, line: int, call: string, services: list<array{id: string, class: string, consumers: list<string>}>}> $wiring
     * @param array<string, int>                                                                               $consumers application class outside the core => its links to it
     * @param array<string, int>                                                                               $tests    module => test files touching the core
     * @param list<string>                                                                                     $topTests test files touching the most core classes
     * @param list<string>                                                                                     $uncovered classes no test touches
     * @param array<string, bool>                                                                              $unused   class nothing in the application uses => used by tests
     * @param ?string                                                                                          $method   the method the topic names: the core is what it runs (MethodCone)
     * @param array<string, int>                                                                               $depths   with a method, the methods it runs => calls away from it
     * @param array<string, int>                                                                               $hubs     with a method, the hubs it reaches, not followed => application classes calling them
     * @param ?array{class: string, methods: int, entries: array<string, array{routes: int, callers: int}>} $large the class named, when it declares many methods: its entry points, to outline one by one
     * @param array<string, list<string>>                                                                      $guesses  with a method, the calls guessed from a unique method name (AMBIGUOUS): caller => callees
     * @param list<string>                                                                                     $guessed  with a method, the methods it reaches only through such guesses
     * @param list<string>                                                                                     $left     the classes that qualified once the core was full (FeatureCluster::MAX), best first
     */
    public function __construct(
        public string $topic,
        public array $seeds,
        public array $core,
        public array $facts,
        public bool $sourcesRead,
        public array $families,
        public array $routes,
        public array $entries,
        public array $fromFamilies,
        public array $closures,
        public array $inside,
        public array $hints,
        public array $wiring,
        public array $consumers,
        public int $consumerFiles,
        public array $tests,
        public array $topTests,
        public array $uncovered,
        public array $unused,
        public ?string $method = null,
        public array $depths = [],
        public array $hubs = [],
        public ?array $large = null,
        public array $guesses = [],
        public array $guessed = [],
        public array $left = [],
    ) {
    }
}
