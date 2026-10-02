<?php

declare(strict_types=1);

namespace PhpGraph\Project;

use PhpGraph\Builder\SourceFiles;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The optional phpgraph.yaml at the project root: the conventions phpgraph cannot detect, the same for every
 * framework.
 *
 *     services:            # the services of a multi-service repository, instead of detecting them
 *       - services/billing
 *       - services/shipping
 */
final readonly class ProjectConfig
{
    /**
     * @param list<string>|null $services service directories relative to the root, null to detect them
     */
    public function __construct(public ?array $services = null)
    {
    }

    public static function load(string $root): self
    {
        $path = $root . '/' . SourceFiles::CONFIG;
        if (!is_file($path)) {
            return new self();
        }

        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $exception) {
            throw new \RuntimeException(sprintf('%s is not valid YAML: %s', SourceFiles::CONFIG, $exception->getMessage()), 0, $exception);
        }
        if ($data === null) {
            return new self();
        }
        if (!\is_array($data)) {
            throw new \RuntimeException(sprintf('%s must be a map of settings.', SourceFiles::CONFIG));
        }

        $unknown = array_diff(array_keys($data), ['services']);
        if ($unknown !== []) {
            throw new \RuntimeException(sprintf('%s: unknown setting "%s". Known: services.', SourceFiles::CONFIG, implode('", "', $unknown)));
        }

        $services = $data['services'] ?? null;
        if ($services !== null && (!\is_array($services) || array_filter($services, static fn (mixed $service): bool => !\is_string($service)) !== [])) {
            throw new \RuntimeException(sprintf('%s: services must be a list of directories.', SourceFiles::CONFIG));
        }

        /** @var array<string>|null $services */
        return new self($services === null ? null : array_values($services));
    }
}
