<p align="center">
  <img src="https://raw.githubusercontent.com/hbenabdallah/phpgraph/main/docs/phpgraph.png" alt="phpgraph turns a PHP codebase into a knowledge graph of domains, handlers, repositories, controllers and adapters, served to AI agents through a CLI and an MCP server" width="100%">
</p>

<h1 align="center">phpgraph</h1>

<p align="center">
  <b>A knowledge graph of your PHP codebase, for AI coding agents.</b><br>
  Static analysis served over MCP to <a href="https://github.com/hbenabdallah/sherpa">Sherpa</a>, Claude Code, Codex, Cursor and any MCP client.<br>
  No LLM, no network, no embeddings: deterministic facts, each with a confidence level.
</p>

<p align="center">
  <a href="https://github.com/hbenabdallah/phpgraph/actions/workflows/ci.yml"><img src="https://github.com/hbenabdallah/phpgraph/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="https://packagist.org/packages/hbenabdallah/phpgraph"><img src="https://img.shields.io/packagist/v/hbenabdallah/phpgraph" alt="Packagist version"></a>
  <a href="https://packagist.org/packages/hbenabdallah/phpgraph"><img src="https://img.shields.io/packagist/dt/hbenabdallah/phpgraph" alt="Downloads"></a>
  <img src="https://img.shields.io/badge/php-%3E%3D8.2-777bb4" alt="PHP 8.2+">
  <a href="https://github.com/hbenabdallah/phpgraph/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-PolyForm%20Shield%201.0.0-blue" alt="License: PolyForm Shield 1.0.0"></a>
</p>

<p align="center"><b>English</b> · <a href="https://github.com/hbenabdallah/phpgraph/blob/main/README.fr.md">Français</a></p>

---

## Why phpgraph?

An AI agent dropped into an unfamiliar PHP project reads files one by one and greps for names. It misses what matters most in a real codebase: **who calls what, which handler receives a message, which controller serves a route, what breaks if a method changes, and which layer depends on which**.

phpgraph parses the whole project once (with [`nikic/php-parser`](https://github.com/nikic/PHP-Parser)) and answers those questions from a graph:

- **Deterministic facts, not guesses.** Every relation is `EXTRACTED` (read in the code), `INFERRED` (resolved from declared types) or `AMBIGUOUS` (a guess, labelled as such).
- **It says what it does not know.** Unresolved calls, missing `vendor/`, messages without handlers: the gaps are reported, so the agent knows when to read the code itself.
- **Built for DDD and hexagonal architecture**, then microservices, then PHP in general: Symfony, Laravel, Ecotone, PrestaShop, WordPress, or plain PHP.
- **Private by design.** Your code never leaves your machine, and phpgraph never executes it: it only parses it.

## Features

- **Call graph with type inference**: return types, chained calls (`$a->b()->c()`), local variables, docblocks (`@return`, `@var`), and signatures of your `vendor/` dependencies, so chains go through Doctrine, Symfony or Laravel APIs.
- **Architecture**: DDD and hexagonal layers, bounded contexts, layer rules checked in CI (`phpgraph check`), and impact analysis (`impact_of`): what depends on a class or method, and which tests to run.
- **Messages and events**: sender → message → handler for Symfony Messenger, Laravel jobs and events, Ecotone, PrestaShop CQRS, WordPress hooks, domain events and your own buses, from attributes, service configuration (YAML, XML, PHP) or code shape.
- **HTTP**: routes (Symfony attributes, YAML and PHP routing files, API Platform resources, Laravel route files, controllers named by container service id) linked to controllers, and HTTP calls linked to the routes they reach. A route whose controller class exists neither in the project nor in `vendor/` is reported with its routing file.
- **Dependency injection**: what the Symfony container configuration injects beyond constructor types, every service of a tag (`tagged_iterator`, `#[AutowireIterator]`) or a service named by id, from PHP, YAML or XML configuration: a validator is linked to the rules it receives.
- **Microservices**: one id space per service, and links between services through their contracts: shared message classes, routing keys, HTTP routes.
- **Zero-configuration MCP server**: built on first use, rebuilt incrementally when the code changes (about 3 s per edit on an 8,400-file project).

## Quick start

**1. Install.** A single executable, no PHP needed (Linux and macOS on x86_64 and ARM, Windows on x86_64):

```bash
curl -fsSL https://raw.githubusercontent.com/hbenabdallah/phpgraph/main/install.sh | sh                  # latest release
curl -fsSL https://raw.githubusercontent.com/hbenabdallah/phpgraph/main/install.sh | sh -s -- 0.2.0     # a given release
curl -fsSL https://raw.githubusercontent.com/hbenabdallah/phpgraph/main/install.sh | sh -s -- dev-main  # the main branch
```

```powershell
irm https://raw.githubusercontent.com/hbenabdallah/phpgraph/main/install.ps1 | iex                       # Windows
$env:PHPGRAPH_VERSION = "dev-main"; irm https://raw.githubusercontent.com/hbenabdallah/phpgraph/main/install.ps1 | iex
```

The script downloads the binary, checks its SHA-256 and puts it in `~/.local/bin` (Windows: `%LOCALAPPDATA%\Programs\phpgraph`, added to the PATH); `PHPGRAPH_INSTALL_DIR` changes the directory. Run it again to update. `dev-main` is rebuilt on every push to `main` (the `edge` pre-release). The binary is a static PHP runtime with phpgraph inside, built with [static-php-cli](https://github.com/crazywhalecc/static-php-cli).

With PHP 8.2+ installed, or with Docker:

```bash
composer require --dev hbenabdallah/phpgraph       # or: composer global require hbenabdallah/phpgraph
curl -LO https://github.com/hbenabdallah/phpgraph/releases/latest/download/phpgraph.phar
docker pull ghcr.io/hbenabdallah/phpgraph          # nothing else to install
```

**2. Connect your agent.** `mcp-config` prints a ready-to-paste configuration with the paths filled in (`vendor/bin/phpgraph` when installed with Composer):

```bash
phpgraph mcp-config sherpa    # Sherpa: ~/.config/sherpa/mcp.json, one declaration for every project
phpgraph mcp-config claude    # Claude Code: a `claude mcp add` command, or .mcp.json
phpgraph mcp-config codex     # Codex: ~/.codex/config.toml
phpgraph mcp-config cursor    # Cursor: .cursor/mcp.json, shareable with the team
phpgraph mcp-config json      # any other MCP client over stdio
```

With [Sherpa](https://github.com/hbenabdallah/sherpa), a terminal coding agent, one declaration in `~/.config/sherpa/mcp.json` serves every project: Sherpa starts its MCP servers from the project it runs in.

```json
{
  "mcpServers": {
    "phpgraph": { "command": "/home/you/.local/bin/phpgraph", "args": ["serve"] }
  }
}
```

With Claude Code (absolute paths, as `mcp-config` prints them):

```bash
claude mcp add phpgraph -- /home/you/.local/bin/phpgraph serve /path/to/project
```

**3. Ask your agent** something like *"Give me an overview of this project"*, *"How does an order get placed?"* or *"What breaks if I change `OrderRepository::save`?"*. The graph is built on the first call, then kept up to date.

## MCP tools

| Tool | Use it to |
|---|---|
| `overview` | Start here: Composer stack, namespace tree with layers, bounded contexts, layer-rule violations, messages, HTTP routes (each with method, path, controller and file up to 30, else grouped by path prefix), services, and what the graph cannot see. |
| `query_graph` | Find the code about a topic when you do not know the class names. A question about routes (*"mooc courses routes"*, *"GET /courses"*) returns the matching routes and their controllers. |
| `get_node` | Read one class, method, route or channel with all its relations. |
| `get_neighbors` | See what uses a node (`in`) or what it depends on (`out`). |
| `impact_of` | Before a change: every class that depends on a class or method, nearest first, with the lines of the calls; callers through the interface it implements; code depending on the state it writes; and the tests to run, found through test helpers. |
| `shortest_path` | See how two pieces of code are connected. |
| `god_nodes` | Find the hubs most of the code depends on. |

The server tells the agent to call `overview` first. A path, as an agent sees it, on [php-ddd-example](https://github.com/CodelyTV/php-ddd-example):

```text
Shortest path (4 hops):
  PUT /courses/{id} --handled_by--> CoursesPutController::__invoke()
    --dispatches--> CreateCourseCommand --handled_by--> CreateCourseCommandHandler::__invoke()
    --calls--> CourseCreator::__invoke()
```

## Command line

```bash
phpgraph build [path]                        # phpgraph-out/graph.json and GRAPH_REPORT.md
phpgraph overview                            # stack, structure and gaps
phpgraph impact "OrderRepository::save"      # what depends on it, and the tests to run
phpgraph check                               # layer rules, for CI: exit code 1 on a new violation
phpgraph explain "PlaceOrderHandler" [-d in] # a node and its relations
phpgraph path "StockChecker" "DbalOrderRepository"
phpgraph query "how is stock checked"
phpgraph serve [path]                        # the MCP server, over stdio
phpgraph mcp-config claude|codex|cursor|json [--docker image]
```

`build` options: `-e` to exclude paths (repeatable), `--no-vendor` not to read dependencies, `--no-cache` to parse every file again. `vendor`, `node_modules`, `var`, `.git` and the files ignored by the project's own `.gitignore` files are skipped; a git repository above the project never applies its rules, so a project copied into an ignored directory is still read. When the directory holds PHP files but every one is excluded, `build` fails and says so, and every MCP answer starts with that warning instead of serving an empty graph. Memory is unlimited by default; `PHPGRAPH_MEMORY_LIMIT=2G` sets a limit.

## What the graph contains

| Relation | From → to |
|---|---|
| `defines`, `imports` | file → class, `use` statement |
| `extends`, `implements`, `uses_trait` | class → parent, interface, trait |
| `has_method`, `overrides` | class → method, method → the method it overrides |
| `instantiates`, `references` | `new Foo()`; parameter, return and property types, `catch`, `instanceof`, constants, attributes |
| `calls` | method → resolved method, with the lines of the call sites |
| `dispatches`, `handled_by` | sender → message or channel → handler; route → controller |
| `contract` | a message class sent by one service → the same class handled by another |
| `requests` | HTTP call → the route it reaches, in the same service or another |
| `receives` | service → each service the container configuration injects into it (a tag, an id, the decorated service) |
| `reads_state_of` | method → a method of the same class changing a property it reads, outside the constructor (`hasErrors()` → `add()`), INFERRED |

Every relation carries a confidence level:

| Level | Meaning |
|---|---|
| `EXTRACTED` | Read in the code of one file, or in the configuration (attribute, service tag, route file). |
| `INFERRED` | Resolved across files from declared types: parameters, properties, `$this`, inheritance, return types, shape of the code. |
| `AMBIGUOUS` | A guess, for example the only method of the project with that name, called on a receiver of unknown type. |

<details>
<summary><b>Frameworks and conventions recognised</b></summary>

| | Handlers and listeners | Messages sent | HTTP |
|---|---|---|---|
| **Symfony** | `#[AsMessageHandler]`, `#[AsEventListener]`, tags `messenger.message_handler` and `kernel.event_listener` (YAML, XML, PHP, `_instanceof`), `getSubscribedEvents()`, `addListener()` | `MessageBusInterface`, `EventDispatcherInterface`, named events | `#[Route]` (paths in class constants too), YAML and PHP routing files, controllers named by service id; API Platform `#[ApiResource]` and operation attributes, handled by their provider, processor or controller |
| **Laravel** | `$listen`, `Event::listen()`, jobs (`ShouldQueue`, `Dispatchable`) | `event()`, `dispatch()`, facades, `Job::dispatch()` | `Route::get/post/…`, `match`, `resource`, groups with `prefix`, `routes/api.php`; `Http::` client |
| **Ecotone** | `#[CommandHandler]`, `#[EventHandler]`, `#[QueryHandler]`, routing keys | `CommandBus`, `EventBus`, `DistributedBus`, `sendWithRouting()` | |
| **PrestaShop** | `#[AsCommandHandler]`, `#[AsQueryHandler]` | `CommandBusInterface::handle()` | YAML routing |
| **WordPress** | `add_action()`, `add_filter()` | `do_action()`, `apply_filters()` | |
| **Your own code** | handler attributes and tags recognised by name, `__invoke`/`handle(Message $m)`, `*Handler` classes | `*Bus`, `*Dispatcher`, `*Publisher` types, aggregates' `recordThat()`, your bus wrappers | Guzzle, Symfony HttpClient, PSR-18 |

</details>

<details>
<summary><b>Architecture: layers, bounded contexts, rules in CI</b></summary>

The layer of a class is read from its namespace: the first explicit segment (`Domain`, `Application`, `Infrastructure`, `UI`, `Presentation`, `Port`, `Adapter`, `UseCase`), or else the last generic one (`Model`, `Entity`, `Controller`, `Http`, `Persistence`…). The bounded context is read before the layer (`CodelyTv\Mooc\Courses\Domain`) or after it when the project puts the layer first (`Core\Domain\Product`).

`phpgraph check` applies the default rules of a layered architecture to application code: `domain` must not depend on `application`, `infrastructure` or `interface`; `application` and `port` must not depend on `infrastructure` or `interface`. On a project that already has violations:

```bash
phpgraph check --generate-baseline   # accept today's violations in phpgraph-baseline.json
phpgraph check                       # fail on new ones only
```

</details>

<details>
<summary><b>Microservices</b></summary>

A repository holding two or more independent Composer applications is split into services. Packages of a monorepo stay in their project: a package the root autoloads, declares as a path repository or `replace`s is a part, not a service. Ids then carry their service, `billing@App\Domain\Order`, and a name never resolves into another service: services meet through contracts.

- the same message class sent by one service and handled by another: `contract`;
- a routing key (`sendWithRouting('order.place', …)`, `#[CommandHandler('ticket.create')]`, a named event): a `channel:` node shared by all services;
- an HTTP call to a route of another service: `requests`. A call whose path matches a route declared for other HTTP methods only is reported: it would fail as written.

An optional `phpgraph.yaml` names the services when detection does not fit:

```yaml
services:
  - services/billing
  - services/shipping
```

</details>

<details>
<summary><b>How it works</b></summary>

1. Every PHP file is parsed into facts: declarations, calls with the expression typing their receiver, message sends and handlers, routes, HTTP calls.
2. The builder resolves those facts across files: types through inheritance and return types, `vendor/` signatures read on demand from Composer's metadata (parsed, never executed), service definitions from the container configuration.
3. The graph is saved as `phpgraph-out/graph.json`, with `GRAPH_REPORT.md` for humans.

Rebuilds are incremental: a file's extraction is cached by content, and the MCP server re-resolves only the calls of changed files when no declaration changed anywhere. The result is identical to a full build.

</details>

## Measured on real projects

phpgraph is developed against a corpus of open-source projects pinned to a commit, and every change is judged on their numbers (`composer corpus:measure`). Application code only; *receiver typed* is the share of method calls whose receiver type phpgraph could determine.

| Project | Kind | PHP files | Build | Receiver typed |
|---|---|---:|---:|---:|
| [php-ddd-example](https://github.com/CodelyTV/php-ddd-example) | DDD, CQRS | 304 | 0.4 s | 98.3 % |
| [Sylius](https://github.com/Sylius/Sylius) | Symfony e-commerce | 4,946 | 6.5 s | 79.6 % |
| [Akeneo PIM](https://github.com/akeneo/pim-community-dev) | hexagonal, bounded contexts | 8,416 | 10.3 s | 87.0 % |
| [PrestaShop](https://github.com/PrestaShop/PrestaShop) | CQRS and legacy | 7,850 | 10.0 s | 90.5 % |
| [Ecotone quickstart](https://github.com/ecotoneframework/quickstart-examples) | 47 services, messaging | 583 | 0.6 s | 91.8 % |
| [BookStack](https://github.com/BookStackApp/BookStack) | Laravel | 1,513 | 2.8 s | 87.4 % |
| [WordPress](https://github.com/WordPress/WordPress) | no framework, hooks | 1,899 | 5.3 s | 90.9 % |

Two small microservices projects complete the corpus: [two Laravel services over RabbitMQ](https://github.com/mostafaaminflakes/Using-RabbitMQ-in-Microservices), linked by 4 message contracts, and [four Laravel services over HTTP](https://github.com/omarihab99/Art-Gallery), where phpgraph found a `POST` sent to a `GET`-only route. Zero parse failures on about 26,000 files. The details per project, including messages, routes and services, are in [`corpus/RESULTS.md`](https://github.com/hbenabdallah/phpgraph/blob/main/corpus/RESULTS.md).

## FAQ

**Does phpgraph send my code anywhere?** No. It runs locally, makes no network call and uses no LLM.

**Does it execute my code?** No. It parses PHP files and reads JSON, YAML and XML configuration. Even `autoload_classmap.php` and PHP service configurators are parsed, not included.

**Which PHP versions can it analyse?** Anything `nikic/php-parser` 5 parses: PHP 7 and 8 syntax. phpgraph itself needs PHP 8.2 or newer.

**Which agents does it work with?** Any MCP client over stdio: [Sherpa](https://github.com/hbenabdallah/sherpa), Claude Code, Codex, Cursor, Claude Desktop, and others. `mcp-config` prints the configuration for the common ones.

**How large a project can it handle?** The largest project of the corpus, Akeneo, has 8,400 files: about 10 s and 500 MB for a full build, about 3 s for a rebuild after an edit.

## Limitations

- Calls to global functions and dynamic calls (`$this->$name()`, `__call`) are not resolved.
- No generics: the element type of a `foreach` stays unknown, and `Collection<Foo>` is read as `Collection`.
- Chains stop at magic methods, PHP internal classes, and dependencies without a usable return type.
- Without an installed `vendor/`, call chains stop at the first dependency.
- Services built at runtime (compiler passes, bundle extensions, ids computed in code), XML routes, API Platform resources declared in XML or YAML, and API schemas (OpenAPI, protobuf) are not read; injections that cannot be linked are listed by `overview`.
- Two services declaring a message class of the same name are assumed to share it.

## Contributing

Issues and pull requests are welcome. See [CONTRIBUTING.md](https://github.com/hbenabdallah/phpgraph/blob/main/CONTRIBUTING.md): `composer check` must pass (php-cs-fixer, PHPStan level 8, PHPUnit), and changes to the analysis are judged on the corpus. Without PHP installed, `bin/dev composer check` runs everything in Docker.

Releases are published by pushing a version tag: the [release workflow](https://github.com/hbenabdallah/phpgraph/blob/main/.github/workflows/release.yml) builds the PHAR, the executables for Linux, macOS and Windows (`tools/build-binary.sh`), and the Docker image `ghcr.io/hbenabdallah/phpgraph`.

## License

phpgraph is source-available under the [PolyForm Shield License 1.0.0](https://github.com/hbenabdallah/phpgraph/blob/main/LICENSE): you may use, modify and redistribute it, including in a company, for any purpose that does not compete with phpgraph. Providing a competing product from this code is not allowed. This is not an OSI-approved open-source license.
