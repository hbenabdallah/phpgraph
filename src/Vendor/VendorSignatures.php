<?php

declare(strict_types=1);

namespace PhpGraph\Vendor;

use PhpGraph\Extractor\FileExtractor;
use PhpGraph\Extractor\PhpFileExtractor;
use PhpGraph\Graph\Relation;
use PhpParser\Error;

/**
 * Signatures of dependency classes, read on demand: a vendor file is parsed only when a call chain reaches
 * one of its classes, and never becomes part of the graph.
 */
final class VendorSignatures
{
    /** @var array<string, ?ClassSignature> lowercase class => signature, null when not found */
    private array $signatures = [];

    private int $filesRead = 0;

    public function __construct(
        private readonly ComposerClassLocator $locator,
        private readonly FileExtractor $extractor = new PhpFileExtractor(),
    ) {
    }

    public function signature(string $class): ?ClassSignature
    {
        $key = strtolower(ltrim($class, '\\'));
        if (\array_key_exists($key, $this->signatures)) {
            return $this->signatures[$key];
        }

        $this->signatures[$key] = null;
        $file = $this->locator->locate($class);
        if ($file !== null) {
            $this->read($file);
        }

        return $this->signatures[$key];
    }

    public function filesRead(): int
    {
        return $this->filesRead;
    }

    private function read(string $file): void
    {
        $code = @file_get_contents($file);
        if (!\is_string($code)) {
            return;
        }

        try {
            $extraction = $this->extractor->extract($code, $file);
        } catch (Error) {
            return;
        }
        ++$this->filesRead;

        $classes = [];
        foreach ($extraction->nodes as $node) {
            if ($node->kind->isClassLike()) {
                $classes[$node->id] = ['parents' => [], 'methods' => []];
            }
        }
        foreach ($extraction->edges as $edge) {
            if (!isset($classes[$edge->source])) {
                continue;
            }
            if ($edge->relation === Relation::HasMethod) {
                $classes[$edge->source]['methods'][strtolower(substr($edge->target, strrpos($edge->target, '::') + 2))] = $edge->target;
            } elseif (\in_array($edge->relation, [Relation::Extends, Relation::Implements, Relation::UsesTrait], true)) {
                $classes[$edge->source]['parents'][] = $edge->target;
            }
        }

        foreach ($classes as $name => $class) {
            $properties = $genericProperties = [];
            foreach ($extraction->propertyTypes as $property => $type) {
                if (str_starts_with($property, $name . '::')) {
                    $properties[substr($property, \strlen($name) + 2)] = $type;
                }
            }
            foreach ($extraction->genericProperties as $property => $type) {
                if (str_starts_with($property, $name . '::')) {
                    $genericProperties[substr($property, \strlen($name) + 2)] = $type;
                }
            }
            $returnTypes = array_intersect_key($extraction->returnTypes, array_flip($class['methods']));

            $this->signatures[strtolower($name)] = new ClassSignature(
                $name,
                $class['parents'],
                $class['methods'],
                $returnTypes,
                $properties,
                $extraction->templates[$name] ?? [],
                $extraction->parentArguments[$name] ?? [],
                array_intersect_key($extraction->genericReturns, array_flip($class['methods'])),
                $genericProperties,
            );
        }
    }
}
