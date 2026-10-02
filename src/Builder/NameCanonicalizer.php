<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

/**
 * PHP class, function and method names are case-insensitive. Maps every spelling of a name to one id:
 * the declared one when the project declares it, otherwise the first spelling seen.
 *
 * In a multi-service repository, ids carry their service (`billing@App\Domain\Order`): a name written in a service
 * means that service's class, else the root code shared by all services, else something outside the project. Never
 * another service's class: services meet through contracts, not class names.
 */
final class NameCanonicalizer
{
    /** @var array<string, string> */
    private array $ids = [];

    public function declare(string $id): void
    {
        $this->ids[$this->key($id)] = $id;
    }

    public function canonical(string $id, string $service = ''): string
    {
        if (str_starts_with($id, 'file:')) {
            return $id;
        }

        if ($service !== '' && !str_contains($id, '@')) {
            foreach ($service === ServiceMap::ROOT ? [$service] : [$service, ServiceMap::ROOT] as $scope) {
                $declared = $this->ids[$this->key($scope . '@' . ltrim($id, '\\'))] ?? null;
                if ($declared !== null) {
                    return $declared;
                }
            }
        }

        return $this->ids[$this->key($id)] ??= $id;
    }

    /**
     * Every name and the id it resolved to, in the order they were met: two builds that resolve the same names the
     * same way have the same fingerprint.
     */
    public function fingerprint(): string
    {
        $hash = hash_init('xxh128');
        foreach ($this->ids as $key => $id) {
            hash_update($hash, $key . '=' . $id . "\n");
        }

        return hash_final($hash);
    }

    /**
     * The id a declaration gets in its service.
     */
    public static function qualify(string $id, string $service): string
    {
        return $service === '' || str_starts_with($id, 'file:') ? $id : $service . '@' . ltrim($id, '\\');
    }

    private function key(string $id): string
    {
        return strtolower(ltrim($id, '\\'));
    }
}
