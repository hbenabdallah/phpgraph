<?php

declare(strict_types=1);

namespace PhpGraph\Project\Rules;

use PhpGraph\Values;

/**
 * The project's own rules, the `rules` key of `phpgraph.yaml`, checked by `phpgraph check` beside the default layer
 * rules:
 *
 *     rules:
 *       dependencies:
 *         - name: doctrine-in-infrastructure
 *           dependency: 'Doctrine\'
 *           allow-from: ['App\*\Infrastructure\']
 *           reason: Only infrastructure talks to the database.
 *       structure:
 *         - on: 'App\*\UI\Controller\*'
 *           target: class
 *           must-be-final: true
 *           must-be-named: '*Controller'
 *           only-public-methods: [__construct, __invoke]
 *       handlers:
 *         own-context: true
 *         shared: [Shared]
 */
final readonly class RuleSet
{
    private const DEPENDENCY_KEYS = ['name', 'dependency', 'allow-from', 'deny-from', 'reason'];

    private const STRUCTURE_KEYS = [
        'name', 'on', 'not-on', 'target', 'must-be-final', 'must-be-abstract', 'must-be-readonly', 'must-be-named',
        'must-implement', 'must-extend', 'must-use-trait', 'only-public-methods', 'reason',
    ];

    private const HANDLER_KEYS = ['name', 'own-context', 'shared', 'reason'];

    private const TARGETS = ['class', 'interface', 'trait', 'enum'];

    /**
     * @param list<DependencyRule> $dependencies
     * @param list<StructureRule>  $structure
     */
    public function __construct(
        public array $dependencies = [],
        public array $structure = [],
        public ?HandlerRule $handlers = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->dependencies === [] && $this->structure === [] && $this->handlers === null;
    }

    /**
     * @param string $file the configuration file, for the error messages
     */
    public static function fromArray(mixed $data, string $file): self
    {
        if ($data === null) {
            return new self();
        }
        $data = self::map($data, $file . ': rules', ['dependencies', 'structure', 'handlers']);

        $dependencies = [];
        foreach (self::list($data['dependencies'] ?? [], $file . ': rules.dependencies') as $index => $entry) {
            $where = $file . ': rules.dependencies[' . $index . ']';
            $entry = self::map($entry, $where, self::DEPENDENCY_KEYS);
            $dependencies[] = new DependencyRule(
                self::text($entry['name'] ?? null, $where . '.name') ?? 'dependencies[' . $index . ']',
                self::text($entry['dependency'] ?? null, $where . '.dependency') ?? throw new \RuntimeException($where . ': dependency is required, a namespace or a pattern.'),
                self::texts($entry['allow-from'] ?? [], $where . '.allow-from'),
                self::texts($entry['deny-from'] ?? [], $where . '.deny-from'),
                self::text($entry['reason'] ?? null, $where . '.reason'),
            );
        }

        $structure = [];
        foreach (self::list($data['structure'] ?? [], $file . ': rules.structure') as $index => $entry) {
            $where = $file . ': rules.structure[' . $index . ']';
            $entry = self::map($entry, $where, self::STRUCTURE_KEYS);
            $target = self::text($entry['target'] ?? null, $where . '.target');
            if ($target !== null && !\in_array($target, self::TARGETS, true)) {
                throw new \RuntimeException(sprintf('%s.target: "%s", expected one of %s.', $where, $target, implode(', ', self::TARGETS)));
            }
            $structure[] = new StructureRule(
                self::text($entry['name'] ?? null, $where . '.name') ?? 'structure[' . $index . ']',
                self::text($entry['on'] ?? null, $where . '.on') ?? throw new \RuntimeException($where . ': on is required, the pattern of the classes it applies to.'),
                self::text($entry['not-on'] ?? null, $where . '.not-on'),
                $target,
                self::flag($entry['must-be-final'] ?? null, $where . '.must-be-final'),
                self::flag($entry['must-be-abstract'] ?? null, $where . '.must-be-abstract'),
                self::flag($entry['must-be-readonly'] ?? null, $where . '.must-be-readonly'),
                self::text($entry['must-be-named'] ?? null, $where . '.must-be-named'),
                self::texts($entry['must-implement'] ?? [], $where . '.must-implement'),
                self::text($entry['must-extend'] ?? null, $where . '.must-extend'),
                self::texts($entry['must-use-trait'] ?? [], $where . '.must-use-trait'),
                \array_key_exists('only-public-methods', $entry) ? self::texts($entry['only-public-methods'], $where . '.only-public-methods') : null,
                self::text($entry['reason'] ?? null, $where . '.reason'),
            );
        }

        $handlers = null;
        if (isset($data['handlers'])) {
            $where = $file . ': rules.handlers';
            $entry = self::map($data['handlers'], $where, self::HANDLER_KEYS);
            if (self::flag($entry['own-context'] ?? null, $where . '.own-context') === true) {
                $handlers = new HandlerRule(
                    self::text($entry['name'] ?? null, $where . '.name') ?? 'handlers',
                    self::texts($entry['shared'] ?? [], $where . '.shared'),
                    self::text($entry['reason'] ?? null, $where . '.reason'),
                );
            }
        }

        return new self($dependencies, $structure, $handlers);
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, mixed>
     */
    private static function map(mixed $value, string $where, array $keys): array
    {
        if (!\is_array($value) || array_is_list($value) && $value !== []) {
            throw new \RuntimeException($where . ' must be a map.');
        }
        $unknown = array_diff(array_map('strval', array_keys($value)), $keys);
        if ($unknown !== []) {
            throw new \RuntimeException(sprintf('%s: unknown setting "%s". Known: %s.', $where, implode('", "', $unknown), implode(', ', $keys)));
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value, string $where): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw new \RuntimeException($where . ' must be a list.');
        }

        return $value;
    }

    private static function text(mixed $value, string $where): ?string
    {
        if ($value !== null && !\is_string($value)) {
            throw new \RuntimeException($where . ' must be a string.');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function texts(mixed $value, string $where): array
    {
        if (\is_string($value)) {
            return [$value];
        }
        if (!\is_array($value) || array_filter($value, static fn (mixed $item): bool => !\is_string($item)) !== []) {
            throw new \RuntimeException($where . ' must be a string or a list of strings.');
        }

        return array_values(array_map(Values::text(...), $value));
    }

    private static function flag(mixed $value, string $where): ?bool
    {
        if ($value !== null && !\is_bool($value)) {
            throw new \RuntimeException($where . ' must be true or false.');
        }

        return $value;
    }
}
