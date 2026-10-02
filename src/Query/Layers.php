<?php

declare(strict_types=1);

namespace PhpGraph\Query;

/**
 * Where a class sits, read from its namespace: its layer (the first layer-like segment, Domain in
 * `Sales\Domain\Model\Order`) and its bounded context. Names only: phpgraph reports, the agent judges.
 */
final class Layers
{
    /**
     * Namespace segments that name a layer explicitly, by role: the vocabulary of DDD and hexagonal architecture.
     */
    public const EXPLICIT = [
        'domain' => ['domain'],
        'application' => ['application', 'usecase', 'usecases'],
        'infrastructure' => ['infrastructure', 'infra', 'adapter', 'adapters'],
        'interface' => ['ui', 'presentation', 'userinterface'],
        'port' => ['port', 'ports'],
    ];

    /**
     * Segments that hint at a layer without naming it: also used as module names (BookStack\Entities\Controllers).
     */
    public const GENERIC = [
        'domain' => ['model', 'entity', 'entities', 'valueobject', 'valueobjects'],
        'infrastructure' => ['persistence'],
        'interface' => ['controller', 'controllers', 'http', 'api', 'cli'],
    ];

    /**
     * Default dependency rules of a layered (DDD, hexagonal) architecture: role => roles it must not depend on.
     */
    public const FORBIDDEN = [
        'domain' => ['application', 'infrastructure', 'interface'],
        'application' => ['infrastructure', 'interface'],
        'port' => ['infrastructure', 'interface'],
    ];

    /**
     * Segments too vague for a rule: Api is a user interface in one project and a published contract in another.
     */
    private const NOT_IN_RULES = ['api'];

    /**
     * The layer of a namespace: its first explicit layer segment (`Domain` in `Sales\Domain\Model`), or else its last
     * generic one, the most specific (`Controllers` in `BookStack\Entities\Controllers`).
     *
     * @param list<string> $segments
     *
     * @return array{segment: string, role: string, position: int, explicit: bool}|null
     */
    public static function of(array $segments): ?array
    {
        $generic = null;
        foreach ($segments as $position => $segment) {
            $lower = strtolower($segment);
            // API and Api are one layer name: an all-capitals segment is counted as Api.
            $name = strtoupper($segment) === $segment ? ucfirst($lower) : $segment;
            foreach (self::EXPLICIT as $role => $names) {
                if (\in_array($lower, $names, true)) {
                    return ['segment' => $name, 'role' => $role, 'position' => $position, 'explicit' => true];
                }
            }
            foreach (self::GENERIC as $role => $names) {
                if (\in_array($lower, $names, true)) {
                    $generic = ['segment' => $name, 'role' => $role, 'position' => $position, 'explicit' => false];
                }
            }
        }

        return $generic;
    }

    /**
     * The layer role a dependency rule applies to, or null.
     */
    public static function ruleRole(string $class): ?string
    {
        $layer = self::of(self::namespaceOf($class));

        return $layer === null || \in_array(strtolower($layer['segment']), self::NOT_IN_RULES, true) ? null : $layer['role'];
    }

    public static function isForbidden(string $fromRole, string $toRole): bool
    {
        return \in_array($toRole, self::FORBIDDEN[$fromRole] ?? [], true);
    }

    /**
     * The bounded context of a class, read from an explicit layer segment only, given how the project writes its
     * namespaces: the part before the layer
     * (`CodelyTv\Mooc\Courses` in `CodelyTv\Mooc\Courses\Domain\Course`), or the segment after it when the project
     * puts the layer first (`Product` in `PrestaShop\PrestaShop\Core\Domain\Product\Command\AddProduct`, and also in
     * `PrestaShop\PrestaShop\Adapter\Product\ProductRepository`).
     */
    public static function context(string $class, bool $layerFirst): ?string
    {
        $segments = self::namespaceOf($class);
        $layer = self::of($segments);
        if ($layer === null || !$layer['explicit']) {
            return null;
        }

        // Core\Domain\Product and Adapter\Product are the same context seen from two layers.
        if ($layerFirst) {
            return $segments[$layer['position'] + 1] ?? null;
        }

        return $layer['position'] === 0 ? null : implode('\\', \array_slice($segments, 0, $layer['position']));
    }

    /**
     * Whether the project puts the layer before the context: one namespace leads to its domain layer, and several
     * sub-namespaces come after it (Core\Domain\Product, Core\Domain\Cart...).
     *
     * @param iterable<string> $classes
     */
    public static function isLayerFirst(iterable $classes): bool
    {
        $before = [];
        $after = [];
        foreach ($classes as $class) {
            $segments = self::namespaceOf($class);
            $layer = self::of($segments);
            if ($layer !== null && $layer['explicit'] && $layer['role'] === 'domain') {
                $before[implode('\\', \array_slice($segments, 0, $layer['position']))] = true;
                if (isset($segments[$layer['position'] + 1])) {
                    $after[$segments[$layer['position'] + 1]] = true;
                }
            }
        }

        return \count($before) === 1 && \count($after) >= 2;
    }

    /**
     * @return list<string>
     */
    public static function namespaceOf(string $class): array
    {
        $segments = explode('\\', $class);
        array_pop($segments);

        return $segments;
    }
}
