# Changelog

phpgraph follows [semantic versioning](https://semver.org); what stays stable is listed in
[docs/STABILITY.md](docs/STABILITY.md). Before 1.0.0, any version could change it.

## 1.1.0 (2026-10-10)

### Added
- Generics, read in docblocks in the project and in `vendor/`: `@template`, what a class gives its parents
  (`@extends Repository<Order>`, `@implements`, `@use`, `@template-extends`), and the generic types of `@return`,
  `@var` and `@param` (`Collection<int, Item>`, `ScalarNodeDefinition<$this>`). A template is bound by the type of
  the receiver, through the parents passing it on: Symfony's configuration builders (`->end()` returns `TParent`)
  are typed down to the last call. A `foreach` walks the elements of any typed collection (a property, a variable,
  a method's return), up to `IteratorAggregate<K, V>`. Sylius: 20.1% → 4.0% of application calls with an unknown
  receiver; no INFERRED edge lost on the corpus.
- PHP's own classes (`DateTime`, `ArrayObject`, `SplObjectStorage`, `PDO`...), from signatures generated once
  (`tools/internal-classes.php`), with the templates of their iterables; read even without `vendor/`.
- `overview` names the other languages of a mixed repository (`TypeScript (340 files)`), and a project without PHP is
  told at once: in the MCP instructions, before any build, and in every answer.

### Changed
- `query_graph` answers are about a third of their size: a method sits on its class's line (no repeated path, no
  `has_method` edge), the edges of one source and relation share a line, and a line says when the node limit is
  reached. Neighbours must name the topic the matched classes share: `course created event handler` no longer
  brings in `CreateVideoCommandHandler` through `create` and `handler`.
- `query_graph` shows the method linking a message to its handler or sender, and a route to its controller, when
  both classes are in the answer (`CourseCreatedDomainEvent --handled_by--> IncrementCoursesCounterOnCourseCreated::__invoke()`).
- `overview` is 8 to 29% shorter: routes grouped by routing directory without `::__invoke()`, route prefixes with
  their number of routing files instead of their paths, names without the root namespace, 3 examples of layer
  violations instead of 5.
- `@return Foo|false` is read as `Foo`, as PHP's own functions and WordPress write it.

### Fixed
- `query_graph "all routes"` (and `every`, `list`...) lists every route: the word was taken for a topic.
- `outline`: the "+N more: section ..." lines were dropped when the core had families (a variable was reused).

## 1.0.2 (2026-10-04)

### Added
- `outline --section` (`-s`; `section` on the MCP tool): one section alone, every entry listed (`core`, `families`,
  `flow`, `behaviour`, `wiring`, `users`; 1 to 9 KB on a feature of 34 classes). A list cut short names its section
  (`+21 more: section behaviour`) instead of `format full`, which agents followed to a 38 KB answer too large to read
  inline. The MCP description of `format full` says so.

### Fixed
- `outline` lists every caller of a method of the core: a member of a family calling it (`ReservationRequest::create()`
  calling `MutationValidators::validate()`) was left out, though the routes went through it; members are named under
  their family (`(ValidationPathRootInterface)`), counted when more than 4. The callers from the core are added too.

## 1.0.1 (2026-10-04)

### Changed
- `outline` starts with what not to miss, "At a glance": the outcomes a branch chooses between (`422` or `200`), the
  classes nothing in the application uses and those no test touches.
- `impact` shows 8 call sites per class instead of 3, and up to 2 other chains of a route, before cutting: agents
  no longer rerun it with `--all`.

### Fixed
- `explain -d` works again as the short form of `--direction`: 1.0.0 had removed it.

## 1.0.0 (2026-10-04)

### Added
- `outline` (command and MCP tool): the structural outline of a feature named in words, enough for an agent to read
  only the few files with non-obvious logic: its classes by namespace and layer with their docblock's first sentence,
  public signatures, constants and enum cases (read in the sources on demand, not stored in the graph); the
  interfaces and base classes around it with their implementations by module, 0 stated; the routes running into it,
  through the closures it runs; behaviour read in the bodies (guarded throws, early returns, branches on constants,
  loop caps, state compared before and after); its container wiring with service ids; its users, tests counted by
  module, what no test touches and what nothing uses. About 15 KB for a feature of 34 classes.
- A method running a closure it receives (`$apply(...)`) calls what the closure calls, INFERRED, `via`
  `closure of <the method writing it>`: use case → pipeline → (closure) → builder → aggregate is a path. `impact`
  does not climb these edges: the method writing the closure already calls the same code.
- `query` adds to the classes it names the classes of the feature around them (about the question or working with
  them), and sums up an injected list once on the class receiving it (`ContextValidator receives 34
  ContextRuleInterface`) instead of an edge per member; a member of a family the answer holds is left to it.
- `query` ends with a pointer to `outline` (not for a route question), and the MCP descriptions of `outline` and
  `query_graph` say to call `outline` first when asked to explain a feature.
- `explain` and `get_node` count the tests using a node by module instead of listing them.
- `impact` lists the tests one path per line under their module, gives the other chains of a route (`+1 other chain`,
  listed with `--all`), and names the application classes on the way nothing outside tests uses.
- A reader of the state using an enum the change writes (`$violation->type->value` serialized into a response) is
  INFERRED, `[uses ViolationTypeEnum]`; the readers only guessed (AMBIGUOUS) are counted, listed with
  `--section state`.
- A `receives` edge from a service named by id says which one (`service sales_order.estimate.validation_pipeline`):
  which pipeline of a shared class each use case gets, shown by `explain`, `get_node` and the routes of `impact`.
- `query` counts the implementations of the interfaces around its answer, by folder (`ContextRuleInterface: 34`).
- `foreach` over a method documented as returning a collection (`@return list<Violation>`) types its variable.
- `impact` answers in a compact text by default: one line per class with its methods reaching the change and their
  lines, paths shortened, one line per route, tests grouped by module (about a third of the size: 10 KB instead of
  31 KB for a change reaching 90 classes). `--format full` gives every relation spelled out, `--format json` the data.
- `impact` flags the application classes no test touches, the test helper methods nothing calls any more, the calls
  written with named arguments (`named: severity`: renaming the parameter breaks them), and a method comparing the
  state before and after a call (`count($n->all())` twice), ranked INFERRED.
- `impact` lists one step further the callers of a method reading the state and filtering on what the change writes
  (a tree walker's validators); the tests reaching only a method reading all of the state are counted, listed with
  `--section state-tests`.
- `impact` finds the routes reaching a change without the depth limit, each with its chain from the route's handler
  down to the change (`CreateEstimateProcessor::process() ← CreateEstimate::handle() ← ValidationPipelineRunner::run()
  ← ContextValidator::executeRules() (tagged_iterator sales_order.estimate_quotation.context_rule) ← Rule::apply()`).
- Injected lists scope the walk: a service holding a tagged member through other services (a use case, its pipeline,
  its validator) `receives` it, INFERRED, and `impact` keeps the rules of one bounded context to the use cases wired
  to them. `receives` edges carry what injects them (`via`).
- A route handled by a generic processor, picking the use case at run time (a `tagged_locator`, or an interface
  implemented by the operation's payload), is kept only when its operation names a class the change reaches: its use
  case or its payload.
- `foreach` over a parameter or a property documented as a collection (`@param Rule[] $rules`, `iterable<Rule>`,
  `array<int, Rule>`, `list<Rule>`) types its variable: calls in such loops are resolved.
- `query` and `query_graph` match words, not substrings: names are split into words and reduced to a stem
  (validated finds Validator, prices finds PricesCalculator), rare words weigh more, and the nodes naming more of the
  question come first. Words naming the question, not code (explain, system, flow, how), are left out. Up to 6
  seeds; around them the classes of their namespace (the module asked about) and the neighbours about the question, a
  method standing for its class; test code only when it is asked for.
- `impact` lists the routes reaching the change through their controller or processor (`--section routes`), follows
  a service injected into a constructor through the whole class, and lists up to 100 tests by default.
- A static call outside any method (a configuration file's closure, a script) is a call from the file:
  `Wiring::wire()` called by `config/services.php`.
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
- `query --budget` is now `-l, --limit`, the name `query_graph` and `impact` already use.
- `explain` loses the short option `-d`, which meant `--depth` on `impact`: write `--direction`.
- The JSON of `outline` is not yet covered by the stability promise: it may change in a minor version.
- `impact` follows a template method of a parent class only to the callers holding the subclass (the processor holding
  the use case), and no longer lists the tests of every other subclass through it.
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
- `impact` no longer hangs on a test whose path back to the change loops, nor follows a test implementation of an
  interface (a fake repository) up to the application calling the interface.
- Collections documented as `iterable<T>`, `list<T>` or `array<K, T>` were read only in the `T[]` form.
- A test running a generic processor with another use case's payload is no longer listed for a change behind it.
- An API Platform route references the classes of its own operation only, not those of the resource's other
  operations.
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
