<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

/**
 * A conditional `@return`, `($content is null ? ResponseFactory : Response)`, decided by the arguments of a call.
 */
final readonly class ConditionalReturn
{
    /**
     * @param int $parameter the position of the argument tested
     */
    public function __construct(
        public int $parameter,
        public ConditionalTest $test,
        public self|ConditionalClass|ConditionalArgument $then,
        public self|ConditionalClass|ConditionalArgument $else,
    ) {
    }

    /**
     * Whether a branch gives a class: a condition between `array` and `mixed` says nothing.
     */
    public function namesAClass(): bool
    {
        foreach ([$this->then, $this->else] as $branch) {
            $names = match (true) {
                $branch instanceof self => $branch->namesAClass(),
                $branch instanceof ConditionalArgument => true,
                default => $branch->class !== null,
            };
            if ($names) {
                return true;
            }
        }

        return false;
    }
}
