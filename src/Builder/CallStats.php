<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

/**
 * What happened to each method call site found in the code.
 */
final readonly class CallStats
{
    /**
     * @param int $chainOutsideProject the unknown receivers whose type comes from a chain that reaches a method
     *                                 or property the project does not declare (vendor code, magic methods)
     */
    public function __construct(
        public int $inferred = 0,
        public int $ambiguous = 0,
        public int $outsideProject = 0,
        public int $unknownReceiver = 0,
        public int $chainOutsideProject = 0,
    ) {
    }

    /**
     * @param array<mixed> $data as written by toArray()
     */
    public static function fromArray(array $data): self
    {
        $int = static fn (string $key): int => \is_int($data[$key] ?? null) ? $data[$key] : 0;

        return new self($int('inferred'), $int('ambiguous'), $int('outsideProject'), $int('unknownReceiver'), $int('chainOutsideProject'));
    }

    public function minus(self $other): self
    {
        return new self(
            $this->inferred - $other->inferred,
            $this->ambiguous - $other->ambiguous,
            $this->outsideProject - $other->outsideProject,
            $this->unknownReceiver - $other->unknownReceiver,
            $this->chainOutsideProject - $other->chainOutsideProject,
        );
    }

    public function total(): int
    {
        return $this->inferred + $this->ambiguous + $this->outsideProject + $this->unknownReceiver;
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total(),
            'inferred' => $this->inferred,
            'ambiguous' => $this->ambiguous,
            'outsideProject' => $this->outsideProject,
            'unknownReceiver' => $this->unknownReceiver,
            'chainOutsideProject' => $this->chainOutsideProject,
        ];
    }
}
