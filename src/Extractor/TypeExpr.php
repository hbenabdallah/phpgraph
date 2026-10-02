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

    private function __construct(
        public ?string $className,
        public ?self $receiver = null,
        public ?string $member = null,
        public bool $isProperty = false,
    ) {
    }

    public static function named(string $className): self
    {
        return new self($className);
    }

    public static function returnOf(self $receiver, string $method): self
    {
        return new self(null, $receiver, $method);
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
            ? new self($this->className === null ? null : $map($this->className))
            : new self(null, $this->receiver->map($map), $this->member, $this->isProperty);
    }

    public function key(): string
    {
        if ($this->receiver === null) {
            return strtolower((string) $this->className);
        }

        return $this->receiver->key() . ($this->isProperty ? '->$' : '->') . $this->member . ($this->isProperty ? '' : '()');
    }
}
