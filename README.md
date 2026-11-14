<h1 align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/alpine-ronin/phpgraph/main/docs/brand/logo-dark.svg">
    <img src="https://raw.githubusercontent.com/alpine-ronin/phpgraph/main/docs/brand/logo.svg" alt="phpgraph" width="484">
  </picture>
</h1>

<p align="center">
  <b>A knowledge graph of your PHP codebase, for AI coding agents.</b><br>
  Static analysis served over MCP to <a href="https://github.com/hbenabdallah/sherpa">Sherpa</a>, Claude Code, Codex, Cursor and any MCP client.<br>
  No LLM, no network, no embeddings: deterministic facts, each with a confidence level.
</p>

<p align="center">
  <a href="https://github.com/alpine-ronin/phpgraph/actions/workflows/ci.yml"><img src="https://github.com/alpine-ronin/phpgraph/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="https://packagist.org/packages/alpine-ronin/phpgraph"><img src="https://img.shields.io/packagist/v/alpine-ronin/phpgraph" alt="Packagist version"></a>
  <a href="https://packagist.org/packages/alpine-ronin/phpgraph"><img src="https://img.shields.io/packagist/dt/alpine-ronin/phpgraph" alt="Downloads"></a>
  <img src="https://img.shields.io/badge/php-%3E%3D8.5-777bb4" alt="PHP 8.5+">
  <a href="https://github.com/alpine-ronin/phpgraph/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue" alt="License: MIT"></a>
  <a href="https://mcpservers.org/servers/hbenabdallah/phpgraph"><img src="https://mcpservers.org/badge.svg" alt="Listed on mcpservers.org"></a>
</p>

<p align="center"><b>English</b> · <a href="https://github.com/alpine-ronin/phpgraph/blob/main/README.fr.md">Français</a></p>

---

## What it answers

An agent in a PHP project it does not know greps names and reads files. That works until the answer goes through something a search of names does not show: an interface, the container configuration, a bus, a route, another service. phpgraph parses the whole project once (with [`nikic/php-parser`](https://github.com/nikic/PHP-Parser), docblocks with [`phpstan/phpdoc-parser`](https://github.com/phpstan/phpdoc-parser)) and answers from a graph, in one call:

- **What breaks if I change this method?** `impact_of` lists every dependent, nearest first, callers through the interface it implements included, the routes reaching it and the tests to run. Sylius, the 17 callers of `OrderItemQuantityModifierInterface::modify()`: phpgraph finds 17, codebase-memory-mcp 11 (2 false), graphify 0.
- **How does this feature work?** `outline` gives the classes, the routes running into them, the behaviour read in their bodies, the wiring and the tests in one answer, so the agent reads only the files with non-obvious logic. Claude Code on 6 explain tasks: −12 % tokens, −26 % time, −42 % tool calls.
- **Which handler receives this message, which controller serves this route?** Senders, messages and handlers are linked for Symfony Messenger, Laravel, Ecotone, PrestaShop CQRS, WordPress hooks and your own buses; routes to their controllers, HTTP calls to the routes they reach. Sylius: 206 Messenger handlers linked to their message; Kimai: 266 routes and 26 events with their subscribers; graphify links none. Claude Code wrote the exact class name in 18 of 18 runs with phpgraph, 15 of 18 without (the 3 misses: namespaces guessed from the path).
- **Does the code respect its layers?** `phpgraph check` fails the CI on a new dependency that breaks the layer rules (domain, application, infrastructure) or the project's own rules in `phpgraph.yaml`, with a baseline for today's violations.
- **What crosses the service boundaries?** Shared message classes, routing keys, HTTP routes, OpenAPI and protobuf schemas link services together: all 45 operations of StayHub's OpenAPI document and all 14 rpcs of ticket-booking are linked to their implementation; in art-gallery, phpgraph found a `POST` sent to a `GET`-only route.
- **Who calls this, in a large or old codebase?** Receivers are typed from return types, docblocks, generics, branches and the signatures of `vendor/`, and PHP 4 and 5 syntax is parsed: 92.9 to 99.1 % of method calls typed on the 7 main projects of the corpus. On Kimai, phpgraph finds the callers in 2,407 of the 2,408 files a grep finds for methods whose name is declared once, graphify in 902.

How it answers:

- **Deterministic facts, not guesses.** Every relation is `EXTRACTED` (read in the code), `INFERRED` (resolved from declared types) or `AMBIGUOUS` (a guess, labelled as such).
- **It says what it does not know.** Unresolved calls, missing `vendor/`, messages without handlers: the gaps are reported, so the agent knows when to read the code itself.
- **Built for DDD and hexagonal architecture**, then microservices, then PHP in general: Symfony, Laravel, Ecotone, PrestaShop, WordPress, or plain PHP.
- **Private by design.** Your code never leaves your machine, and phpgraph never executes it: it only parses it.

## What it costs

The same agent with and without phpgraph, scored against answers derived from the code ([method and every run](https://github.com/alpine-ronin/phpgraph/blob/main/docs/BENCHMARK.md)):

| Question | Agent and projects | With phpgraph |
|---|---|---|
| Impact of a change | Sherpa, a private 4,800-file Symfony/DDD project, 3 runs | −42 % tokens, −50 % time, −59 % tool calls |
| Impact of a change | Claude Code, 6 tasks on 5 public projects, 3 runs | −1.9 % tokens in total (+18 % with 2.0); Akeneo −51 % |
| Explain a feature | Sherpa, the same private project, 3 runs | −25 % tokens, −34 % time, −49 % tool calls |
| Explain a feature | Claude Code, 6 tasks on 5 public projects, 3 runs | −12 % tokens, −26 % time, −42 % tool calls |
| Find a handler, a controller | Claude Code, 6 tasks on 5 public projects, 3 runs | −7 % tokens, −14 % time, −27 % tool calls |
| Fix a seeded bug | Claude Code, 6 tasks on 4 public projects, 3 runs | not called; +10 % tokens for carrying the server with 2.0, whose tool list is now 4.8 KB instead of 11.2 KB (not measured again) |

On the Sherpa project, the ranges of the runs with and without phpgraph do not overlap; on the public tasks, the run-to-run variance is about 11 %, so a few percent on one task is noise.

Questions whose answer goes through an indirection (a custom command bus, the container configuration, an HTTP path in another service), 4 tasks run once per arm: Claude Code reached the full answer without phpgraph too, every time. The difference showed on the largest project, in the way there: the controllers of Akeneo reaching `GetCategoryInterface::byCode()` through its command bus cost 182 k tokens and 19 s with phpgraph, 382 k and 33 s without. One run each is a direction, not a measure; on the other three tasks, phpgraph cost more tokens.

## Quick start

**1. Install.** A single executable, no PHP needed (Linux and macOS on x86_64 and ARM, Windows on x86_64):

```bash
curl -fsSL https://raw.githubusercontent.com/alpine-ronin/phpgraph/main/install.sh | sh                  # latest release
curl -fsSL https://raw.githubusercontent.com/alpine-ronin/phpgraph/main/install.sh | sh -s -- 0.2.0     # a given release
curl -fsSL https://raw.githubusercontent.com/alpine-ronin/phpgraph/main/install.sh | sh -s -- dev-main  # the main branch
```

```powershell
irm https://raw.githubusercontent.com/alpine-ronin/phpgraph/main/install.ps1 | iex                       # Windows
$env:PHPGRAPH_VERSION = "dev-main"; irm https://raw.githubusercontent.com/alpine-ronin/phpgraph/main/install.ps1 | iex
```

The script downloads the binary, checks its SHA-256 and puts it in `~/.local/bin` (Windows: `%LOCALAPPDATA%\Programs\phpgraph`, added to the PATH); `PHPGRAPH_INSTALL_DIR` changes the directory. Run it again to update. `dev-main` is rebuilt on every push to `main` (the `edge` pre-release). The binary is a static PHP runtime with phpgraph inside, built with [static-php-cli](https://github.com/crazywhalecc/static-php-cli).

With PHP 8.5+ installed, or with Docker:

```bash
composer require --dev alpine-ronin/phpgraph       # or: composer global require alpine-ronin/phpgraph
curl -LO https://github.com/alpine-ronin/phpgraph/releases/latest/download/phpgraph.phar
docker pull ghcr.io/alpine-ronin/phpgraph          # nothing else to install
```

**2. Connect your agent.** `mcp-config` prints a ready-to-paste configuration with the paths filled in (`vendor/bin/phpgraph` when installed with Composer):

```bash
phpgraph mcp-config sherpa    # Sherpa: a `sherpa mcp add` command, one declaration for every project
phpgraph mcp-config claude    # Claude Code: a `claude mcp add` command, or .mcp.json
phpgraph mcp-config codex     # Codex: ~/.codex/config.toml
phpgraph mcp-config cursor    # Cursor: .cursor/mcp.json, shareable with the team
phpgraph mcp-config json      # any other MCP client over stdio
```

Agents reach for grep first, even with phpgraph connected. `mcp-config` also prints a line for the instructions file of the project (`CLAUDE.md`, `AGENTS.md`) telling the agent to call phpgraph before grep or reading files; the MCP instructions of the server say it too.

With [Sherpa](https://github.com/hbenabdallah/sherpa), a terminal coding agent, one declaration serves every project: Sherpa starts its MCP servers from the project it runs in.

```bash
sherpa mcp add phpgraph -- ~/.local/bin/phpgraph serve
```

In a Sherpa session, `/mcp add` does the same, and the tools are there at once. Both write `~/.config/sherpa/mcp.json`.

With Claude Code (absolute paths, as `mcp-config` prints them):

```bash
claude mcp add phpgraph -- /home/you/.local/bin/phpgraph serve /path/to/project
```

**3. Ask your agent** something like *"Give me an overview of this project"*, *"How does an order get placed?"* or *"What breaks if I change `OrderRepository::save`?"*. The graph is built on the first call, then kept up to date.

## MCP tools

| Tool | Use it to |
|---|---|
| `overview` | Start here: Composer stack, namespace tree with layers, bounded contexts, layer-rule violations, messages, HTTP routes (each with method, path, controller and file up to 30, else grouped by path prefix), services, and what the graph cannot see. |
| `query_graph` | Find the code about a topic when you do not know the class names, by the words of their names (validated finds `Validator`), with the classes of the feature around the ones it names. A question about routes (*"mooc courses routes"*, *"GET /courses"*) returns the matching routes and their controllers. The families of classes around the answer are counted by folder (*ContextRuleInterface: 34 in …*), injected lists once (*ContextValidator receives 34 ContextRuleInterface*). |
| `outline` | Before explaining a feature: its structural outline, enough to read only the few files with non-obvious logic. The classes it is made of, by namespace and layer, with the first sentence of their docblock, their public signatures, constants and enum cases; the interfaces and base classes around it with their implementations by module (0 stated); the routes running into it, chain by chain, through the closures it runs; behaviour read in the bodies (guarded throws, early returns, branches on constants, loop caps, state compared before and after); the container wiring with service ids; its users, tests counted by module, what no test touches and what nothing uses. About 15 KB; a list cut short names the section that has it all (`section behaviour`, 1 to 9 KB), and a core full at 45 classes names those it left out. A class used by many (the `User` model) brings nothing in with it: it shows the methods the feature calls and its attributes naming the feature's classes (`$log: Log`). A method (`OrderService::place`) gives what it runs instead: the methods it calls, 3 calls deep, each class with those methods only; hubs (called by 20 classes or more, a singleton's `getInstance()`) counted, not followed; its behaviour nearest first. |
| `get_node` | Read one class, method, route or channel with all its relations; tests using it are counted by module. |
| `get_neighbors` | See what uses a node (`in`) or what it depends on (`out`). |
| `impact_of` | Before a change: every class that depends on a class or method, nearest first, with the lines of the calls; callers through the interface it implements; code depending on the state it writes; the routes reaching it, with each chain; and the tests to run, found through test helpers. It flags the classes no test touches and those nothing in the application uses. |
| `shortest_path` | See how two pieces of code are connected. |
| `god_nodes` | Find the hubs most of the code depends on. |

The server lists `outline`, `impact_of`, `get_node` and `get_neighbors`: an agent reads the list again on every turn, and in the [benchmark](docs/BENCHMARK.md) it never called the other four. `serve --all-tools` lists them too. A path, as an agent sees it, on [php-ddd-example](https://github.com/CodelyTV/php-ddd-example):

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
phpgraph impact "OrderRepository::save"      # what depends on it, and the tests to run (--all, --section tests, --format json)
phpgraph check                               # layer rules, for CI: exit code 1 on a new violation
phpgraph explain "PlaceOrderHandler"         # a node and its relations (-d in|out)
phpgraph path "StockChecker" "DbalOrderRepository"
phpgraph query "how is stock checked"
phpgraph outline "notification validation"   # the outline of a feature (--section flow, --format json)
phpgraph serve [path]                        # the MCP server, over stdio
phpgraph mcp-config claude|codex|cursor|json [--docker image]
```

`build` options: `-e` to exclude paths (repeatable), `--no-vendor` not to read dependencies, `--no-cache` to parse every file again. `vendor`, `node_modules`, `var`, `.git` and the files ignored by the project's own `.gitignore` files are skipped; a git repository above the project never applies its rules, so a project copied into an ignored directory is still read. When the directory holds PHP files but every one is excluded, `build` fails and says so, and every MCP answer starts with that warning instead of serving an empty graph. Memory is unlimited by default; `PHPGRAPH_MEMORY_LIMIT=2G` sets a limit. The files are parsed by one worker process per processor, with PHP's `pcntl` extension (in the binaries and the Docker image; not on Windows, where they are parsed one after the other): Sylius builds in 10 s instead of 28 s on a 12-thread laptop. `PHPGRAPH_WORKERS=4` sets the number of workers, `PHPGRAPH_WORKERS=1` parses in one process. With PHP installed, opcache and its JIT make builds about 20% faster: `php -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=128M vendor/bin/phpgraph build` (the Docker image and the commands printed by `mcp-config` already use them).

## Features

- **Call graph with type inference**: return types, chained calls (`$a->b()->c()`), local variables, docblocks (`@return`, `@var`), and signatures of your `vendor/` dependencies, so chains go through Doctrine, Symfony or Laravel APIs.
- **Architecture**: DDD and hexagonal layers, bounded contexts, layer rules checked in CI (`phpgraph check`), and impact analysis (`impact_of`): what depends on a class or method, and which tests to run.
- **Messages and events**: sender → message → handler for Symfony Messenger, Laravel jobs and events, Ecotone, PrestaShop CQRS, WordPress hooks, domain events and your own buses, from attributes, service configuration (YAML, XML, PHP) or code shape.
- **HTTP**: routes (Symfony attributes, YAML and PHP routing files, API Platform resources, Laravel route files, controllers named by container service id) linked to controllers, and HTTP calls linked to the routes they reach. A route whose controller class exists neither in the project nor in `vendor/` is reported with its routing file.
- **Dependency injection**: what the Symfony container configuration injects beyond constructor types, every service of a tag (`tagged_iterator`, `#[AutowireIterator]`) or a service named by id, from PHP, YAML or XML configuration, including helpers called with literal arguments (`Wiring::wire($services, 'sales', ...)`, evaluated, never run): a validator is linked to the rules it receives. A rule declaring what it accepts (`supports(object $input): bool { return $input instanceof CreateEstimateQuery; }`, also `canHandle`, `accepts`…) is kept out of the use cases passing another input to the shared pipeline, the classes the input's properties hold included. An input typed by an interface is any project class implementing it and the parameter types it is passed on to (`QueryInterface $query` given to `setUp(CreateEstimateQueryInterface $query)`: a `CreateEstimateQuery`); a guard it cannot read, or a rule running for some of these classes only, is kept, marked `supports() not resolved`.
- **Microservices**: one id space per service, and links between services through their contracts: shared message classes, routing keys, HTTP routes.
- **API schemas**: the operations of OpenAPI (Swagger) documents linked to the routes implementing them, or, for a document of another API, to the HTTP calls of its client; the rpcs of protobuf services linked to the classes implementing their generated interface. What is documented and not implemented, and the routes no document describes, are reported. `impact` lists the operations behind a change (the contract its clients rely on), `outline` those a feature serves.
- **Zero-configuration MCP server**: built on first use, rebuilt incrementally when the code changes (about 3 s per edit on an 8,400-file project).

## What the graph contains

| Relation | From → to |
|---|---|
| `defines`, `imports` | file → class, `use` statement |
| `extends`, `implements`, `uses_trait` | class → parent, interface, trait |
| `has_method`, `overrides` | class → method, method → the method it overrides |
| `instantiates`, `references` | `new Foo()`; parameter, return and property types, `catch`, `instanceof`, constants, attributes |
| `calls` | method → resolved method, with the lines of the call sites; a method running a closure it receives (`$apply(...)`) calls what the closure calls, INFERRED, `via` `closure of <the method writing it>` |
| `dispatches`, `handled_by` | sender → message or channel → handler; route → controller; class tested by a `supports()` method → that strategy |
| `contract` | a message class sent by one service → the same class handled by another |
| `requests` | HTTP call → the route it reaches, in the same service or another, or the operation of another API's OpenAPI document |
| `implemented_by` | API schema operation (`operation:`) → the route with its method and path (OpenAPI), or the method of a class implementing the generated service (protobuf) |
| `receives` | service → each service the container configuration injects into it (a tag, an id, the decorated service); `via` says which: `service sales.pipeline` for one of several services of a class |
| `autowires` | service → an interface or class it takes in more constructor parameters than the configuration gives it services of: the container autowires the others, so `impact` keeps it as a caller whatever it is given, INFERRED |
| `reads_state_of` | method → a method of the same class changing a property it reads, outside the constructor (`hasErrors()` → `add()`), INFERRED |

Every relation carries a confidence level:

| Level | Meaning |
|---|---|
| `EXTRACTED` | Read in the code of one file, or in the configuration (attribute, service tag, route file). |
| `INFERRED` | Resolved across files from declared types: parameters, properties, `$this`, inheritance, return types, `instanceof` tests, shape of the code. A variable that branches leave holding one of several classes links a call to the method of each. |
| `AMBIGUOUS` | A guess, for example the only method of the project with that name, called on a receiver of unknown type; or the method of a class a variable may hold, when another branch leaves it of unknown type. |

<details>
<summary><b>Frameworks and conventions recognised</b></summary>

| | Handlers and listeners | Messages sent | HTTP |
|---|---|---|---|
| **Symfony** | `#[AsMessageHandler]`, `#[AsEventListener]`, tags `messenger.message_handler` and `kernel.event_listener` (YAML, XML, PHP, `_instanceof`), `getSubscribedEvents()`, `addListener()` | `MessageBusInterface`, `EventDispatcherInterface`, named events | `#[Route]` (paths in class constants too), YAML and PHP routing files, controllers named by service id; API Platform `#[ApiResource]` and operation attributes, handled by their provider, processor or controller |
| **Laravel** | `$listen`, `Event::listen()`, subscribers (`$events->listen()` in `subscribe()`, or the map it returns), listeners of a union of events (`handle(Accepted\|Rejected $event)`), jobs (`ShouldQueue`, `Dispatchable`) | `event()`, `dispatch()`, facades, `Job::dispatch()` | `Route::get/post/…`, `match`, `resource`, `view`, `redirect`, groups with `prefix` and `domain`, `routes/api.php`; `Http::` client, relative to its `baseUrl()` |
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

The project's own rules go in `phpgraph.yaml`, checked by the same command, with the same baseline. Patterns are globs on class names (`*` within a namespace segment, `**` across segments), and a namespace ending with `\` is everything in it:

```yaml
rules:
  dependencies:                       # who may depend on what
    - name: doctrine-in-infrastructure
      dependency: 'Doctrine\'
      allow-from: ['App\*\Infrastructure\']  # or deny-from, which wins
      reason: Only infrastructure talks to the database.
  structure:                          # what the classes a pattern selects must be
    - on: 'App\**\Controller\*'
      not-on: 'App\Legacy\**'
      target: class                   # class, interface, trait or enum
      must-be-final: true             # must-be-abstract, must-be-readonly: true or false
      must-be-named: '*Controller'
      must-implement: ['App\Shared\Http\Controller']   # must-extend, must-use-trait
      only-public-methods: [__construct, __invoke]
  handlers:                           # a message handler dispatches the messages of its own bounded context
    own-context: true
    shared: [Shared]                  # contexts any handler may use
```

Dependencies are the certain ones (EXTRACTED and INFERRED: types, attributes, `new`, calls, inheritance) of application code; inheritance is read from the graph, so the parents of a class of `vendor/` are not known. The bounded context of a handler and of a message is read from their namespaces, as for the layers.

</details>

<details>
<summary><b>Microservices</b></summary>

A repository holding two or more independent Composer applications is split into services. Packages of a monorepo stay in their project: a package the root autoloads, declares as a path repository or `replace`s is a part, not a service; a library the services require (a path repository, or its package name) is their common code. Ids then carry their service, `billing@App\Domain\Order`, and a name never resolves into another service: services meet through contracts.

- the same message class sent by one service and handled by another: `contract`;
- a routing key (`sendWithRouting('order.place', …)`, `#[CommandHandler('ticket.create')]`, a named event): a `channel:` node shared by all services;
- an HTTP call to a route of another service: `requests`. A call whose path matches a route declared for other HTTP methods only is reported: it would fail as written;
- an API schema: the `operation:` nodes of an OpenAPI document (`GET /api/hotels/{hotel}`) or of a protobuf service (`CinemaService.Reserve`), `implemented_by` the routes or classes serving them. A document at the root of the repository describes every service (an API gateway), one in a service that service only.

An optional `phpgraph.yaml` names the services when detection does not fit:

```yaml
services:
  - services/billing
  - services/shipping
```

</details>

<details>
<summary><b>Routing files of your own: action codes in XML</b></summary>

Legacy applications often route by an action code the screen sends (`value="saveReservation"`), mapped to a controller method by an XML file of their own. `phpgraph.yaml` describes the format, nothing is hard-coded:

```yaml
actions:
  - file: Framework/jacActions.xml
    element: //action                                 # XPath of the action elements
    code: code                                        # their attributes (these are the defaults)
    controller: controller
    method: method
    controller_file: '{dir}/control/{name}CTRL.php'   # _Stock/Reservation → _Stock/control/ReservationCTRL.php
    target: target                                    # the action or template it goes on to
    forward: { element: forward, result: result, target: target }
    cited_in: [html, js]                              # the screens quoting the codes
```

Each action is a route node, `ACTION saveReservation`, `handled_by` its method (EXTRACTED), and referencing its controller (`via` `controller`) when the method is inherited. Its target and forwards are `references` to the next action or to the template (`_Stock/SO_080/Resa.html` finds `_Stock/views/SO_080/Resa.html`), `via` `target` or `forward KO`. A file of `cited_in` quoting a code (`value="saveReservation"`, `'saveReservation&id='`) references the action, AMBIGUOUS: `impact_of` lists those screens under the route. A code that is a plain word (`default`, `login`) is not looked for, nor are minified files. `overview` counts the actions and lists those whose controller or method is missing; `outline` of a method starts its flow from its action; `query_graph("saveReservation routes")` finds it. `controller_class: '{name}CTRL'` finds the class by name instead of by file.

</details>

<details>
<summary><b>How it works</b></summary>

1. Every PHP file is parsed into facts: declarations, calls with the expression typing their receiver, message sends and handlers, routes, HTTP calls.
2. The builder resolves those facts across files: types through inheritance and return types, `vendor/` signatures read on demand from Composer's metadata (parsed, never executed), service definitions from the container configuration.
3. The graph is saved as `phpgraph-out/graph.json`, with `GRAPH_REPORT.md` for humans.

Rebuilds are incremental: a file's extraction is cached by content, and the MCP server re-resolves only the calls of changed files when no declaration changed anywhere. The result is identical to a full build.

</details>

## Measured on real projects

phpgraph is developed against a corpus of open-source projects pinned to a commit, and every change is judged on their numbers (`composer corpus:measure`). Application code only; *receiver typed* is the share of method calls whose receiver type phpgraph could determine. Builds measured by `corpus:measure`, one project at a time, in Docker (PHP 8.5, no opcache), version 1.5.8.

| Project | Kind | PHP files | Build | Receiver typed |
|---|---|---:|---:|---:|
| [php-ddd-example](https://github.com/CodelyTV/php-ddd-example) | DDD, CQRS | 304 | 0.8 s | 99.1 % |
| [Sylius](https://github.com/Sylius/Sylius) | Symfony e-commerce | 4,946 | 12.7 s | 98.6 % |
| [Akeneo PIM](https://github.com/akeneo/pim-community-dev) | hexagonal, bounded contexts | 8,416 | 18.7 s | 92.9 % |
| [PrestaShop](https://github.com/PrestaShop/PrestaShop) | CQRS and legacy | 7,850 | 20.4 s | 96.5 % |
| [Ecotone quickstart](https://github.com/ecotoneframework/quickstart-examples) | 47 services, messaging | 583 | 1.1 s | 92.7 % |
| [BookStack](https://github.com/BookStackApp/BookStack) | Laravel | 1,513 | 6.6 s | 97.4 % |
| [WordPress](https://github.com/WordPress/WordPress) | no framework, hooks | 1,899 | 9.7 s | 97.7 % |

Four microservices projects complete the corpus: [two Laravel services over RabbitMQ](https://github.com/mostafaaminflakes/Using-RabbitMQ-in-Microservices), linked by 4 message contracts; [four Laravel services over HTTP](https://github.com/omarihab99/Art-Gallery), where phpgraph found a `POST` sent to a `GET`-only route; [Spiral's ticket booking](https://github.com/spiral/ticket-booking), services calling each other through gRPC services declared in `.proto` files; and [StayHub](https://github.com/ahmedibrahimyassindev/StayHub), seven Laravel services behind an API gateway described by an OpenAPI file. The last two measure API schemas as contracts: all 45 operations of StayHub's document and all 14 rpcs of ticket-booking are linked to what implements them. Zero parse failures on about 26,500 files. The details per project, including messages, routes and services, are in [`corpus/RESULTS.md`](https://github.com/alpine-ronin/phpgraph/blob/main/corpus/RESULTS.md).

### With an agent

Measured on a private 4,800-file Symfony/DDD project, 3 runs per setup, the same agent ([Sherpa](https://github.com/hbenabdallah/sherpa)) with and without phpgraph 1.0.2:

| Kind of question | With phpgraph | Quality |
|---|---|---|
| Impact or refactoring (`impact_of`) | −42 % tokens, −50 % time, −59 % tool calls | same or better |
| Explaining a feature (`outline`) | −25 % tokens, −34 % time, −49 % tool calls; −31 % tokens with the default outline | same |

The ranges of the runs with and without phpgraph do not overlap.

On public projects (November 2026): Claude Code (`claude -p`, Sonnet) on 24 tasks across php-ddd-example, Sylius, PrestaShop, BookStack and Akeneo, 3 runs per task with phpgraph 2.0 and without (grep and file reading only), scored against answers derived from the code. Method, per-task results and variance: [`docs/BENCHMARK.md`](https://github.com/alpine-ronin/phpgraph/blob/main/docs/BENCHMARK.md); `tools/agent-bench/bench` runs it again.

| Kind of task | Success with / without | With phpgraph |
|---|---|---|
| Find a handler, a controller | 18/18 / 15/18 | −7 % tokens, −14 % time, −27 % tool calls; the 3 failures without it are guessed namespaces |
| Impact of a signature change | 17/18 / 15/18 | +18 % tokens (`impact_of` says more than needed on a method with many callers); every failure is a guessed namespace |
| Explain a feature | 17/18 / 18/18 | −12 % tokens, −26 % time, −42 % tool calls |
| Fix a seeded bug | 18/18 / 18/18 | phpgraph not called; +10 % tokens for carrying the server |

Since then (2.1), the server lists 4 tools instead of 8 and `impact_of` gives a compact answer past 20 dependents: on the 6 impact tasks again, phpgraph costs −1.9 % tokens in total instead of +18 %, from −51 % on Akeneo to +56 % on PrestaShop's `assertManufacturerExists()` ([details](https://github.com/alpine-ronin/phpgraph/blob/main/docs/BENCHMARK.md#impact-without-the-extra-cost-21)).

### Compared with other code graphs

[Kimai](https://github.com/kimai/kimai) 2.70.2 (Symfony, 1,072 source and 761 test files, not in the corpus phpgraph is measured on), October 2026, without any LLM: phpgraph 1.5.2 and [graphify](https://github.com/Graphify-Labs/graphify) 0.9.93 (`extract --code-only`), on the same machine, in Docker. graphify keeps one edge per caller and callee, so calls between methods of the project are compared by pair; samples of the differences were checked in the code. `tools/graphify/benchmark.sh` runs it again.

| | phpgraph | graphify |
|---|---:|---:|
| Build (median of 6 runs; graphify 2.2 to 2.7 times longer in each) | 11 s | 27 s |
| Caller and callee pairs, methods of the project | 20,195 | 7,615 |
| found by this tool only | 12,936 | 356 |
| Caller files of the methods whose name is declared once (2,408, from grep) | 2,407, no false one | 902, no false one |
| Events linked to their subscribers, dispatches | 26, 135 | 0, 0 |
| Routes linked to their controller | 266 | 0 |

- The pairs found by phpgraph only are resolved from declared types, inherited methods and `instanceof` included: 14 of 14 checked right. graphify misses calls on local variables and chains even when the method name is unique (`$sut->hasUser()`, `$this->getDateTimeFactory()->getStartOfMonth()`).
- Of graphify's 356 pairs, 243 link a call phpgraph links to another method of the same name: phpgraph is right on 11 of 12 checked, graphify on the twelfth (phpgraph follows the declared return type, `AbstractWidgetType`, to the inherited method; the object is a subclass overriding it). The 113 others: 11 of 12 checked wrong, calls on another object linked to the method of the same name in the current class (Doctrine's `$em->rollback()` to `TimesheetRepository::rollback()`); the twelfth is right, a call on a helper without a return type, which phpgraph counts as an unknown receiver.
- phpgraph's one miss among the 2,408 files: `$user->isFirstDayOfWeekSunday()` on what `loadUserByIdentifier()` declares to return, Symfony's `UserInterface`, which has no such method.
- What graphify has and phpgraph has not: other languages (Kimai's JavaScript), communities, an interactive visualisation and documents in the graph.

Sylius (the corpus commit), October 2026, without any LLM: phpgraph 1.0.2, [codebase-memory-mcp](https://github.com/DeusData/codebase-memory-mcp) 0.11.0 and [graphify](https://github.com/safishamsi/graphify) 0.9.76 (`--code-only`), on the same machine, in Docker. The calls were compared site by site (file, line, method), and samples of the differences were checked in the code.

| | phpgraph | codebase-memory-mcp | graphify |
|---|---:|---:|---:|
| Build | 10 s | 17 s | 32 s |
| Call sites linked to a project method | 22,870 | 32,274 | 4,005 |
| of which resolved from types | 22,747 | 4,314 | — |
| Callers of `OrderItemQuantityModifierInterface::modify()` found (17 in the code) | 17, no false one | 11, 2 false | 0, 1 false |
| Messenger handlers linked to their message | 206 | 0 | 0 |
| Container injections, routes | 1,360, 188 | 0, 1 (false) | 0, 0 |

- codebase-memory-mcp agrees with phpgraph on 99.7 % of the calls it resolves from types. The rest of its edges are guessed from the method name (confidence 0.04 to 0.44): where both link the same call, they disagree on 9,400 sites, and its 11,300 extra edges are mostly calls into `vendor/` attached to a project method of the same name (19 of 20 checked).
- graphify links `$this->other->foo()` to `foo()` of the current class: right for `$this->foo()`, wrong for the 10 of 10 differences checked.
- What codebase-memory-mcp has and phpgraph has not: other languages, full-text and vector search, Cypher queries.

## FAQ

**Does phpgraph send my code anywhere?** No. It runs locally, makes no network call and uses no LLM.

**Does it execute my code?** No. It parses PHP files and reads JSON, YAML and XML configuration. Even `autoload_classmap.php` and PHP service configurators are parsed, not included.

**Which PHP versions can it analyse?** Anything `nikic/php-parser` 5 parses: PHP 7 and 8 syntax, and the PHP 4 and 5 syntax removed since (`$s{0}`, `=& new`, octals like `08`), for a file the newest grammar rejects. phpgraph itself needs PHP 8.5 or newer.

**Which agents does it work with?** Any MCP client over stdio: [Sherpa](https://github.com/hbenabdallah/sherpa), Claude Code, Codex, Cursor, Claude Desktop, and others. `mcp-config` prints the configuration for the common ones.

**How large a project can it handle?** The largest project of the corpus, Akeneo, has 8,400 files: about 22 s and 730 MB for a full build (in Docker, without opcache), about 3 s for a rebuild after an edit.

## Limitations

- Only PHP is read: `overview` names the other languages of a mixed repository (their calls to the PHP part are not linked), and the MCP instructions say at once when a project has no PHP at all.
- Calls to global functions are not edges of the graph, though what a function returns types the calls on it (`now()->addDay()`, `response()->json()`); dynamic calls (`$this->$name()`, `__call`) are resolved only through the methods a class documents (`@method`, `@mixin`).
- Docblocks are read by phpstan/phpdoc-parser: a type may be written over several lines, a tag may follow another on the same line of a one-line docblock, and the forms older code writes though PHPDoc does not allow them are read as they mean (`callable(Foo $x)` without a return type, `?A|B`, `@method static NAME(): Type`). Generics are read from docblocks (`@template`, `@extends Repository<Order>`, `@return Collection<int, Item>`, `ScalarNodeDefinition<$this>`), in the project and in `vendor/`: a template is bound by the type of the receiver and the parents passing it on, and a `foreach` walks the elements of any typed collection. A union (`Response|ResponseFactory`) or an intersection (`Countable&Named`), documented or declared, is called on every member declaring the method; a closure passed to a method gets the parameters the method documents it passes (`@param callable($this): mixed`, Laravel's `when(fn ($query) => ...)`); `@use HasFactory<HotelFactory>` on a trait use binds its templates. A template of a method is bound by the arguments of each call: the class an argument names (`getRepository(Order::class)` with `@param class-string<T>`), the type of a value (`@param T $value`), what a closure returns (`@param callable(): T`, its return statements or native type). `@phpstan-return` and `@psalm-return` are read before `@return`; a condition the arguments do not decide (`TAsync is false ? Response : PromiseInterface`) gives the union of its branches. Magic properties documented with `@property` and `@property-read` carry chains on; on an Eloquent model, so do the casts (`'paid_at' => 'datetime'` is a Carbon, an enum, the `TGet` of a cast class), the timestamps and the relations read as properties (`$page->book`, what `book()->getResults()` returns). A method declaring no return type returns what the method it overrides declares (`@inheritDoc`). A `global $wpdb` has the class the function's `@global wpdb $wpdb` gives; without the tag, it stays unknown. Conditions on a type (`$id is array ? Collection : Model`) are not evaluated, and a facade method documented as `mixed` (`DB::transaction()`) stays unknown.
- Local variables follow the branches of a function: where `if`/`elseif`/`else`, `switch`, `match`, `try`/`catch`, a ternary, `??` or a loop meet, a variable holds what any branch reaching that point assigned (one ending in `return`, `throw`, `exit`, `break` or `continue` does not reach it, `null` and scalars add nothing). `instanceof`, `is_a()`, `assert()`, `&&`/`||` and `match (true)` narrow a variable where they hold, a negated test followed by an exit (`if (!$x instanceof Foo) { return; }`) narrows the code after it. A call on such a variable is linked to the method of each class it may hold: INFERRED when every branch typed it, AMBIGUOUS when one left it of unknown type. A call narrows its arguments as its callable documents it, in the project or in `vendor/`: `@phpstan-assert`, `@psalm-assert` and their `-if-true`/`-if-false` forms, a template bound by a `class-string` argument included (WordPress's `is_wp_error()`, `Webmozart\Assert\Assert::isInstanceOf($x, Order::class)`, PHPUnit's `assertInstanceOf()`); only calls named like an assertion are looked up (`is…`, `assert…`, `ensure…`, `check…`, `expect…`, `validate…`, `verify…`, any static method of an `…Assert` or `…Assertion` class). Only variables are narrowed, not properties (`$this->x instanceof Foo`), and a loop body is read once (a type assigned at its end is not seen at its start).
- What a library makes of a call its signatures do not say comes from its provider (`src/Builder/Libraries`): a PSR-11 container asked for a class gives an instance of it (`$container->get(Mailer::class)`), an Eloquent model's attributes have the types of their casts, timestamps and relations.
- Chains stop at magic methods and at dependencies without a usable return type. PHP's own classes (`DateTime`, `ArrayObject`, `SplObjectStorage`, `PDO`...) are known from signatures generated once (`tools/internal-classes.php`); those of the `intl` and `mysqli` extensions are not.
- Without an installed `vendor/`, call chains stop at the first dependency.
- Services built at runtime (compiler passes, bundle extensions, ids computed from values only known at runtime), XML routes (but action files mapped in `phpgraph.yaml`), API Platform resources declared in XML or YAML, and API schemas other than OpenAPI and protobuf (GraphQL, AsyncAPI) are not read; an OpenAPI path rewritten by a gateway is not followed; injections that cannot be linked are listed by `overview`.
- Two services declaring a message class of the same name are assumed to share it.

## Contributing

Issues and pull requests are welcome. See [CONTRIBUTING.md](https://github.com/alpine-ronin/phpgraph/blob/main/CONTRIBUTING.md): `composer check` must pass (php-cs-fixer, PHPStan level max, PHPUnit), and changes to the analysis are judged on the corpus. Without PHP installed, `bin/dev composer check` runs everything in Docker. [docs/ARCHITECTURE.md](https://github.com/alpine-ronin/phpgraph/blob/main/docs/ARCHITECTURE.md) explains how the code is organised, [docs/PROVIDERS.md](https://github.com/alpine-ronin/phpgraph/blob/main/docs/PROVIDERS.md) how to add what a library returns.

What stays stable across versions (commands, MCP tools, the `graph.json` format, the JSON of `impact` and `outline`, with [their schemas](https://github.com/alpine-ronin/phpgraph/tree/main/docs/schemas)) is listed in [docs/STABILITY.md](https://github.com/alpine-ronin/phpgraph/blob/main/docs/STABILITY.md); changes are in the [changelog](https://github.com/alpine-ronin/phpgraph/blob/main/CHANGELOG.md).

Releases are published by pushing a version tag: the [release workflow](https://github.com/alpine-ronin/phpgraph/blob/main/.github/workflows/release.yml) builds the PHAR, the executables for Linux, macOS and Windows (`tools/build-binary.sh`), and the Docker image `ghcr.io/alpine-ronin/phpgraph`.

## License

phpgraph is open source under the [MIT License](https://github.com/alpine-ronin/phpgraph/blob/main/LICENSE): you may use, copy, modify, merge, publish, distribute, sublicense and sell it, for any purpose, as long as the copyright notice and the license text are kept with it.
