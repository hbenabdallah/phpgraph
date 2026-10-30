# Changelog

phpgraph follows [semantic versioning](https://semver.org) from 1.0.0; what stays stable is listed in
[docs/STABILITY.md](docs/STABILITY.md), and changes only in a major version (2.0.0), announced here.

## 2.0.0 (2026-10-31)

### Changed
- **PHP 8.5 or newer** is needed to run phpgraph from Composer or its PHAR (8.2 before): `composer require` no longer
  installs it on PHP 8.2 to 8.4. The binaries and the Docker image bring their own PHP and change nothing for their
  users; a project on an older PHP keeps being analysed, whatever PHP it is written for. Nothing else changes: the
  commands, the MCP tools and `graph.json` are those of 1.6.0, and every graph of the corpus is identical.
- Builds are faster. The files are parsed by one worker process per processor, with PHP's `pcntl` extension (in the
  binaries and the Docker image; not on Windows, where they are parsed one after the other); `PHPGRAPH_WORKERS` sets
  the number of workers, `1` parses in one process. The dependencies' signatures are read without the bodies of their
  methods, a chain of calls is typed once instead of once per call in it, and the extraction cache reads back only
  the files a build reuses. Docker image, 12-thread laptop: Sylius 28 → 10 s, Akeneo 26 → 17 s; with the cache of
  the previous build, Sylius 14 → 8 s. Peak memory +12 to +20 % with workers, −4 to −28 % without.

## 1.6.0 (2026-10-30)

### Added
- Route names: Laravel `->name()` (written before or after the route's call, after the names of its groups,
  `Route::name('admin.')` or `'as'`; `photos.index` for a resource), Symfony `name:` of `#[Route]` (after the class's),
  YAML route keys and `$routes->add()`. The route node carries it as `alias` in `graph.json`; `query_graph` and
  `outline` find a route by its name, and a route named after the question opens the outline through its controller:
  devnull's home feed, which its code calls the log, from `GET /` named `home` (8 classes, 3.8 KB, in place of 20
  classes found by method names, 8.8 KB).
- `outline`: a core full at 45 classes says how many more classes qualified and names the first ones (NextAPI
  `customer order`: 96, all in the `CustomerOrder` context); a class used by many (the `User` model) shows its
  attributes naming a class of the feature (`$log: Log`, `$pollVotes: Collection<int, PollVote>`); two classes of one
  name are told apart in the lists (`Api\LogController`, `Controllers\LogController`).

### Changed
- `outline` keeps closer to the feature asked about:
  - nothing joins through a class used by 20 classes or more (devnull's `User`, used by 520) unless it is of the module
    asked about or named after the question; it shows what the feature calls of it, not its actions nor its wiring,
    and a parent class of that kind stands for none of its subclasses (a controller stays, though its base has 59);
  - a class off the topic joins through a class about the question only, not one DAO after another;
  - a word most classes carry in their namespace (the `Order` of `App\SalesOrder`) is the topic only in a class's own
    name; at the cap, of classes equally linked, the one most about the question joins first;
  - the module of a seed keeps what the seed runs and what its callers run, not the siblings sharing a widget (the
    catalog promotion forms sharing an add button with the shipping method's);
  - a class found only by a method's name (`PageContent::base64ImageUriToUploadedImageUrl()` for `image upload`) is no
    seed when most seeds are about the question or a route is named after it: its method shows as a caller.

  Checked before and after on 15 outlines of 6 projects: devnull polls with all its `Poll*` classes and 250 users in
  place of 859, jac2-web `ReservationCTRL` 30.5 → 22.7 KB, BookStack `image upload` without the page editor; NextAPI
  `notification validation` and php-ddd-example unchanged.

### Fixed
- `outline` no longer runs forever when the classes of a feature share no namespace (devnull.com).
- In an outline, Pest tests count as tests, not as wiring (devnull's outline was 67 KB).

## 1.5.8 (2026-10-27)

### Added
- A global variable has the class its function documents: `global $wpdb;` under `@global wpdb $wpdb` types
  `$wpdb->get_results()`. Without the tag, a global stays unknown.

Corpus, receiver type unknown in application code: WordPress 4.0 → 2.3 % (456 call sites, 945 new INFERRED edges,
650 of them AMBIGUOUS guesses before, all right; a sample checked in the code). No INFERRED edge lost; the 10
other projects do not change.
- What PHP's array functions return is read from their array argument, whose elements their signatures do not
  give (`array` or `mixed`): `array_filter()`, `array_values()`, `array_slice()`, `array_diff*()`, `array_intersect*()`
  or `iterator_to_array()` keep its elements in an array, `array_merge()` and `array_replace()` hold those of every
  argument (a call on them is AMBIGUOUS when one argument's elements are unknown), `reset()`, `end()`, `current()`,
  `array_shift()` or `array_pop()` give one of them. A project function of the same name wins.

Corpus, application code: call sites with an unknown receiver −2 on Sylius, Akeneo and WordPress, −4 on PrestaShop
(+3 to +5 INFERRED on the last three); in tests, −5 on Sylius, −3 on PrestaShop. Two wrong AMBIGUOUS guesses gone on Sylius
(`reset($attributes)->getText()` over Mink's `NodeElement[]`), 5 new edges on PrestaShop, all right. No INFERRED edge
lost on the 11 projects.

### Changed
- phpgraph is now distributed under the MIT License, in place of the PolyForm Shield License 1.0.0: any use is
  allowed, including in a competing product, as long as the copyright notice and the license text are kept.
  Contributions are received under the same license (`CONTRIBUTING.md`); `composer.json` and the Docker image label
  declare `MIT`. Releases up to 1.5.7 stay under PolyForm Shield.
- The French README follows the English one again: API schemas (OpenAPI, protobuf) and `implemented_by`, the rules
  of `phpgraph.yaml`, `supports()` guards in dependency injection, Laravel subscribers, union listeners, `view` routes
  and `baseUrl()`, the shared library of microservices, the ticket-booking and StayHub corpus projects, and the
  current limitations (generics, branch narrowing, library providers, PHP's internal classes). It said PHPStan level 8;
  the project runs level max.
- This changelog's header no longer says any version may change what `docs/STABILITY.md` lists: from 1.0.0 it changes
  only in a major version.

## 1.5.7 (2026-10-23)

### Added
- What a `require` or an `include` returns types the calls on it: `$app = require __DIR__ . '/../bootstrap/app.php';
  $app->make(...)` (the value of the required file's `return`, found by a path written from literals, `__DIR__`,
  `__FILE__` and `dirname()`; a relative path next to the script, then from the project root). A computed path stays
  unknown.
- A container asked to build a class gives an instance of it, `$app->make(Kernel::class)`, as `get(X::class)` already
  did: Laravel documents `make()` as `mixed` before Laravel 10.

Corpus, receiver type unknown in application code: laravel-rabbitmq 9.9 → 5.0 %, art-gallery 9.1 → 6.7 %, Ecotone
8.9 → 7.3 %, BookStack 2.8 → 2.6 % (+30 call sites INFERRED, all checked on a sample; one wrong AMBIGUOUS guess gone).
No INFERRED edge lost on the 11 projects.

## 1.5.6 (2026-10-22)

### Fixed
- `serve` left its client waiting forever once phpgraph was updated while it ran (`install.sh` replacing the
  executable): PHP reads the classes of the PHAR from its file the first time they are needed, so a tool never used
  before read the new file at the old offsets and printed source code in place of its response. The server now
  answers each request with an error asking to restart it.
- A route whose segment mixes a placeholder and text (`/exports/{id}.csv`) made an invalid regular expression (a
  PHP warning on stderr), and no HTTP call reached it.

## 1.5.5 (2026-10-22)

### Fixed
- Method calls written in a script, outside any function (legacy pages, batches, PDF reports, `wp-admin/*.php`), were
  not linked: `$pdf = new Report(); $pdf->render();` gave no edge, only static calls did. The file is now their
  caller, and the script's variables keep their types across a function or a class declared in the middle of it.

On jac2-web, 24,260 more call sites resolved from types and 725 more linked by name, none lost; the callers found of
the methods whose name is declared once go from 88.7 % to 92.9 % (grep: 34,204 caller files). The corpus loses no
call (WordPress +456 INFERRED, PrestaShop +34, Ecotone +27). The unknown-receiver share rises on the small projects
(laravel-rabbitmq-microservices 4.4 → 9.9 %, Ecotone 5.5 → 8.9 %): calls not counted before, mostly on the value of
a `require` (`$app = require 'bootstrap/app.php'; $app->make()`), which is not followed.

## 1.5.4 (2026-10-22)

### Fixed
Calls 1.5 stopped linking in older code (jac2-web, PHP 4 era), against 1.4.3:
- A variable assigned a class in one branch and a subclass in another kept only the class: a method only the subclass
  declares was no longer linked (`Context::getSocieteEnCours()` documented `JacBean`, a `Societe` elsewhere, then
  `isChineseSoc()`). Where branches meet, the subclass stays, as in a documented union: each declaration a call may
  reach gets its edge.
- `@return EmailDraft()` and `@param Client () $client`, a class written as a call, are read as the class (the
  PHPDoc parser of 1.5 rejected them).
- `/* @var $line JacBean */`, a `@var` naming its variable in a plain comment, is read like a docblock.
- `static $bean = null;` no longer makes the variable unknown: it holds what the function assigns it.
- A receiver documented with a class no one declares, in a namespace of the project (`@param unknown $bean`, an IDE
  placeholder), no longer counts as a class outside the project: the method is looked for by its name, as without
  the docblock. Only when every dependency is read.

On jac2-web, 66 of the 91 call edges 1.5.3 lost are back, 1,051 more call sites resolved from types than with 1.5.3
(1.4.3: 226,932; 1.5.3: 241,942; now 242,993). The rest are edges 1.4.3 linked wrongly or whose receiver really is
unknown in one branch. The corpus loses no INFERRED call (WordPress +19, PrestaShop +14, Akeneo +5); PrestaShop's
`/* @var Category $category */` now types three calls on a class it creates at run time, as `/** */` does.

## 1.5.3 (2026-10-21)

### Fixed
- An executable could be missing from a release: when www.php.net could not be reached, static-php-cli took PHP from a
  mirror still serving 8.5.11, and the build refused the runtime after compiling it (the Linux x86_64 executable of
  1.5.2, published by running the job again). The PHP source is now checked before compiling and downloaded again, up
  to three times, while it is older than the minimum.

## 1.5.2 (2026-10-21)

### Fixed
- The Windows executable, missing from 1.5.1: the check of its PHP version compared versions with `sort -V`, and on
  Windows the `sort` found first is System32's, which knows no `-V` and ends its lines with CRLF (it refused PHP 8.5.12
  as older than 8.5.12). Versions are compared in bash now. Its runtime is PHP 8.5.12, as on the other platforms.

## 1.5.1 (2026-10-21)

### Security
- The Docker image and the executables run PHP 8.5.12, which fixes CVE-2026-109618 (GHSA-w8hw-w9fx-j79w, remote code
  execution after an out-of-memory error); 1.5.0 and earlier shipped PHP 8.5.11. phpgraph runs PHP on the command line
  and answers MCP over stdio, not the HTTP request parsing the advisory demonstrates the attack through, and removes the
  memory limit by default, the mitigation the advisory recommends: the risk is low, the runtimes are updated all the
  same. Installed with Composer or as a PHAR, phpgraph runs on your own PHP: update it to 8.2.35, 8.3.36, 8.4.27 or
  8.5.12.
- Building the image or an executable fails when its PHP is older than the minimum the build sets (`PHP_MINIMUM` in
  the `Dockerfile` and in `tools/build-binary.sh`, raised with each PHP security release): an outdated base image or a
  cached download can no longer ship.

## 1.5.0 (2026-10-21)

### Added
- Local variables follow the branches: after `if`/`elseif`/`else`, `switch`, `match`, `try`/`catch`, a ternary, `??`
  or a loop, a variable holds what any branch reaching that point assigned, instead of the last assignment read.
  `instanceof`, `is_a()`, `assert()`, `&&`/`||` and `match (true)` narrow it where they hold; a negated test followed
  by `return`, `throw` or `continue` narrows the code after it. A call on it is linked to the method of each class it
  may hold, INFERRED when every branch typed it, AMBIGUOUS when one left it of unknown type.
- A send whose message branches build one way or another (`$command = $unofficial ? new AddUnofficialCurrencyCommand()
  : new AddCurrencyCommand()`) dispatches each of them; AMBIGUOUS when a branch leaves the message unknown.
- Native and documented intersections (`Countable&Named`, `@var OrderInterface&MockObject`) are called on each member
  declaring the method, instead of being dropped (native) or reduced to their first class (documented).
- Fixtures with inline expectations, `tests/Fixtures/expectations/*.php`: `// @phpgraph-expect calls App\Foo::bar
  INFERRED` on the line of each call; a missing or an unexpected call fails, `@phpgraph-todo` marks a known gap.
- A call narrows its arguments as its callable's docblock asserts, in the project or its dependencies:
  `@phpstan-assert`, `@psalm-assert`, `-if-true`, `-if-false`, a template bound by a `class-string` argument
  (`is_wp_error($result)`, `Assert::isInstanceOf($order, Order::class)`, `$this->assertInstanceOf(Order::class, $order)`).
- Library providers (`src/Builder/Libraries`): a PSR-11 container's `get(Mailer::class)` is a Mailer. Eloquent's
  attributes moved there from `TypeResolver`, unchanged.
- `phpgraph check` checks the project's own rules, `rules` in `phpgraph.yaml`, with the same baseline: who may depend
  on what (`dependency: Doctrine\`, `allow-from`, `deny-from`), what the classes a pattern selects must be (final,
  abstract, readonly, named, implementing, extending, using a trait, only some public methods), and that a message
  handler dispatches the messages of its own bounded context only (`handlers: {own-context: true, shared: [Shared]}`).
- Nodes of `graph.json` carry their `modifiers`: `final`, `abstract`, `readonly` on a class; `protected`, `private`,
  `static`, `abstract`, `final` on a method.

### Changed
- Docblocks are read by `phpstan/phpdoc-parser` (a new dependency) instead of regular expressions: types over several
  lines, unions with spaces (`Token | Exception`), callables in unions (`(callable(TValue): float|int)|null`), by-reference
  and variadic `@param`, nested conditional returns. A `@param` mentioned in a description no longer counts. Compared
  with the regular expressions on five projects and their `vendor/` (about 105,000 docblocks): 2,696 facts gained,
  154 lost, all checked: words of descriptions taken for classes (`and`, `annotations`, `empty`), a callable of an
  array element taken for the parameter's, a conditional whose branches name no class (the plain `@return` is read).
  A docblock of thousands of tags (an SDK client's `@method` list) is parsed tag by tag, the parser being quadratic
  on it.
- A call on a union whose members declare different methods (`Cat|Dog` calling `sleep()`) is linked to each of them,
  INFERRED; it was left unresolved. A chain goes on with the union of what they return.

### Fixed
- A method passing its parameter on to a bus is plumbing even when nothing calls it: its send is not counted untyped.
- An inline `@var` naming a union or an intersection without any single class typed nothing.
- A parameter without a native type is typed by a `@param` naming a single class (`@param WP_Error|null $error`); it
  was only with arguments or as a union.

Corpus, call sites compared one by one with 1.4.3 (INFERRED / AMBIGUOUS / unknown receiver): Sylius +299 / −2 /
−2,408, Akeneo +1,183 / −85 / −1,110, PrestaShop +642 / −79 / −652, WordPress +1,097 / −455 / −635, BookStack
+86 / −50 / −99. No call site lost an INFERRED edge except to a more precise one (an `instanceof` narrowing
`ValueInterface::getData()` to `OptionValue::getData()`), to AMBIGUOUS where another branch leaves the receiver
unknown, or where the edge was wrong (the last assignment of an `if` read in its `else`). Builds take 5 to 24 % longer
(Sylius +5 %, PrestaShop and BookStack +14 %, Akeneo +15 %, WordPress +24 %, each measured beside 1.4.3) and 3 to 10 %
more memory.

## 1.4.3 (2026-10-17)

### Fixed
- `impact_of` of a message handler reaches what sends its message: changing `ImportJob::handle()` or
  `PlaceOrderHandler::__invoke()` affects the code dispatching them, and the routes above it. Not the message class,
  nor the code only building it.
- `outline` of a method keeps a guess a guess: a call linked by a method name declared once (AMBIGUOUS, an untyped
  receiver) is listed at a glance as such, marked `guessed:` in the flow, and a class reached only through guesses is
  marked `guessed` in the core. JSON: `guesses`, `guessed`.
- HTTP calls are relative to the base URL of their client (Laravel's `Http::baseUrl()`, in the chain or in the method
  returning the client, from a string or a property holding one): a call to `https://api.vendor.example/v2` +
  `/labels` is a call to `/v2/labels` on that host. A route of a `Route::domain()` group is no longer reached by a call
  to another host, and is reached AMBIGUOUS by a call whose host is unknown.
- A wrapper of the bus is a method passing one of its parameters on to a send (`dispatch(object $command)`,
  `dispatch(string $event, ...)` naming the event); a method sending a message of its own is not (`Invoice::send()`
  dispatching `SendInvoiceJob($this, $config)`, a handler called directly that dispatches its event): its callers no
  longer send what they pass it. Corpus: 19 such false `dispatches` gone on Akeneo, 3 on WordPress, none lost.
- Laravel subscribers: `$events->listen(Event::class, $this->onEvent(...))` or `[$this, 'onEvent']` on the events
  dispatcher (untyped in `subscribe($events)` too), and the event => method map `subscribe()` may return, link the
  event to its method. First-class callables are read wherever a listener is registered.
- A listener of a union of events, `handle(Accepted|Rejected|Sent $event)`, handles each of them (Sylius: 3 events of `CatalogPromotionStateChangedListener`).
- `Route::view()` and `Route::redirect()` are routes, handled by the framework.

## 1.4.2 (2026-10-14)

### Fixed
- `impact_of`, and the routes and flow of `outline`: an action whose method is inherited from a base controller is
  reached by that method only, no longer by every method of the controller it names (the `references` edge of
  `via: controller` names whose action it is, it carries no change). On a legacy project, 13 actions of 2,515.
- `outline` of a method called only from its own class (a private helper, a DAO's inner step) no longer calls it an
  entry point: it names its callers, `X::m() is called only from its own class: a(), b()`.

## 1.4.1 (2026-10-14)

### Added
- `outline` of a class declaring 20 methods or more (a controller of fifty actions) opens with a line pointing to the
  outline of one of its methods, and names its entry points: the routed ones first, then those called by the most
  classes (not its singleton's accessor nor its constructor). JSON: `large`.

## 1.4.0 (2026-10-13)

### Added
- Routing files of the project's own making, declared in `phpgraph.yaml` (`actions:`): XML elements mapping an action
  code to a controller method (`controller_file: '{dir}/control/{name}CTRL.php'`). Each action is a route node
  `ACTION <code>` handled by its method; its target and forwards reference the next action or the template; the files
  of `cited_in` quoting a code reference its action (AMBIGUOUS; not a code that is a plain word, nor a minified
  file), and `impact_of` lists them under the route.
  `overview` counts the actions and lists those whose controller or method is missing. On a legacy project of 2,533
  actions: 2,423 handled by a method, 1,680 citations from 744 screens (sampled: no false one; 16 in 20 send the
  action, the others react to it), templates resolved without error on 35 samples.

### Changed
- `outline` of a method (`ReservationCTRL::saveNewReservation`) is what the method runs: the methods it calls, 3 calls
  deep, each class with those methods only (a parent class shows the inherited methods called, not its 60 others).
  A method called by 20 classes or more, or a singleton's `getInstance()`, is a hub: counted, not followed, and its
  class joins only through another method. Behaviour and wiring keep what touches those methods, nearest first. On a
  legacy project of 4,800 files: 40 classes and 37.6 KB before, 16 classes and 7.4 KB after. A class name or free words give the
  outline of a feature as before, with the same rules: a class named exactly, case included, is the only seed
  (`ReservationCTRL`, not every `*CTRL`; `promotion` stays a topic, `Promotion` is the class), and stays in the core
  though its parent has a family; its parent class, when shared by 20 classes or more,
  shows only the methods the core calls, and the actions of other controllers it handles are left out; a call to a
  hub links no class to the core. `outline "ReservationCTRL"`: 38.6 KB with 6 seeds before, 26.4 KB after.

### Fixed
- `outline` of a feature with many large classes printed a PHP warning and lost the hints of the code running into
  it: the regular expression of every method name exceeded what PCRE compiles.

## 1.3.4 (2026-10-13)

### Fixed
- A response the MCP server could not encode as JSON was written as an empty line, and the client waited for it
  forever: `outline` on a project whose sources are in ISO-8859-1. Invalid UTF-8 is now replaced, and a response that
  cannot be encoded anyway is an error with its id.
- Sources that are not valid UTF-8 are read as Windows-1252 and converted before parsing: docblocks and strings of a
  legacy ISO-8859-1 project show their accents in the MCP tools, the CLI and `GRAPH_REPORT.md`. Graphs and caches built
  by an earlier version are rebuilt on their own.

## 1.3.3 (2026-10-13)

### Changed
- The MCP instructions tell the agent to use phpgraph before grep, glob or reading files, and the descriptions of
  `outline` and `impact_of` to call them instead of grep. `mcp-config` prints a line for `CLAUDE.md` or `AGENTS.md`
  saying the same (not for Sherpa, which reads no instructions file).

## 1.3.2 (2026-10-13)

### Added
- Legacy PHP 4 and 5 code is analysed: a file the newest grammar rejects is parsed again for PHP 5.6, which reads
  `$s{0}`, `$this->x =& new Foo()` and octals like `08` on the original source, lines unchanged. Modern code is never
  read by the old grammar, and a file neither reads keeps the error of the newest. On a legacy project of 4,800 files,
  parse failures went from 366 to 3 (real errors in the source).
- Each release tag publishes the version to the official MCP registry (`release.yml`, logged in by the OIDC token of
  the workflow, no secret).

### Changed
- `mcp-config sherpa` prints a `sherpa mcp add` command, then the `~/.config/sherpa/mcp.json` form; the README declares
  phpgraph in Sherpa the same way.

## 1.3.1 (2026-10-12)

### Added
- Listed in the official MCP registry as `io.github.hbenabdallah/phpgraph`: `server.json` describes the Docker image,
  which carries the `io.modelcontextprotocol.server.name` label the registry checks.

## 1.3.0 (2026-10-11)

### Added
- Unions are typed: a call on `Response|ResponseFactory` (a `@return`, `@var` or `@param`, or a union declared in the
  code, `JsonResponse|AuthenticatedIdentity`) resolves to the member declaring the method, only when one member does
  (or they all inherit the same one). Scalars are left out, `mixed` leaves the union unknown, and a template of the
  method is set aside (`$this|TWhenReturnType` is the receiver). A single class declared in the code wins over a
  documented union.
- An untyped parameter of a closure passed to a method is typed by what the method documents it passes:
  `@param (callable($this, mixed): mixed) $callback` makes `$query` in `->when($city, fn ($query) => $query->where())`
  the receiver; by position or by named argument.
- `/** @use HasFactory<HotelFactory> */` on a trait use binds the templates of the trait: `Hotel::factory()->count()`.
- The templates of a method or a function are bound by the arguments of each call: the class an argument names
  (`@param class-string<T>`: `$em->getRepository(Order::class)->find($id)`, or a string, `_get_list_table('WP_Posts_List_Table')`),
  the type of a value (`@param T $value`), what a closure returns (`@param callable(): T`: its return statements, its
  native return type, the expression of an arrow function). Unbound, the class of `@return` stays.
- `@phpstan-return` and `@psalm-return` are read before `@return` (`Collection::getValues()` is a `list<T>`); a
  condition the arguments do not decide, `(TAsync is false ? Response : PromiseInterface)`, is the union of its branches.
- A method declaring no return type returns what the method it overrides declares (`@inheritDoc`, `{@inheritdoc}`).
- `static|mixed` is the receiver: Eloquent's `scopes()` carries the chain on.
- Magic properties documented with `@property` and `@property-read` carry chains on. On an Eloquent model, so do the
  casts (`$casts` or `casts()`: dates are Carbon, `X::class` the enum or value object, the `TGet` of a cast class,
  Eloquent's castables), the timestamps, and the relations read as properties (`$page->book`: what `book()->getResults()`
  returns).
- `impact` lists the API operations behind a change: the OpenAPI operations its routes implement, the gRPC methods it
  reaches (section `operations`, in the text and in JSON). `outline` names the operations of the routes it shows, and the
  rpcs its classes implement; it now shows the routes handled by a class of the feature, and the routes of a service in
  a repository of several.
- With the templates of methods, `@phpstan-return`, inherited return types and Eloquent attributes, application calls
  with an unknown receiver: PrestaShop 8.2% → 3.9%, BookStack 5.4% → 3.8%, Akeneo 10.0% → 9.6%, Sylius 3.4% → 3.1%,
  StayHub 1.2% → 0.7%. No INFERRED edge lost on the corpus but for WordPress's `WP_List_Table` calls, now linked to the
  subclass the string names; the AMBIGUOUS ones gone were wrong guesses (Mink's `getText()`) or became INFERRED.
- Application calls with an unknown receiver: StayHub 12.7% → 1.2%, art-gallery 20.8% → 6.5%, Akeneo 11.3% → 10.0%,
  WordPress 7.2% → 6.4%. No INFERRED edge lost on the corpus; the AMBIGUOUS ones gone were wrong guesses (Mockery's
  `with()` taken for a project method) or became INFERRED.

## 1.2.0 (2026-10-10)

### Added
- API schemas, read as files, never run: the operations of OpenAPI (Swagger) documents in YAML or JSON, and the rpcs
  of protobuf services, are `operation:` nodes. An operation is `implemented_by` the route with its method and path
  (placeholders match whatever their names; the server's or base path is the prefix), or by the methods of the
  classes implementing the interface generated for its service (generated clients left out; the interface method,
  whose callers are the clients, is referenced). A document at the root describes every service (an API gateway),
  one in a service that service only. A document no route implements describes another API: the HTTP calls of its
  client are linked to its operations (`requests`). `overview` reports what is documented and not implemented, and
  the routes no document describes. StayHub: 45 of 45 operations linked; ticket-booking: 14 of 14 rpcs.
- HTTP calls whose path is built by `sprintf('/apps/%s/challenge', $id)` are read (`/apps/*/challenge`).
- What a function returns types the calls on it: the project's functions (`wp_get_theme()->exists()`), and the
  helpers of the packages, found in their autoloaded files and read, never run (`now()`, `response()->json()`). A
  conditional `@return` is decided by the arguments of the call: `response()` without content is the factory,
  `app(Mailer::class)` a Mailer, a variable argument leaves it unknown. WordPress: 469 more calls resolved; StayHub:
  29% → 13% of application calls with an unknown receiver.
- Magic methods documented with `@method` and classes named by `@mixin` carry call chains on: Laravel facades
  (`Http::timeout()->get()`, `Activity::add()` reaching the project's `ActivityLogger`), Eloquent's builder forwarding
  `orderBy()` to the query builder. BookStack: 11.5% → 5.4% of application calls with an unknown receiver, together
  with the functions.

### Fixed
- A library the services of a repository require (a path repository, or its package name) is their common code, not
  a service: its classes are found from every service. ticket-booking: 34% of application calls resolved instead of
  18%, its gRPC services and their implementations linked.
- Laravel resource routes have the paths Laravel gives them: `Route::apiResource('hotels.rooms', ...)` is
  `/hotels/{hotel}/rooms/{room}` (it was `/hotels.rooms/{id}`), a parameter named after its resource
  (`/photos/{photo}`).

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

## 1.0.7 (2026-10-10)

### Changed
- `query_graph` shows the method linking a message to the class handling or sending it, and a route to its
  controller, when both classes are in the answer.

## 1.0.6 (2026-10-10)

### Changed
- `query_graph` answers are shorter: a method sits on its class's line, the edges of one source and relation share a
  line, and a line says when the node limit is reached.

### Fixed
- `query_graph "all routes"` lists every route: the word was taken for a topic.

## 1.0.5 (2026-10-09)

### Fixed
- `serve` keeps running when its standard input is a socket that times out, as Claude Code opens it.
- `tools/build-binary.sh` runs its Alpine container on the host network, which a local DNS resolver needs.

## 1.0.4 (2026-10-06)

### Changed
- `impact`: the classes a guarded rule accepts are narrowed by the types of the parameters the use case passes its
  input on to (`setUp(CreateEstimateQueryInterface $query)`), within a closure too.

## 1.0.3 (2026-10-06)

### Changed
- `impact`: the rules of a tagged collection are filtered by their `supports()` guard (`instanceof`, `||` of
  `instanceof`, `return true`), against what the use case passes them: a rule that never runs for a use case no
  longer reaches its routes. A guard not read is marked `supports() not resolved`.
- `impact`: a route's chain goes through the rules of its own bounded context.

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
