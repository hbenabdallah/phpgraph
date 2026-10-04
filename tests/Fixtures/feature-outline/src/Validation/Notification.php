<?php

namespace App\Validation;

/**
 * Collects the violations of one validation run. Read back by the responses.
 */
final class Notification
{
    /** @var list<Violation> */
    private array $violations = [];

    public function add(string $code, ViolationType $type = ViolationType::Context): void
    {
        $this->violations[] = new Violation($code, $type);
    }

    /**
     * @return list<Violation>
     */
    public function all(): array
    {
        return $this->violations;
    }

    public function hasErrors(): bool
    {
        return $this->violations !== [];
    }
}
