<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

use PhpGraph\Extractor\FileExtraction;
use PhpGraph\Graph\Edge;
use PhpGraph\Graph\Relation;
use PhpGraph\Vendor\VendorSignatures;

/**
 * What a build leaves for the next one in the same process (the MCP server): the extraction of every file, and the
 * resolution of its calls. The next build reads again only the files whose size or modification time changed, and
 * resolves again only their calls when no declaration changed anywhere: resolving a call depends on declarations
 * only (classes, methods, inheritance, return and property types, dependencies), not on method bodies.
 */
final class BuildState
{
    /**
     * @param string                                                                               $options     excludes, vendor reading, services
     * @param array<string, array{mtime: int, size: int, extraction: FileExtraction, signature: string}> $files
     * @param string                                                                               $environment composer files and installed dependencies
     * @param string                                                                               $names       ids every name resolved to
     * @param list<Edge>                                                                           $overrides
     * @param array<string, list<array{?Edge, string, string}>>                                     $calls       file => [edge added, outcome, method] per call site
     * @param array<string, array{int, int, array<mixed>}>                                          $configuration parsed configuration files: [mtime, size, data]
     */
    public function __construct(
        public readonly string $options,
        public readonly array $files,
        public readonly string $environment,
        public readonly string $names,
        public readonly array $overrides,
        public readonly array $calls,
        public readonly ?VendorSignatures $vendor,
        public readonly array $configuration,
    ) {
    }

    /**
     * What a file declares, as far as resolving calls is concerned: its classes and methods, their parents, return
     * and property types. Editing a method body leaves it unchanged.
     */
    public static function signature(FileExtraction $extraction): string
    {
        $hash = hash_init('xxh128');
        foreach ($extraction->nodes as $node) {
            hash_update($hash, 'n' . $node->kind->value . ':' . $node->id . "\n");
        }
        foreach ($extraction->edges as $edge) {
            if (\in_array($edge->relation, [Relation::Defines, Relation::Extends, Relation::Implements, Relation::UsesTrait, Relation::HasMethod], true)) {
                hash_update($hash, 'e' . $edge->key() . "\n");
            }
        }
        foreach ($extraction->returnTypes as $method => $type) {
            hash_update($hash, 'r' . $method . '=' . $type . "\n");
        }
        foreach ($extraction->propertyTypes as $property => $type) {
            hash_update($hash, 'p' . $property . '=' . $type . "\n");
        }
        foreach ($extraction->invokedParameters as $method => $parameters) {
            hash_update($hash, 'i' . $method . '=' . json_encode($parameters) . "\n");
        }

        return hash_final($hash);
    }
}
