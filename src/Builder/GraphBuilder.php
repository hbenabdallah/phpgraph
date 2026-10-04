<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

use PhpGraph\Extractor\FileExtractor;
use PhpGraph\Extractor\HandlerFact;
use PhpGraph\Extractor\PendingCall;
use PhpGraph\Extractor\PendingDispatch;
use PhpGraph\Extractor\PendingRequest;
use PhpGraph\Extractor\PhpFileExtractor;
use PhpGraph\Extractor\RouteFact;
use PhpGraph\Extractor\TypeExpr;
use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Edge;
use PhpGraph\Graph\Graph;
use PhpGraph\Graph\Node;
use PhpGraph\Graph\NodeKind;
use PhpGraph\Graph\Relation;
use PhpGraph\Vendor\ComposerClassLocator;
use PhpGraph\Vendor\VendorSignatures;
use PhpParser\Error;

final class GraphBuilder
{
    /**
     * @param bool $readVendor read the signatures of dependency classes (vendor/) so call chains go through them
     */
    public function __construct(
        private readonly FileExtractor $extractor = new PhpFileExtractor(),
        private readonly bool $readVendor = true,
    ) {
    }

    /**
     * @param list<string>      $excludePatterns
     * @param list<string>|null $services        service directories, instead of detecting them (phpgraph.yaml)
     * @param BuildState|null   $previous        the last build of this process: what did not change is reused
     */
    public function build(string $root, array $excludePatterns = [], ?array $services = null, ?BuildState $previous = null): BuildResult
    {
        $options = hash('xxh128', serialize([$excludePatterns, $services, $this->readVendor]));
        if ($previous?->options !== $options) {
            $previous = null;
        }

        $finder = SourceFiles::finder($root, $excludePatterns);
        $files = [];
        $changed = [];
        $environment = hash_init('xxh128');

        $extractions = [];
        $failures = [];
        $vendorDirectories = [];
        $composerFiles = [];
        $routingFiles = [];
        $configurationFiles = [];

        foreach ($finder as $file) {
            $relativePath = str_replace('\\', '/', $file->getRelativePathname());

            if (SourceFiles::isConfiguration($file->getFilename())) {
                if (SourceFiles::isYaml($file->getFilename()) && SourceFiles::isRouting($relativePath)) {
                    $routingFiles[] = $relativePath;
                }
                $configurationFiles[] = $relativePath;
                continue;
            }

            if ($file->getFilename() === 'composer.json') {
                $composerFiles[] = $relativePath;
                $vendor = $file->getPath() . '/vendor';
                $installed = $vendor . '/composer/installed.json';
                hash_update($environment, $relativePath . ':' . $file->getMTime() . ':' . $file->getSize() . ':' . (int) @filemtime($installed) . "\n");
                if (is_file($installed)) {
                    $vendorDirectories[] = $vendor;
                }
                continue;
            }

            // Same size and modification time as in the last build of this process: the same file.
            $known = $previous?->files[$relativePath] ?? null;
            if ($known !== null && $known['mtime'] === $file->getMTime() && $known['size'] === $file->getSize()) {
                $extractions[$relativePath] = $known['extraction'];
                $files[$relativePath] = $known;
                continue;
            }

            try {
                $extraction = $this->extractor->extract($file->getContents(), $relativePath);
            } catch (Error $error) {
                $failures[$relativePath] = $error->getMessage();
                continue;
            }
            $extractions[$relativePath] = $extraction;
            $files[$relativePath] = ['mtime' => (int) $file->getMTime(), 'size' => (int) $file->getSize(), 'extraction' => $extraction, 'signature' => BuildState::signature($extraction)];
            $changed[$relativePath] = true;
        }
        $environment = hash_final($environment);

        $serviceMap = ServiceMap::detect($root, $composerFiles, $services);
        $graph = new Graph();
        $names = new NameCanonicalizer();
        $duplicates = [];

        foreach ($extractions as $path => $extraction) {
            $service = $serviceMap->serviceOf((string) $path);
            foreach ($extraction->nodes as $node) {
                if ($service !== '' && $node->kind !== NodeKind::File) {
                    $node = new Node(NameCanonicalizer::qualify($node->id, $service), $node->label, $node->kind, $node->file, $node->line, $service);
                }
                $existing = $graph->node($names->canonical($node->id));
                if ($existing !== null && $node->kind !== NodeKind::Method) {
                    $duplicates[$existing->id] ??= [(string) $existing->file];
                    $duplicates[$existing->id][] = (string) $node->file;
                    continue;
                }
                $names->declare($node->id);
                $graph->addNode($node);
            }
        }

        $pending = [];
        $handlers = [];
        $dispatches = [];
        $returnTypes = [];
        /** @var array<string, string> $returnElements */
        $returnElements = [];
        $invokedParameters = [];
        $propertyTypes = [];
        /** @var array<string, string> $constants */
        $constants = [];
        $routes = [];
        $requests = [];
        $containerDefinitions = [];
        $containerTags = [];
        $containerArguments = [];
        $routePrefixes = [];
        $parameterTypes = [];
        /** @var array<string, \Closure(string): string> $fileIds file => its names as project ids */
        $fileIds = [];
        /** @var list<array{string, TypeExpr}> $propertyReads */
        $propertyReads = [];
        // By reference: both are filled while the files are read, the helpers are evaluated after.
        $configuration = new ConfigurationEvaluator(function (string $constant, string $file) use (&$fileIds, &$constants): ?string {
            return isset($fileIds[$file]) ? $constants[$this->qualifiedMember($constant, $fileIds[$file])] ?? null : null;
        });
        foreach ($extractions as $path => $extraction) {
            $isTest = TestFiles::isTest((string) $path);
            $service = $serviceMap->serviceOf((string) $path);
            // A name written in this file, as a project id: this service's class, the shared root code, or external.
            $id = static fn (string $name): string => $names->canonical($name, $service);

            foreach ($extraction->edges as $edge) {
                $graph->addEdge(new Edge($id($edge->source), $id($edge->target), $edge->relation, $edge->confidence));
            }
            foreach ($extraction->pendingCalls as $call) {
                $pending[$path][] = new PendingCall($id($call->source), $call->receiver?->map($id), $call->method, $call->referenceOnMiss, $call->line, $call->named, $call->closure);
            }
            foreach ($extraction->returnTypes as $method => $type) {
                $returnTypes[$id($method)] ??= $type === TypeExpr::STATIC ? $type : $id($type);
            }
            foreach ($extraction->invokedParameters as $method => $parameters) {
                $invokedParameters[$id($method)] = $parameters;
            }
            foreach ($extraction->returnElements as $method => $type) {
                $returnElements[$id($method)] ??= $id($type);
            }
            foreach ($extraction->propertyTypes as $property => $type) {
                $propertyTypes[$this->qualifiedMember($property, $id)] ??= $id($type);
            }
            foreach ($extraction->routes as $route) {
                $routes[] = [$route, $service, $id];
            }
            foreach ($extraction->services as $serviceId => $target) {
                $containerDefinitions[$service][$serviceId] ??= $target;
            }
            foreach ($extraction->serviceTags as $tag) {
                $containerTags[$service][] = $tag;
            }
            foreach ($this->stateReaders($extraction->stateAccess) as [$reader, $writer, $confidence]) {
                $graph->addEdge(new Edge($id($reader), $id($writer), Relation::ReadsStateOf, $confidence));
            }
            foreach ($extraction->serviceArguments as $argument) {
                $containerArguments[$service][] = $argument;
            }
            foreach ($extraction->propertyReads as [$reader, $property]) {
                $propertyReads[] = [$id($reader), $property->map($id)];
            }
            $fileIds[(string) $path] = $id;
            $member = fn (string $name): string => str_contains($name, '::') ? $this->qualifiedMember($name, $id) : $id($name);
            foreach ($extraction->configurationHelpers as $helper => $definition) {
                $configuration->addHelper($member((string) $helper), $definition, (string) $path);
            }
            foreach ($extraction->configurationCalls as $call) {
                $configuration->addCall(['caller' => $call['caller'] === null ? null : $member($call['caller']), 'callee' => $member($call['callee'])] + $call, (string) $path, $service);
            }
            foreach ($extraction->routePrefixes as $loader => $prefixes) {
                $routePrefixes[$service][$loader] = [...$routePrefixes[$service][$loader] ?? [], ...$prefixes];
            }
            foreach ($extraction->parameterTypes as $method => $type) {
                $parameterTypes[$id($method)] ??= $id($type);
            }
            foreach ($extraction->requests as $request) {
                $requests[] = [new PendingRequest(
                    $id($request->source),
                    $request->receiver?->map($id),
                    $request->staticClass === null ? null : $id($request->staticClass),
                    $request->httpMethod,
                    $request->path,
                    $request->literal,
                    $request->absolute,
                ), $isTest];
            }
            foreach ($extraction->constants as $constant => $value) {
                $constants[$this->qualifiedMember($constant, $id)] ??= $value;
            }
            // A class constant used as a routing key, `const:Class::NAME`, refers to a class of this service.
            $key = fn (?string $key): ?string => $key !== null && str_starts_with($key, 'const:') ? 'const:' . $this->qualifiedMember(substr($key, 6), $id) : $key;
            foreach ($extraction->handlers as $handler) {
                $handlers[] = [new HandlerFact(
                    $id($handler->handlerClass),
                    $handler->method === null ? null : $id($handler->method),
                    $handler->message === null ? null : $id($handler->message),
                    $handler->evidence,
                    $key($handler->routingKey),
                ), $isTest];
            }
            foreach ($extraction->dispatches as $dispatch) {
                $dispatches[] = [new PendingDispatch(
                    $id($dispatch->source),
                    $dispatch->method,
                    $dispatch->receiver?->map($id),
                    $dispatch->receiverIsThis,
                    $dispatch->staticClass === null ? null : $id($dispatch->staticClass),
                    $dispatch->isFunction,
                    $dispatch->message?->map($id),
                    $dispatch->hasArgument,
                    $key($dispatch->routingKey),
                ), $isTest];
            }
        }

        // The calls of an unchanged file resolve as before when no declaration changed anywhere, the dependencies are
        // the same, and every name resolved to the same id.
        $namesFingerprint = $names->fingerprint();
        $sameEnvironment = $previous !== null && $previous->environment === $environment;
        $reuse = $sameEnvironment && $previous->names === $namesFingerprint && array_keys($previous->files) === array_keys($files);
        foreach (array_keys($changed) as $path) {
            $reuse = $reuse && $previous?->files[$path]['signature'] === $files[$path]['signature'];
        }

        if ($sameEnvironment && $previous->vendor !== null) {
            $vendor = $previous->vendor;
        } else {
            $locator = new ComposerClassLocator($this->readVendor ? $vendorDirectories : []);
            $vendor = $locator->isEmpty() ? null : new VendorSignatures($locator, $this->extractor);
        }

        $methodsByClass = $this->methodsByClass($graph);
        $types = new TypeResolver($graph, $names, $methodsByClass, $returnTypes, $propertyTypes, $vendor, $returnElements);

        // A method reading a property typed as an enum (`$violation->type`) uses that enum: what it outputs depends on it.
        foreach ($propertyReads as [$reader, $property]) {
            $type = $types->resolve($property);
            if ($type !== null && $graph->node($type)?->kind === NodeKind::PhpEnum) {
                $graph->addEdge(new Edge($reader, $type, Relation::References, Confidence::Inferred));
            }
        }

        $overrides = $reuse && $previous !== null ? $previous->overrides : $this->overrides($graph, $types, $methodsByClass);
        foreach ($overrides as $edge) {
            $graph->addEdge($edge);
        }

        // File by file, in the order of a full build: an edge keeps the confidence of the first call adding it.
        $methodsByName = $this->methodsByName($methodsByClass);
        $counter = new CallCounter();
        $calls = [];
        foreach ($pending as $path => $fileCalls) {
            $calls[$path] = $reuse && $previous !== null && !isset($changed[$path])
                ? $previous->calls[$path] ?? []
                : $this->resolveCalls($graph, $types, $methodsByName, $fileCalls, $invokedParameters);
            $inTests = TestFiles::isTest((string) $path);
            foreach ($calls[$path] as [$edge, $outcome, $method]) {
                if ($edge !== null) {
                    $graph->addEdge($edge);
                }
                if ($outcome !== '') {
                    $counter->add($outcome, $inTests, $method);
                }
            }
        }

        // Handlers the container configuration declares come first: the edge keeps their confidence, EXTRACTED.
        // Services declared by configuration helpers called with literal arguments.
        $evaluated = $configuration->evaluate();
        foreach ($evaluated['definitions'] as $service => $definitions) {
            foreach ($definitions as $serviceId => $target) {
                $containerDefinitions[$service][$serviceId] ??= $target;
            }
        }
        foreach ($evaluated['tags'] as $service => $tags) {
            $containerTags[$service] = [...$containerTags[$service] ?? [], ...$tags];
        }
        foreach ($evaluated['arguments'] as $service => $arguments) {
            $containerArguments[$service] = [...$containerArguments[$service] ?? [], ...$arguments];
        }
        $container = new ContainerServices($root, $configurationFiles, $serviceMap, $containerDefinitions, $containerTags, $previous->configuration ?? [], $containerArguments);
        $handlers = [...(new TaggedHandlers($graph, $names, $types, $parameterTypes))->facts($container), ...$handlers];
        $injections = (new ServiceInjections($graph, $names, $container, new TaggedClasses($graph, $names, $types, $container)))->resolve();
        $bus = (new BusResolver($graph, $names, $types, $constants))->resolve($handlers, $dispatches);
        // Paths held in class constants, now that every file is read.
        $routes = array_map(fn (array $route): array => [
            $route[0]->withConstants(fn (string $constant): ?string => $constants[$this->qualifiedMember($constant, $route[2])] ?? null),
            $route[1],
        ], $routes);
        $yamlRoutes = new YamlRoutes();
        foreach ($yamlRoutes->read($root, $routingFiles, $this->bundles($graph)) as $route) {
            $routes[] = [$route, $serviceMap->serviceOf($route->file)];
        }
        foreach ($yamlRoutes->loaderPrefixes() as $file => $prefixes) {
            foreach ($prefixes as $loader => $values) {
                $routePrefixes[$serviceMap->serviceOf($file)][$loader] = [...$routePrefixes[$serviceMap->serviceOf($file)][$loader] ?? [], ...$values];
            }
        }
        $routes = $this->withLoaderPrefixes($routes, $routePrefixes);
        $http = (new HttpResolver($graph, $names, $types, $container))->resolve($routes, $requests);
        $this->addExternalNodes($graph);

        return new BuildResult(
            $graph,
            \count($extractions),
            $failures,
            $duplicates,
            $counter->all(),
            $counter->tests(),
            $vendor?->filesRead() ?? 0,
            $composerFiles,
            \count($vendorDirectories),
            $counter->mostUnresolved(15),
            $bus,
            $serviceMap->names(),
            $http,
            new BuildState($options, $files, $environment, $namesFingerprint, $overrides, $calls, $vendor, $container->cache()),
            // An empty graph of a directory holding PHP code: every file was excluded, say so instead of serving it.
            $extractions === [] && $failures === [] ? SourceFiles::countPhpFiles($root) : 0,
            $injections,
        );
    }

    /**
     * Symfony bundles of the project, for `@SyliusShopBundle/...` imports in routing files: name => directory.
     *
     * @return array<string, string>
     */
    private function bundles(Graph $graph): array
    {
        $bundles = [];
        foreach ($graph->nodes() as $node) {
            if ($node->kind === NodeKind::PhpClass && str_ends_with($node->label, 'Bundle') && $node->file !== null) {
                $bundles[$node->label] ??= \dirname($node->file);
            }
        }

        return $bundles;
    }

    /**
     * `Class::member` with the class as a project id.
     *
     * @param \Closure(string): string $id
     */
    /**
     * Within a class, the methods reading a property that another method changes outside the constructor: the
     * reader depends on what the writer does. Properties set only by the constructor (injected services) link nothing.
     *
     * The class constants tell how much: when the writers of a property each write their own (`addContextViolation()`
     * appends a `ViolationType::CONTEXT` record, `addSurfaceViolation()` a `SURFACE` one), a reader testing the
     * writer's constant filters on what it writes (INFERRED); a reader testing only another writer's never sees it (no
     * link); a reader testing none reads everything, and may or may not care (AMBIGUOUS).
     *
     * @param array<string, array{reads: list<string>, writes: list<string>, constants?: list<string>}> $access method id => properties
     *
     * @return list<array{string, string, Confidence}> reader, writer, confidence
     */
    private function stateReaders(array $access): array
    {
        $byClass = [];
        foreach ($access as $method => $properties) {
            [$class, $name] = explode('::', $method, 2) + [1 => ''];
            $byClass[$class][$method] = $properties + ['constructor' => strtolower($name) === '__construct', 'constants' => []];
        }

        $pairs = [];
        foreach ($byClass as $methods) {
            $writers = array_filter($methods, static fn (array $method): bool => !$method['constructor'] && $method['writes'] !== []);
            foreach ($writers as $writer => $writes) {
                // Its own constants: those no other writer of the same properties uses.
                $others = [];
                foreach ($writers as $other => $otherWrites) {
                    if ($other !== $writer && array_intersect($writes['writes'], $otherWrites['writes']) !== []) {
                        array_push($others, ...$otherWrites['constants']);
                    }
                }
                $own = array_diff($writes['constants'], $others);
                $foreign = array_diff($others, $writes['constants']);

                foreach ($methods as $reader => $reads) {
                    if ($reader === $writer || $reads['constructor'] || array_intersect($writes['writes'], $reads['reads']) === []) {
                        continue;
                    }
                    $confidence = match (true) {
                        $own === [] => Confidence::Inferred,
                        array_intersect($own, $reads['constants']) !== [] => Confidence::Inferred,
                        array_intersect($foreign, $reads['constants']) !== [] => null,
                        default => Confidence::Ambiguous,
                    };
                    if ($confidence !== null) {
                        $pairs[] = [$reader, $writer, $confidence];
                    }
                }
            }
        }

        return $pairs;
    }

    /**
     * Routes made by a loader (API Platform) get the prefix of its import, `->import('.', 'api_platform')->prefix('/api')`:
     * one route per prefix, the path as declared when the import is not found.
     *
     * @param list<array{RouteFact, string}>                     $routes
     * @param array<string, array<string, list<string>>>         $prefixes service => loader => prefixes
     *
     * @return list<array{RouteFact, string}>
     */
    private function withLoaderPrefixes(array $routes, array $prefixes): array
    {
        $prefixed = [];
        foreach ($routes as [$route, $service]) {
            $loaderPrefixes = $route->loader === null ? [] : array_values(array_unique($prefixes[$service][$route->loader] ?? $prefixes[ServiceMap::ROOT][$route->loader] ?? []));
            if ($loaderPrefixes === []) {
                $prefixed[] = [$route, $service];
            }
            foreach ($loaderPrefixes as $prefix) {
                $prefixed[] = [$route->withPath($prefix . '/' . $route->path), $service];
            }
        }

        return $prefixed;
    }

    /**
     * `named: severity`, on the edge of a call written with named arguments.
     */
    private static function named(PendingCall $call): string
    {
        return $call->named === [] ? '' : 'named: ' . implode(', ', $call->named);
    }

    private function qualifiedMember(string $member, \Closure $id): string
    {
        $separator = (int) strrpos($member, '::');

        return $id(substr($member, 0, $separator)) . substr($member, $separator);
    }

    /**
     * @return array<string, array<string, string>> class => lowercase method name => method id
     */
    private function methodsByClass(Graph $graph): array
    {
        $methodsByClass = [];
        foreach ($graph->nodes() as $node) {
            if ($node->kind === NodeKind::Method) {
                $separator = (int) strrpos($node->id, '::');
                $methodsByClass[substr($node->id, 0, $separator)][strtolower(substr($node->id, $separator + 2))] = $node->id;
            }
        }

        return $methodsByClass;
    }

    /**
     * A method overriding the method of a parent, an interface or a trait: depends on declarations only.
     *
     * @param array<string, array<string, string>> $methodsByClass
     *
     * @return list<Edge>
     */
    private function overrides(Graph $graph, TypeResolver $types, array $methodsByClass): array
    {
        $overrides = [];
        foreach ($methodsByClass as $class => $methods) {
            $ancestors = $this->ancestors($graph, $class);
            foreach ($methods as $name => $methodId) {
                foreach ($ancestors as $ancestor) {
                    $overridden = $types->findMethod($ancestor, $name);
                    if ($overridden !== null && $overridden !== $methodId && $graph->hasNode($overridden)) {
                        $overrides[] = new Edge($methodId, $overridden, Relation::Overrides, Confidence::Inferred);
                        break;
                    }
                }
            }
        }

        return $overrides;
    }

    /**
     * @param array<string, array<string, string>> $methodsByClass
     *
     * @return array<string, list<string>> lowercase method name => method ids
     */
    private function methodsByName(array $methodsByClass): array
    {
        $methodsByName = [];
        foreach ($methodsByClass as $methods) {
            foreach ($methods as $name => $methodId) {
                $methodsByName[$name][] = $methodId;
            }
        }

        return $methodsByName;
    }

    /**
     * What each call site of a file resolves to: the edge it adds, if any, and its outcome.
     *
     * @param array<string, list<string>> $methodsByName
     * @param list<PendingCall>           $calls
     * @param array<string, list<array{int, string}>> $invokedParameters method id => the parameters it calls
     *
     * @return list<array{?Edge, string, string}> an empty outcome for an edge counting no call site
     */
    private function resolveCalls(Graph $graph, TypeResolver $types, array $methodsByName, array $calls, array $invokedParameters = []): array
    {
        $results = [];
        $targets = [];
        foreach ($calls as $index => $call) {
            $count = \count($results);
            $this->resolveCall($graph, $types, $methodsByName, $call, $results);
            $edge = $results[$count][0] ?? null;
            if ($edge === null || $edge->relation !== Relation::Calls) {
                continue;
            }
            $targets[$index] = $edge->target;

            // Written in a closure passed to a method calling that parameter: the method runs it.
            [$outer, $parameter] = $call->closure ?? [null, null];
            $callee = $outer === null ? null : $targets[$outer] ?? null;
            if ($callee === null) {
                continue;
            }
            foreach ($invokedParameters[$callee] ?? [] as [$position, $name]) {
                if ($parameter === $position || $parameter === $name) {
                    $results[] = [new Edge($callee, $edge->target, Relation::Calls, $edge->confidence, '', 'closure of ' . $call->source), '', $call->method];
                    break;
                }
            }
        }

        return $results;
    }

    /**
     * @param array<string, list<string>>        $methodsByName
     * @param list<array{?Edge, string, string}> $results
     */
    private function resolveCall(Graph $graph, TypeResolver $types, array $methodsByName, PendingCall $call, array &$results): void
    {
        $name = strtolower($call->method);

        $leftProject = false;
        $class = $call->receiver === null ? null : $types->resolve($call->receiver, $leftProject);

        if ($class !== null) {
            $found = $types->findMethod($class, $name);

            // A method found in a dependency is outside the project, like before: no node, no call edge.
            if ($found !== null && $graph->hasNode($found)) {
                $results[] = [new Edge($call->source, $found, Relation::Calls, Confidence::Inferred, (string) $call->line, self::named($call)), 'inferred', $call->method];
            } else {
                $edge = $call->referenceOnMiss ? new Edge($call->source, $class, Relation::References, Confidence::Extracted) : null;
                $results[] = [$edge, 'outsideProject', $call->method];
            }

            return;
        }

        $candidates = $methodsByName[$name] ?? [];
        $results[] = \count($candidates) === 1
            ? [new Edge($call->source, $candidates[0], Relation::Calls, Confidence::Ambiguous, (string) $call->line, self::named($call)), 'ambiguous', $call->method]
            : [null, $leftProject ? 'chainOutsideProject' : 'unknownReceiver', $call->method];
    }

    /**
     * @return list<string>
     */
    private function ancestors(Graph $graph, string $class): array
    {
        $parents = [];
        foreach ($graph->incident($class) as $item) {
            if ($item['forward'] && \in_array($item['edge']->relation, [Relation::Extends, Relation::Implements], true)) {
                $parents[] = $item['other'];
            }
        }

        return $parents;
    }

    private function addExternalNodes(Graph $graph): void
    {
        foreach ($graph->edges() as $edge) {
            if ($graph->hasNode($edge->target)) {
                continue;
            }

            $position = strrpos($edge->target, '\\');
            $label = $position === false ? $edge->target : substr($edge->target, $position + 1);

            $graph->addNode(new Node($edge->target, $label, NodeKind::External));
        }
    }
}
