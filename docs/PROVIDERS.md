# Library providers

phpgraph types a method call's receiver from what the code declares: native types, `@return`, `@var`, generics,
the signatures of `vendor/`. Some libraries make types exist that no signature carries: a PSR-11 container's
`get(Mailer::class)` returns `mixed`, an Eloquent model's attributes are no declared property. A **provider** holds
that knowledge for one library, so the type resolver itself knows none.

Providers live in `src/Builder/Libraries/`. They are an internal extension point: added by pull request, not a
public API, so they are not listed in [STABILITY.md](STABILITY.md) and their interfaces may change in a minor
version.

## The two kinds

| Interface | Asked for | Example |
|---|---|---|
| `MethodReturnProvider` | what a method returns to one call, **before** its declared return type | `PsrContainer`: `$container->get(Mailer::class)` is a `Mailer`; `ServiceIds`: `$container->get('app.mailer')` is the class the project's configuration defines for that id |
| `PropertyProvider` | the type of a property no class declares | `Eloquent`: a model's casts, timestamps, a relation read as a property |

`Libraries` is the registry: `Libraries::defaults()` lists the providers in use, and `TypeResolver` asks it
(`TypeResolver::memberType()`: `methodReturn()` for a call, `propertyType()` for a property `findProperty()` does not find). The first provider with an
answer wins; `null` means "nothing to say" and the resolver goes on as if no provider existed.

A `MethodReturnProvider` declares the lowercase names of the methods it may type (`methods()`); the others are
never asked, so a provider costs nothing on the calls it does not know.

Not every library fact is a return type. PrestaShop's `Context` is a class the autoloader declares at run time
(`class Context extends ContextCore {}`): `PrestaShopClasses` is a name alias registered on `NameCanonicalizer`
by `GraphBuilder`, not a provider of the resolver. Pick the place where the fact belongs: a type of a call or a
property here, a name in `NameCanonicalizer`, a relation in the builder.

## What a provider receives

```php
public function returnType(string $class, string $method, TypeExpr $call, TypeResolver $types): ?string;
```

- `$class`: the class the method is called on, resolved (`Psr\Container\ContainerInterface`, or the project's
  container class).
- `$method`: the method found, `Class::name`, maybe declared by a parent.
- `$call`: the call as the extractor saw it (`Extractor/TypeExpr`). `$call->arguments` holds what it passes, by
  position, as strings: `class:App\Mailer` for `Mailer::class`, `id:app.mailer` for a literal service id (set by
  `ExtractionVisitor::serviceId`), `null` when nothing literal; `$call->argumentTypes` the types of the arguments.
- `$types`: the resolver, for what a provider may ask of the project and of `vendor/`: `isA($class, $parent)`,
  `lineage($class)`, `bindings($type, $class)` (template arguments), `findMethod($class, $name)`,
  `resolve($typeExpr)`.

It returns a GenericType string (`App\Mailer`, `Illuminate\Support\Collection<int,App\Item>`) or `null`. Names are
canonicalized by the resolver afterwards.

A provider never reads files nor runs code of the analysed project. What it needs from the project is read before
resolution and passed to its constructor: `Eloquent` receives the casts the extractor recorded (`Generics::casts`),
`ServiceIds` the container definitions (`ContainerServices`). If the extractor does not record what you need yet,
add it there first (a field of `FileExtraction`, listed in `CachingExtractor::ALLOWED_CLASSES` when it is an object,
and in `BuildState::signature` so incremental builds follow it).

## Writing one

1. Check the library by its types, not by its name in `composer.json`: `$types->isA($class, 'Psr\Container\ContainerInterface')`
   works on any container implementing PSR-11, the project's own included.
2. Return `null` whenever the call is not certain to be the case you know. A provider's answer becomes an INFERRED
   edge: a wrong one is worse than an unknown receiver.
3. Register it in `Libraries::defaults()`.
4. If its answer depends on something other than the PHP files (configuration, `composer.json`), fold that into a
   fingerprint the incremental build compares: `ContainerServices::fingerprint()` is part of the names fingerprint
   in `GraphBuilder`, so a changed service definition resolves the calls again.

The smallest example, `PsrContainer`:

```php
final class PsrContainer implements MethodReturnProvider
{
    private const CONTAINER = 'Psr\\Container\\ContainerInterface';

    public function methods(): array
    {
        return ['get', 'make', 'makewith'];
    }

    public function returnType(string $class, string $method, TypeExpr $call, TypeResolver $types): ?string
    {
        $argument = $call->arguments[0] ?? '';

        return str_starts_with($argument, 'class:') && $types->isA($class, self::CONTAINER) ? substr($argument, 6) : null;
    }
}
```

## Testing it

One test class per provider, next to the others in `tests/`, with the `TemporaryProject` trait: the sources are
written inline, the project is built for real, and the test reads the edges. Declare the library's types in the
sources (an interface is enough), so the test needs no `vendor/`. Test both sides: the call it types, and the
calls it must leave alone. From `tests/ServiceIdsTest.php`:

```php
private const SOURCES = [
    'src/Psr/ContainerInterface.php' => 'namespace Psr\Container; interface ContainerInterface { public function get(string $id): mixed; }',
    'src/Mailer.php' => 'namespace App; class Mailer { public function send(): void {} }',
    'src/Bag.php' => 'namespace App; class Bag { public function get(string $key): mixed { return null; } }',
    'src/Newsletter.php' => 'namespace App; use Psr\Container\ContainerInterface; class Newsletter {'
        . ' public function direct(ContainerInterface $container): void { $container->get("app.mailer")->send(); }'
        . ' public function bag(Bag $bag): void { $bag->get("app.mailer")->send(); } }',
    'config/services.yaml' => "services:\n    app.mailer:\n        class: App\\Mailer\n",
];

public function testAServiceIdGivesTheClassTheConfigurationDefines(): void
{
    $graph = $this->buildProject(self::SOURCES)->graph;

    self::assertTrue($this->hasEdge($graph, 'App\Newsletter::direct', 'App\Mailer::send', Relation::Calls, Confidence::Inferred), 'app.mailer is defined as App\Mailer');
    self::assertFalse($this->hasEdge($graph, 'App\Newsletter::bag', 'App\Mailer::send', Relation::Calls, Confidence::Inferred), 'a Bag is no container: its key names no service');
}
```

When the provider depends on a fingerprint, add a case to `tests/IncrementalBuildTest.php`: change only the input
the provider reads and check the incremental build gives the same graph as a full one.

`composer check` (or `bin/dev composer check` without PHP) must stay green.

## Measuring it on the corpus

A provider is kept only if the [measurement corpus](../CONTRIBUTING.md#measurement-corpus) shows it pays, and loses
nothing:

1. Before the change: `composer corpus:measure` (or `php tools/corpus.php measure <name>` for the projects using
   the library). Keep `corpus/RESULTS.md`.
2. Build the graphs of those projects before and after, outside the checkout:

   ```bash
   bin/phpgraph build corpus/checkouts/<project> -o /tmp/before   # then again, with the provider, into /tmp/after
   jq -r '.edges[] | select(.relation == "calls" and .confidence == "INFERRED") | .source + " -> " + .target' \
     /tmp/before/graph.json | sort > before.txt                    # same for after.txt
   comm -23 before.txt after.txt | wc -l                           # INFERRED edges lost: must be 0, or each explained
   comm -13 before.txt after.txt | shuf -n 10                      # a sample of the new ones, to check in the code
   ```

3. After the change: `composer corpus:measure` again. The main indicator is the share of "Unknown receiver" calls
   in application code (first table of `RESULTS.md`); every other project must be unchanged (build time aside: it
   varies by about ×2 between runs).
4. If it is an intended improvement: `php tools/corpus.php measure --baseline <name>`, and give the numbers in the
   pull request and the changelog (calls resolved before → after, INFERRED edges lost, what the sample showed).

Before writing a provider, find what it would fix: group the corpus' unknown receivers by the library producing
them. A library behind a few calls is not worth a provider. A chain that stays inside `vendor/` adds no edge of the
project either: resolving it only moves calls from "unknown" to "outside the project" (Symfony's
`ArrayNodeDefinition::prototype()` was left out for that reason).
