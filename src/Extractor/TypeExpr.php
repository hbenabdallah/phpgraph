<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

/**
 * The type of an expression as the extractor sees it, before the other files are known:
 * a class name, or the declared type of a method's return value or of a property, resolved by the builder.
 */
final readonly class TypeExpr
{
    /**
     * Return type marker for `static` and `$this`: the type of the receiver.
     */
    public const STATIC = 'static';

    /**
     * @param ?string      $generic   the type with its arguments, a GenericType string, when it has some: `Collection<?,Item>`
     * @param list<string> $functions the function called, `response()`: the names it may have, its namespace's first
     * @param list<string> $arguments what the call passes, by position: `null`, `class:Foo` (Foo::class) or `?`
     * @param array{int|string, int}|null $closure the closure argument of the method call, by position or name, and
     *                                             the parameter of the closure
     * @param array<int, self> $argumentTypes the types of the arguments the call passes, by position; for a closure,
     *                                        what it returns: they bind the templates of the method (`@param T $value`)
     * @param list<self> $members the types a variable may hold where branches meet, sorted by key; an unknown
     *                          one last when a branch leaves it of unknown type
     * @param array{self, list<string>, bool}|null $test the type an `instanceof` test narrows, the classes tested,
     *                                                   whether the test failed (the classes are then excluded)
     */
    private function __construct(
        public ?string $className,
        public ?self $receiver = null,
        public ?string $member = null,
        public bool $isProperty = false,
        public bool $isElement = false,
        public ?string $generic = null,
        public array $functions = [],
        public array $arguments = [],
        public ?array $closure = null,
        public array $argumentTypes = [],
        public array $members = [],
        public ?array $test = null,
    ) {
    }

    /**
     * A type nothing is known of: a variable assigned an expression of unknown type, an untyped parameter. Where
     * branches meet, it makes the union partial.
     */
    public static function unknown(): self
    {
        return new self(null);
    }

    public function isUnknown(): bool
    {
        return $this->className === null && $this->receiver === null && $this->functions === [] && $this->members === [] && $this->test === null && $this->generic === null;
    }

    /**
     * What a variable may hold where branches meet: `if (...) { $shape = new Circle(); } else { $shape = new Square(); }`.
     * Nested unions are flattened, an unknown member makes it partial; the members are sorted, so that the order of the
     * branches does not matter. A test and its negation of the same type give the type back (`if ($x instanceof A)`
     * without an assignment leaves `$x` as it was).
     *
     * @param list<self> $types
     */
    public static function union(array $types): self
    {
        $members = [];
        $partial = false;
        foreach ($types as $type) {
            foreach ($type->members !== [] ? $type->members : [$type] as $member) {
                if ($member->isUnknown()) {
                    $partial = true;
                } else {
                    $members[$member->key()] = $member;
                }
            }
        }
        // A type narrowed, beside the type itself or its negation: the type.
        do {
            $reduced = false;
            foreach ($members as $key => $member) {
                if ($member->test === null) {
                    continue;
                }
                [$subject, $classes, $excluding] = $member->test;
                $negation = self::narrowed($subject, $classes, !$excluding)->key();
                if (isset($members[$subject->key()]) || isset($members[$negation])) {
                    unset($members[$key], $members[$negation]);
                    $members[$subject->key()] = $subject;
                    $reduced = true;
                    break;
                }
            }
        } while ($reduced);
        ksort($members, \SORT_STRING);
        $members = array_values($members);

        return match (true) {
            $members === [] => self::unknown(),
            \count($members) === 1 && !$partial => $members[0],
            default => new self(null, null, null, false, false, null, [], [], null, [], $partial ? [...$members, self::unknown()] : $members),
        };
    }

    /**
     * The type an `instanceof` test leaves in its branch: `$subject` narrowed to the classes tested
     * (`if ($shape instanceof Circle)`), or without them when the test failed (`else`, after `if (!...) { return; }`).
     * Resolved by the builder, which knows the hierarchy.
     *
     * @param list<string> $classes
     */
    public static function narrowed(self $subject, array $classes, bool $excluding = false): self
    {
        return new self(null, null, null, false, false, null, [], [], null, [], [], [$subject, $classes, $excluding]);
    }

    /**
     * A parameter of a closure passed to a method, typed by what the method documents it receives:
     * `$query->when($search, fn ($query) => ...)` with `@param (callable($this, TWhenParameter): mixed) $callback`.
     *
     * @param int|string $argument  position or name of the argument
     * @param int        $parameter position of the parameter in the closure
     */
    public static function closureParameter(self $receiver, string $method, int|string $argument, int $parameter): self
    {
        return new self(null, $receiver, $method, false, false, null, [], [], [$argument, $parameter]);
    }

    /**
     * What a function returns: `now()`, `response()` (its `@return` may depend on the arguments).
     *
     * @param list<string>     $names         the names the function may have: in the namespace of the call, then global
     * @param list<string>     $arguments
     * @param array<int, self> $argumentTypes
     */
    public static function returnOfFunction(array $names, array $arguments, array $argumentTypes = []): self
    {
        return new self(null, null, null, false, false, null, $names, $arguments, null, $argumentTypes);
    }

    public static function named(string $className): self
    {
        return new self($className);
    }

    /**
     * A type with its arguments, read in a docblock: `@var Collection<int, Item> $items`, `@param Item[] $items`.
     */
    public static function generic(string $type): self
    {
        $base = GenericType::base($type);

        return new self(GenericType::isClass($base) ? $base : null, null, null, false, false, $type);
    }

    /**
     * An element of a collection: `foreach ($order->getItems() as $item)` with `@return Collection<int, Item>`.
     */
    public static function elementOf(self $collection): self
    {
        return new self(null, $collection, null, false, true);
    }

    /**
     * @param list<string>     $arguments     what the call passes, when it binds the templates of the method
     * @param array<int, self> $argumentTypes
     */
    public static function returnOf(self $receiver, string $method, array $arguments = [], array $argumentTypes = []): self
    {
        return new self(null, $receiver, $method, false, false, null, [], $arguments, null, $argumentTypes);
    }

    /**
     * An element of the collection a method returns: `foreach ($notification->all() as $violation)` with
     * `@return list<Violation>`.
     */
    public static function elementsOf(self $receiver, string $method): self
    {
        return new self(null, $receiver, $method, false, true);
    }

    public static function propertyOf(self $receiver, string $property): self
    {
        return new self(null, $receiver, $property, true);
    }

    /**
     * The same expression with every class name passed through $map: names written in a file, made project ids.
     *
     * @param \Closure(string): string $map
     */
    public function map(\Closure $map): self
    {
        $arguments = array_map(
            static fn (string $argument): string => str_starts_with($argument, 'class:') ? 'class:' . $map(substr($argument, 6)) : $argument,
            $this->arguments,
        );
        $argumentTypes = array_map(static fn (self $type): self => $type->map($map), $this->argumentTypes);
        if ($this->members !== []) {
            return new self(null, null, null, false, false, null, [], [], null, [], array_map(static fn (self $member): self => $member->map($map), $this->members));
        }
        if ($this->test !== null) {
            return self::narrowed($this->test[0]->map($map), array_map($map, $this->test[1]), $this->test[2]);
        }
        if ($this->functions !== []) {
            return new self(null, null, null, false, false, null, array_map($map, $this->functions), $arguments, null, $argumentTypes);
        }

        return $this->receiver === null
            ? new self($this->className === null ? null : $map($this->className), null, null, false, false, $this->generic === null ? null : GenericType::mapNames($this->generic, $map))
            : new self(null, $this->receiver->map($map), $this->member, $this->isProperty, $this->isElement, null, [], $arguments, $this->closure, $argumentTypes);
    }

    public function key(): string
    {
        if ($this->members !== []) {
            return '{' . implode('|', array_map(static fn (self $member): string => $member->isUnknown() ? '?' : $member->key(), $this->members)) . '}';
        }
        if ($this->test !== null) {
            return '(' . $this->test[0]->key() . ($this->test[2] ? ')!is(' : ')is(') . strtolower(implode('|', $this->test[1])) . ')';
        }
        if ($this->functions !== []) {
            return strtolower(implode('|', $this->functions)) . '(' . $this->argumentsKey() . ')';
        }
        if ($this->receiver === null) {
            return strtolower($this->generic ?? (string) $this->className);
        }
        if ($this->member === null) {
            return $this->receiver->key() . '[]';
        }
        if ($this->closure !== null) {
            return $this->receiver->key() . '->' . $this->member . '(#' . $this->closure[0] . ':' . $this->closure[1] . ')';
        }

        return $this->receiver->key() . ($this->isProperty ? '->$' : '->') . $this->member . ($this->isProperty ? '' : '(' . $this->argumentsKey() . ')') . ($this->isElement ? '[]' : '');
    }

    private function argumentsKey(): string
    {
        $arguments = $this->arguments;
        foreach ($this->argumentTypes as $position => $type) {
            $arguments[$position] = ($arguments[$position] ?? '?') . ':' . $type->key();
        }
        ksort($arguments);

        return implode(',', $arguments);
    }
}
