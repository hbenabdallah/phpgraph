<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Edge;
use PhpGraph\Graph\Graph;
use PhpGraph\Graph\NodeKind;
use PhpGraph\Graph\Relation;

/**
 * What the container configuration injects into a service, beyond the types of its constructor: every service of a
 * tag (`tagged_iterator('app.rule')` in PHP, `!tagged_iterator` in YAML, `#[AutowireIterator]`), or a service named
 * by id (`service('app.mailer')`, `'@app.mailer'`). Each becomes `receives`, EXTRACTED: read in the configuration.
 *
 * A validator receiving its rules through a tag has no other link to them: the code only knows their interface.
 */
final class ServiceInjections
{
    private const MAX_UNLINKED = 8;

    /**
     * How far, and to how many services, a tagged member is followed up the services holding it.
     */
    private const MAX_DEPTH = 6;

    private const MAX_HOLDERS = 50;

    public function __construct(
        private readonly Graph $graph,
        private readonly NameCanonicalizer $names,
        private readonly ContainerServices $container,
        private readonly TaggedClasses $tagged,
        private readonly ?InputGuards $guards = null,
    ) {
    }

    /**
     * Counts the injections into application code only (test code is counted apart everywhere): those linked, and
     * those naming a service the project's configuration does not define, a framework or bundle service most of the
     * time (`request_stack`), sometimes an id computed at runtime. Ids that look like the project's own come first in
     * the examples: they are the likely gaps.
     */
    public function resolve(): InjectionStats
    {
        $injections = $linked = $edges = 0;
        $outside = [];
        $seen = [];
        $prefixes = $this->container->idPrefixes();
        foreach ($this->container->arguments() as $argument) {
            $key = $argument['service'] . '|' . $argument['id'] . '|' . $argument['tag'] . '|' . $argument['target'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $consumer = $this->classOf($argument['id'], $argument['service']);
            // Injections into a dependency's services (a bundle definition overridden by the project) are not the project's.
            if ($consumer === null) {
                continue;
            }

            $targets = $argument['tag'] !== null
                ? $this->tagged->named($argument['tag'])
                : array_filter([$this->classOf((string) $argument['target'], $argument['service'])]);
            $via = $argument['tag'] !== null ? ($argument['locator'] ? 'tagged_locator ' : 'tagged_iterator ') . $argument['tag'] : '';
            // A service named by an id rather than its class says which of several services of one class it is: the
            // pipeline of one use case among those built by a helper (`sales_order.estimate.validation_pipeline`).
            // Not when the consumer is such a service itself: its class serves every id, the edge only one.
            $strip = static fn (string $class): string => preg_replace('/^[^\\\\@]+@/', '', $class) ?? $class;
            if ($via === '' && $targets !== [] && strcasecmp(ltrim($argument['id'], '\\'), $strip($consumer)) === 0 && strcasecmp(ltrim((string) $argument['target'], '\\'), $strip((string) reset($targets))) !== 0) {
                $via = 'service ' . $argument['target'];
            }
            foreach ($targets as $target) {
                if ($target !== $consumer && !$this->hasEdge($consumer, $target)) {
                    $this->graph->addEdge(new Edge($consumer, $target, Relation::Receives, Confidence::Extracted, '', $via));
                    ++$edges;
                }
            }

            $file = $this->graph->node($consumer)?->file;
            if ($file !== null && TestFiles::isTest($file)) {
                continue;
            }
            ++$injections;
            if ($targets !== []) {
                ++$linked;
                continue;
            }
            $name = (string) ($argument['tag'] ?? $argument['target']);
            $outside[] = [
                isset($prefixes[strtolower(explode('.', $name)[0])]) ? 0 : 1,
                \sprintf('%s %s into %s', $argument['tag'] !== null ? 'tag' : 'service', $name, $argument['id']),
            ];
        }
        usort($outside, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $edges += $this->transitive();

        return new InjectionStats($injections, $linked, $edges, \count($outside), \array_slice(array_column($outside, 1), 0, self::MAX_UNLINKED));
    }

    /**
     * The services holding a tagged member through other services: a use case receives its pipeline by id, the
     * pipeline its validator, the validator the rules of a tag. Each such holder `receives` the member, INFERRED, so
     * `impact` keeps the rules of one bounded context to the use cases wired to them. Only injections by id are
     * followed: autowiring by type and locators of a whole tag do not narrow anything.
     *
     * A member whose guard rejects what a use case passes (`supports()` testing another query) is not held by that use
     * case; one whose guard or input cannot be read is held, marked unresolved.
     *
     * @return int edges added
     */
    private function transitive(): int
    {
        // service id => the ids injecting it by id, in each application service.
        $injectedBy = [];
        foreach ($this->container->arguments() as $argument) {
            if ($argument['target'] !== null) {
                $injectedBy[$argument['service']][$argument['target']][$argument['id']] = true;
            }
        }

        $edges = 0;
        foreach ($this->container->arguments() as $argument) {
            if ($argument['tag'] === null || $argument['locator']) {
                continue;
            }
            $service = $argument['service'];
            /** @var array<string, string> $holders holder id => the id it injects on the way */
            $holders = [];
            for ($queue = [$argument['id']], $depth = 0; $queue !== [] && $depth < self::MAX_DEPTH; ++$depth) {
                $next = [];
                foreach ($queue as $id) {
                    foreach (array_keys($injectedBy[$service][$id] ?? []) as $holder) {
                        if (!isset($holders[$holder]) && \count($holders) < self::MAX_HOLDERS) {
                            $holders[(string) $holder] = $id;
                            $next[] = (string) $holder;
                        }
                    }
                }
                $queue = $next;
            }
            $members = $holders === [] ? [] : $this->tagged->named($argument['tag']);
            foreach ($members as $member) {
                $decisions = $this->decisions($member, $holders, $injectedBy[$service] ?? [], $service);
                foreach ($holders as $holder => $injected) {
                    $class = $this->classOf((string) $holder, $service);
                    $decision = $decisions[$holder] ?? InputGuards::KEEP;
                    if ($class === null || $member === $class || $decision === InputGuards::DROP || $this->hasEdge($class, $member)) {
                        continue;
                    }
                    $via = 'through ' . $argument['id'];
                    if ($decision === InputGuards::UNRESOLVED) {
                        $via .= ', ' . ($this->guards?->guardOf($member)['method'] ?? 'guard') . '() not resolved';
                    }
                    $this->graph->addEdge(new Edge($class, $member, Relation::Receives, Confidence::Inferred, '', $via));
                    ++$edges;
                }
            }
        }

        return $edges;
    }

    /**
     * What the guard of a member says for each holder at the top of the chain (not injected by id further): the use
     * cases, which pass the input. A member no use case can run keeps them all: dead or not, the graph cannot tell.
     *
     * @param array<string, string>               $holders    holder id => the id it injects
     * @param array<string, array<string, true>>  $injectedBy service id => the ids injecting it
     *
     * @return array<string, string> holder id => decision
     */
    private function decisions(string $member, array $holders, array $injectedBy, string $service): array
    {
        if ($this->guards?->guardOf($member) === null) {
            return [];
        }
        $decisions = [];
        foreach ($holders as $holder => $injected) {
            $class = $this->classOf((string) $holder, $service);
            $held = $this->classOf($injected, $service);
            if (!isset($injectedBy[$holder]) && $class !== null && $held !== null) {
                $decisions[$holder] = $this->guards->decide($member, $class, $held);
            }
        }
        if ($decisions !== [] && array_diff($decisions, [InputGuards::DROP]) === []) {
            return [];
        }

        return $decisions;
    }

    private function classOf(string $id, string $service): ?string
    {
        $class = $this->container->classOf($id, $service) ?? (str_contains($id, '\\') ? ltrim($id, '\\') : null);
        $class = $class === null ? null : $this->names->canonical($class, $service);

        return $class !== null && $this->graph->node($class)?->kind === NodeKind::PhpClass ? $class : null;
    }

    private function hasEdge(string $source, string $target): bool
    {
        foreach ($this->graph->incident($source) as $item) {
            if ($item['forward'] && $item['other'] === $target && $item['edge']->relation === Relation::Receives) {
                return true;
            }
        }

        return false;
    }
}
