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
     * @param ?string $generic the type with its arguments, a GenericType string, when it has some: `Collection<?,Item>`
     */
    private function __construct(
        public ?string $className,
        public ?self $receiver = null,
        public ?string $member = null,
        public bool $isProperty = false,
        public bool $isElement = false,
        public ?string $generic = null,
    ) {
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

    public static function returnOf(self $receiver, string $method): self
    {
        return new self(null, $receiver, $method);
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
        return $this->receiver === null
            ? new self($this->className === null ? null : $map($this->className), null, null, false, false, $this->generic === null ? null : GenericType::mapNames($this->generic, $map))
            : new self(null, $this->receiver->map($map), $this->member, $this->isProperty, $this->isElement);
    }

    public function key(): string
    {
        if ($this->receiver === null) {
            return strtolower($this->generic ?? (string) $this->className);
        }
        if ($this->member === null) {
            return $this->receiver->key() . '[]';
        }

        return $this->receiver->key() . ($this->isProperty ? '->$' : '->') . $this->member . ($this->isProperty ? '' : '()') . ($this->isElement ? '[]' : '');
    }
}
