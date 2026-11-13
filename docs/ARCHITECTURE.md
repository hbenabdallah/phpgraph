# Architecture

phpgraph builds a knowledge graph of a PHP project by static analysis (`nikic/php-parser`, no LLM, no network) and
serves it to agents (MCP) and people (CLI). It never runs or includes code of the project it analyses. It does not
"understand" the project: it states facts with a confidence level and says what it could not link. Understanding is
the agent's job.

Priorities, in this order: DDD / hexagonal architecture, then microservices, then PHP in general. A change that
helps the first two must not make general PHP worse; the [corpus](../CONTRIBUTING.md#measurement-corpus) is how
that is checked.

## Pipeline

```
files ──► Extractor ──► FileExtraction (one per file, cached)
                              │
                              ▼
                          Builder ──► Graph (nodes, edges, confidence) + ProjectSummary ──► graph.json
                                                     │
                                                     ▼
                                                Query/GraphQuery ──► Query/Result/* ──► Presentation ──► CLI, MCP, GRAPH_REPORT.md
```

1. **Extract** (`src/Extractor/`). `FileExtractor` turns one file into a `FileExtraction`: nodes declared in it,
   edges certain from that file alone (EXTRACTED), and everything that needs other files to be resolved, kept as
   pending facts: `PendingCall` (a call and the `TypeExpr` of its receiver), `PendingDispatch`, `PendingRequest`,
   `HandlerFact`, `RouteFact`, container definitions… `PhpFileExtractor` is the implementation; the interface leaves
   room for another engine. Files are extracted in parallel processes (`Builder/ParallelExtraction`) and cached
   by content (`CachingExtractor`).
2. **Build** (`src/Builder/`). `GraphBuilder::build()` assembles the extractions once every file is known: it
   canonicalizes names (`NameCanonicalizer`), reads the container configuration (`ContainerServices`), resolves
   receiver types (`TypeResolver`, with generics, unions, `vendor/` signatures and library providers), then calls,
   bus messages (`BusResolver`), injections, HTTP routes and requests (`HttpResolver`), API schemas, and counts
   what it resolved (`CallStats`, `BusStats`…). `BuildState` keeps what an incremental build needs to re-resolve
   only what changed.
3. **Store** (`src/Storage/`, `src/Project/`). `JsonGraphStorage` writes `phpgraph-out/graph.json` (format v2,
   [STABILITY.md](STABILITY.md)). `ProjectGraph` decides when to rebuild (source fingerprint, at most every 2 s)
   and keeps the last build in memory for `serve`. `ProjectSummary` records what the build learnt besides the
   graph (stack, unresolved calls, `vendor/`).
4. **Query** (`src/Query/`). `GraphQuery` answers questions and returns structures (`Query/Result/*`), never text:
   `overview`, `impactOf` (`ImpactAnalysis`), `outline` (`FeatureOutline`, `FeatureCluster`, `MethodCone`),
   `shortestPath`, layer and project rules (`Layers`, `LayerRules`, `ProjectRules`).
5. **Present** (`src/Presentation/`). `TextPresenter`, `ImpactReport`, `OutlineReport` turn results into text or
   JSON for the commands (`src/Command/`) and the MCP server (`src/Mcp/McpServer`, JSON-RPC over stdio, no
   dependency; stdout carries JSON-RPC only).

## Layers

The lower layers keep a strict order, checked in review: `Graph` depends only on `Values`, `Extractor` only on
`Graph`, `Vendor` on `Extractor` and `Graph`, `Builder` on those three (and `Values`). The upper layers (`Storage`,
`Project`, `Query`, `Presentation`, `Command`, `Mcp`) use what they need.

| Directory | Holds |
|---|---|
| `Graph/` | the model: `Node`, `Edge`, `Relation`, `Confidence`, `Graph` |
| `Extractor/` | parsing (`PhpCodeParser`: newest grammar, then PHP 5.6), `ExtractionVisitor`, docblocks (`DocTypeResolver`, `phpstan/phpdoc-parser`), detectors (`BusExtractor`, `RouteExtractor`, `ApiPlatformResources`, `ConfigurationHelpers`) |
| `Vendor/` | signatures of dependencies, read on demand, never nodes: `ComposerClassLocator` (reads `installed.json`, parses `autoload_classmap.php`, never includes it), `VendorSignatures`, `InternalClasses` (PHP's own classes, generated) |
| `Builder/` | assembly and resolution; `Builder/Libraries/` for [library providers](PROVIDERS.md) |
| `Storage/`, `Project/` | `graph.json`, rebuilds, `phpgraph.yaml` (`ProjectConfig`), stack detection, project rules |
| `Query/` | questions on a built graph |
| `Presentation/` | text and JSON |
| `Command/`, `Mcp/` | entry points |

Values read at a boundary (JSON, YAML, CLI options, MCP requests) go through `PhpGraph\Values`, so the rest of the
code works on checked types (PHPStan runs at level max).

## Confidence

Every edge says how it was found, and phpgraph never raises it:

- **EXTRACTED**: read in the code or the configuration (`extends`, `new Foo`, an attribute, a YAML service).
- **INFERRED**: resolved from declared types (`$this->repository->save()` through the property's type).
- **AMBIGUOUS**: guessed from a method name declared by one class only, or by several.

What cannot be linked is counted, not guessed: unknown receivers, messages without a handler, requests matching no
route, injections the configuration does not define. `overview` and `meta.summary` list them.

## How a call gets its edge

The part most changes touch. The extractor cannot know the receiver's class (it may be declared in another file),
so it records the receiver as a `TypeExpr`: a class name, the return of a method, a property, an element of a
collection, a union, a narrowed type (`instanceof`), the value of a `require`… Local variables are typed per path
(`ExtractionVisitor::onFlow`: branches copied, merged where they reach the end). Once all files are known,
`TypeResolver::resolve()` turns the `TypeExpr` into a class, following inheritance, templates (`@template`,
`@extends`), `vendor/` signatures, then library providers. `GraphBuilder::resolveCall()` finds the method and adds
the `calls` edge: INFERRED when the type is known, AMBIGUOUS when only the name decides, nothing (counted as unknown)
otherwise.

## Where to add what

| You want to… | Start in |
|---|---|
| read a new PHP construct or docblock tag | `Extractor/ExtractionVisitor`, `Extractor/DocTypeResolver`; a new field of `FileExtraction` goes in `CachingExtractor::ALLOWED_CLASSES` (objects) and `BuildState::signature` |
| type what a library returns | a provider in `Builder/Libraries/` ([PROVIDERS.md](PROVIDERS.md)) |
| link a new kind of message bus, handler or listener | `Extractor/BusExtractor` (facts), `Builder/BusResolver` (links), `Builder/TaggedHandlers` (handlers declared by configuration) |
| read routes of a framework | `Extractor/RouteExtractor`, `Builder/YamlRoutes`, `Builder/HttpResolver` |
| read container configuration | `Builder/ContainerServices`, `Builder/ServiceInjections` |
| answer a new question | `Query/GraphQuery` returning a `Query/Result/*` object, text in `Presentation/` |
| add a command or an MCP tool | `src/Command/`, `Mcp/McpServer`; both go in [STABILITY.md](STABILITY.md) once released |

## Rules for changes

- `composer check` stays green (PHP CS Fixer PER-CS 2.0, PHPStan level max, PHPUnit). Without PHP: `bin/dev composer check`.
- PHP 8.5 minimum. Classes are `final` (`readonly` when they can be), except `McpServer` and `AbstractGraphCommand`.
- One PHPUnit test per new construct, next to the existing ones, with the project written inline by the
  `TemporaryProject` trait and built for real.
- A change of extraction or resolution is judged on the corpus: `composer corpus:measure` before and after, no
  INFERRED edge lost (or each loss explained), a sample of the new edges checked in the code. Numbers go in the pull
  request and the changelog.
- Everything phpgraph prints is in English. [README.fr.md](../README.fr.md) translates the README.
- What users and agents rely on (commands, MCP tools, `graph.json`, the JSON of `impact` and `outline`) is listed in
  [STABILITY.md](STABILITY.md) and changes only in a major version.
