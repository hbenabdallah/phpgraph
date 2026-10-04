# Changelog

phpgraph follows [semantic versioning](https://semver.org); what stays stable is listed in
[docs/STABILITY.md](docs/STABILITY.md). Before 1.0.0, any version could change it.

## Unreleased

### Added
- `impact` lists, under each class, every method reaching the change with the lines of its calls (3 per class by
  default, all with `--all` or `limit: 0`).
- A strategy declaring what it handles, `supports(object $input): bool { return $input instanceof LineQuery; }`
  (rules, voters, normalizers), is linked: `LineQuery --handled_by--> LineRule`, INFERRED.
- `path` finds a dependency in the other direction before ignoring directions: from a rule to the use case running
  it, it shows that the use case depends on the rule.
- `impact` and `impact_of` give complete lists on demand: `--limit N` (`limit`), `--all` (`limit: 0`), and one list
  only with `--section direct|state|tests|helpers` (`section`).
- `docs/STABILITY.md`: the commands, MCP tools, graph format and files kept stable from 1.0.0.
- CI on PHP 8.5.
- The Docker image runs PHP 8.5 with opcache and its JIT, and `mcp-config` adds them to the `php` command: builds about 20% faster (Sylius 7.7 s to 6.4 s, Akeneo 12.9 s to 9.9 s), the same graph. The executables run PHP 8.5.
- A version tag with a suffix (`v1.0.0-rc.1`) is published as a pre-release, and does not move the `latest` Docker tag.

### Changed
- `reads_state_of` is weighed by the class constants: when the writers of a property each write their own constant
  (`ViolationType::CONTEXT`, `SURFACE`), a reader testing the writer's is INFERRED, a reader testing only another
  writer's is not linked, and a reader testing none is AMBIGUOUS. `impact` ranks the first ones first.
- `impact` classifies a class reached both by a call and through the state as a caller.
- `explain` and `get_node` group the connections by relation, the most telling first, and only count the files
  importing a class.
- `impact`: the code reached through the state a method writes is listed as possibly affected, its callers no longer
  followed; tests reached that way are listed apart, those in the modules of the direct dependents first.
- The MCP server negotiates the protocol version: it answers with the client's version when it speaks it
  (2024-11-05 to 2025-11-25), else with its latest.
- `impact` with an unknown section exits with code 2, as other invalid input.

### Fixed
- A graph built by another version of phpgraph is rebuilt by `serve`, even when the sources did not change: an upgrade
  no longer serves a graph without what the new version finds.
- A `graph.json` in another format is refused with a message asking to rebuild it, instead of being read wrongly.

## 0.1.9 (2026-10-03)

### Added
- `impact` follows calls through the interface or parent method a method implements (INFERRED), lists the code
  depending on the state the method writes (new relation `reads_state_of`), and finds tests through test helpers
  (fakers, fixtures) without the depth limit, listed apart from the helpers.
- `calls` edges carry the lines of their call sites, shown by `impact` and `get_node`.
- `get_node` shows the callers reaching a method through the interface it implements.
- Symfony service configuration written in helpers called with literal arguments
  (`Wiring::wire($services, 'sales', ...)`) is evaluated, never run: the ids, tags and namespaces it computes are read.

### Changed
- `impact` lists, without following them, a service receiving the change among others (a tagged collection) and
  inherited code shared with other subclasses; it no longer goes from a route to its controller.

### Fixed
- API Platform operations naming their HTTP method by a constant (`Request::METHOD_POST`) get the right method.

## 0.1.8 (2026-10-03)

### Added
- API Platform: `#[ApiResource]` and operation attributes are routes, with the prefix of the `api_platform` import,
  handled by their provider, processor or controller, and referencing the resource, input and output.
- Symfony PHP routing files (`$routes->add()->controller()`) and route paths held in class constants.
- Container injections, relation `receives`: `tagged_iterator`, `service()`, `!tagged_iterator`, `@id`, XML
  arguments, `#[AutowireIterator]`, `#[AutoconfigureTag]`, decorated services, tagged namespace resources.
- `PHPGRAPH_MEMORY_LIMIT`.

### Fixed
- Every command raises the memory limit, not only `build`, `check` and `serve`: reading a large graph no longer runs
  out of memory.

## 0.1.4 to 0.1.7 (2026-10-03)

### Added
- Executables needing no PHP, for Linux and macOS (x86_64 and ARM) and Windows (x86_64), built with static-php-cli,
  published with each release and, as the `edge` pre-release, on every push to `main`.
- `install.sh` and `install.ps1`: the latest release, a given one, or `dev-main`, checked against its SHA-256.
- `mcp-config` gives the executable itself as the command when phpgraph runs as one.

These versions mostly fixed the release workflow; 0.1.7 is the first with every executable.

## 0.1.3 (2026-10-03)

### Added
- `query_graph` answers questions about routes (routes, endpoints, HTTP, a path); `overview` lists the routes, or
  groups them by path prefix.
- Routes whose controller exists neither in the project nor in `vendor/` are reported with their routing file.

### Fixed
- Only the project's own `.gitignore` files apply: a project inside a directory ignored by a parent repository is no
  longer read as empty. A graph left empty by the exclusion rules says so, in the CLI and in every MCP answer.

## 0.1.1 and 0.1.2 (2026-10-02)

- Sherpa, Claude Code, Codex and Cursor in the package keywords and `mcp-config`.

## 0.1.0 (2026-10-02)

First release: the knowledge graph of a PHP project by static analysis, its CLI and its MCP server.

- Classes, methods, calls resolved through declared types, return types and `vendor/` signatures, with a confidence
  level on every relation.
- Messages and events (Symfony Messenger, Laravel, Ecotone, PrestaShop, WordPress hooks, project buses), HTTP routes
  and calls, microservices and their contracts.
- Layers and bounded contexts, layer rules checked in CI (`check`), impact analysis (`impact`).
- `serve` without configuration: the graph is built on first use and rebuilt incrementally.
- The PHAR and the Docker image.
