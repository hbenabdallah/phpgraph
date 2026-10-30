<?php

declare(strict_types=1);

namespace PhpGraph\Vendor;

use PhpGraph\Extractor\ConditionalReturn;
use PhpGraph\Extractor\FileExtractor;
use PhpGraph\Extractor\PhpFileExtractor;
use PhpGraph\Graph\NodeKind;
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

        // PHP's own classes: never in vendor/, read from the signatures generated once.
        return $this->signatures[$key] ??= InternalClasses::signature($class);
    }

    /** @var array<string, string>|null lowercase function name => the autoloaded file declaring it */
    private ?array $functionFiles = null;

    /** @var array<string, array{?string, ?string, ?ConditionalReturn, array<string, array{string, int}>}> lowercase function name => return type, GenericType, conditional, template binders */
    private array $functions = [];

    /** @var array<string, list<array{string, int, string}>> lowercase function name => what a call tells of its arguments */
    private array $functionAssertions = [];

    /**
     * What a function of the packages returns: its class, its GenericType, its conditional return type, how a
     * call binds its templates. The autoloaded files are scanned for the names they declare; the file declaring it is
     * then read, never run.
     *
     * @return array{?string, ?string, ?ConditionalReturn, array<string, array{string, int}>}|null
     */
    public function function(string $name): ?array
    {
        $key = strtolower(ltrim($name, '\\'));
        if (\array_key_exists($key, $this->functions)) {
            return $this->functions[$key];
        }
        if ($this->functionFiles === null) {
            $this->functionFiles = [];
            foreach ($this->locator->files() as $file) {
                $code = @file_get_contents($file);
                if (!\is_string($code)) {
                    continue;
                }
                $namespace = preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $code, $match) === 1 ? $match[1] . '\\' : '';
                preg_match_all('/^\s*function\s+&?(\w+)\s*\(/m', $code, $matches);
                foreach ($matches[1] as $function) {
                    $this->functionFiles[strtolower($namespace . $function)] ??= $file;
                }
            }
        }
        $file = $this->functionFiles[$key] ?? null;
        if ($file === null) {
            return null;
        }

        $code = (string) @file_get_contents($file);
        try {
            $extraction = $this->extractor->extract($code, $file);
        } catch (Error) {
            return $this->functions[$key] = [null, null, null, []];
        }
        ++$this->filesRead;
        foreach ($extraction->nodes as $node) {
            if ($node->kind === NodeKind::Func) {
                $this->functions[strtolower($node->id)] = [
                    $extraction->returnTypes[$node->id] ?? null,
                    $extraction->genericReturns[$node->id] ?? null,
                    $extraction->conditionalReturns[$node->id] ?? null,
                    $extraction->templateBinders[$node->id] ?? [],
                ];
                $this->functionAssertions[strtolower($node->id)] = $extraction->assertions[$node->id] ?? [];
            }
        }

        return $this->functions[$key] ??= [null, null, null, []];
    }

    /**
     * What a call to a function of the packages tells of its arguments (`@phpstan-assert-if-true WP_Error $thing`).
     *
     * @return list<array{string, int, string}>
     */
    public function functionAssertions(string $name): array
    {
        $this->function($name);

        return $this->functionAssertions[strtolower(ltrim($name, '\\'))] ?? [];
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
            // Magic methods documented with `@method`, a facade's: after those it declares.
            $class['methods'] += $extraction->documentedMethods[$name] ?? [];
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
                $extraction->mixins[$name] ?? [],
                array_intersect_key($extraction->callableParameters, array_flip($class['methods'])),
                array_intersect_key($extraction->templateBinders, array_flip($class['methods'])),
                array_intersect_key($extraction->assertions, array_flip($class['methods'])),
            );
        }
    }
}
