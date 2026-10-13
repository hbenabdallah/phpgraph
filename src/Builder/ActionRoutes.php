<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Edge;
use PhpGraph\Graph\Graph;
use PhpGraph\Graph\Node;
use PhpGraph\Graph\NodeKind;
use PhpGraph\Graph\Relation;
use Symfony\Component\Finder\Finder;

/**
 * The actions of routing files of the project's own making (ActionMapping): a route node per action code,
 * `route:<file>#ACTION <code>`, handled by the controller method it names (EXTRACTED), and referencing its controller
 * (`via` `controller`) when the method is inherited. The actions and templates an
 * action goes on to are `references` of the route, through `target` or `forward <result>`; the files quoting a code
 * (a template's `value="saveOrder"`, a script's `'saveOrder&id=…'`) reference its route, AMBIGUOUS: not a code that
 * is a plain word (`default`, `login`), nor a minified library.
 */
final class ActionRoutes
{
    public const METHOD = 'ACTION';

    private const LISTED = 40;

    /** @var array<string, list<string>> file name => the project files bearing it, for the templates */
    private array $byName = [];

    public function __construct(
        private readonly Graph $graph,
        private readonly NameCanonicalizer $names,
        private readonly TypeResolver $types,
        private readonly ServiceMap $services,
    ) {
    }

    /**
     * @param list<ActionMapping> $mappings
     * @param list<string>        $excludePatterns
     */
    public function resolve(string $root, array $mappings, array $excludePatterns = []): ActionStats
    {
        if ($mappings === []) {
            return new ActionStats();
        }
        $classesByFile = [];
        foreach ($this->graph->nodes() as $node) {
            if ($node->kind->isClassLike() && $node->kind !== NodeKind::External && $node->file !== null) {
                $classesByFile[$node->file][] = $node;
            }
        }

        $actions = [];
        $unreadable = [];
        foreach ($mappings as $mapping) {
            $read = $this->read($root . '/' . $mapping->file, $mapping);
            if ($read === null) {
                $unreadable[] = $mapping->file;
                continue;
            }
            array_push($actions, ...$read);
        }

        $extensions = [];
        foreach ($mappings as $mapping) {
            foreach ($mapping->citedIn as $extension) {
                $extensions[strtolower($extension)] = true;
            }
        }
        foreach ($actions as $action) {
            foreach ($action['targets'] as [, $target]) {
                if (preg_match('/\.(\w+)$/', $target, $match) === 1) {
                    $extensions[strtolower($match[1])] = true;
                }
            }
        }
        $files = $this->files($root, array_map('strval', array_keys($extensions)), $excludePatterns);

        // The routes and their handlers.
        $routes = [];
        $linked = $toClass = 0;
        $unlinked = [];
        foreach ($actions as $index => $action) {
            $mapping = $action['mapping'];
            $service = $this->services->serviceOf($mapping->file);
            $id = NameCanonicalizer::qualify('route:' . $mapping->file . '#' . self::METHOD . ' ' . $action['code'], $service);
            $actions[$index]['id'] = $id;
            $routes[$action['code']] ??= $id;
            if (!$this->graph->hasNode($id)) {
                $this->graph->addNode(new Node($id, self::METHOD . ' ' . $action['code'], NodeKind::Route, $mapping->file, $action['line'], $service === '' ? null : $service));
            }
            $class = $this->controller($action['controller'], $mapping, $classesByFile, $service);
            if ($class === null) {
                $unlinked[] = \sprintf('%s: no controller for %s (%s)', $action['code'], $action['controller'], $this->expected($action['controller'], $mapping));
                continue;
            }
            $method = $action['method'] === '' ? null : $this->types->findMethod($class, strtolower($action['method']));
            if ($method !== null && $this->graph->hasNode($method)) {
                $this->graph->addEdge(new Edge($id, $method, Relation::HandledBy, Confidence::Extracted));
                // A method inherited from the parent of every controller: the action is still this controller's.
                if (explode('::', $method)[0] !== $class) {
                    $this->graph->addEdge(new Edge($id, $class, Relation::References, Confidence::Extracted, '', 'controller'));
                }
                ++$linked;
                continue;
            }
            $this->graph->addEdge(new Edge($id, $class, Relation::HandledBy, Confidence::Extracted));
            ++$toClass;
            $unlinked[] = \sprintf('%s: %s has no method %s', $action['code'], $this->graph->node($class)->label ?? $class, $action['method'] === '' ? '(none named)' : $action['method'] . '()');
        }

        // Where an action goes on to: another action, or a template.
        $forwards = $forwardsLinked = 0;
        $templates = [];
        $unresolvedTargets = [];
        foreach ($actions as $action) {
            foreach ($action['targets'] as [$via, $target]) {
                ++$forwards;
                $next = $routes[$target] ?? null;
                if ($next === null && str_contains($target, '.')) {
                    $file = $this->template($target, $action['controller'], $files);
                    if ($file !== null) {
                        $next = $this->fileNode($file);
                        $templates[$file] = true;
                    }
                }
                if ($next === null) {
                    $unresolvedTargets[$target] = true;
                    continue;
                }
                ++$forwardsLinked;
                $this->graph->addEdge(new Edge($action['id'], $next, Relation::References, Confidence::Extracted, '', $via));
            }
        }

        // The files quoting a code: the screens sending the action.
        $citations = 0;
        $citing = [];
        $quoted = [];
        foreach ($mappings as $mapping) {
            foreach ($mapping->citedIn as $extension) {
                $quoted[strtolower($extension)] = true;
            }
        }
        foreach ($quoted === [] ? [] : $files as $file) {
            if (!isset($quoted[strtolower(pathinfo($file, \PATHINFO_EXTENSION))]) || str_ends_with($file, '.min.js')) {
                continue;
            }
            $content = (string) @file_get_contents($root . '/' . $file);
            // A minified or bundled library is no screen: its quoted words (`'unknown'`, `'test'`) are its own.
            if (preg_match('/^[^\n]{1000}/m', $content) === 1) {
                continue;
            }
            if (preg_match_all('/[\'"=&](\w+)(?=[\'"&])/', $content, $matches, \PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }
            $lines = [];
            foreach ($matches[1] as [$code, $offset]) {
                if (isset($routes[$code]) && self::distinctive($code)) {
                    $lines[$routes[$code]][substr_count($content, "\n", 0, $offset) + 1] = true;
                }
            }
            foreach ($lines as $route => $at) {
                $this->graph->addEdge(new Edge($this->fileNode($file), $route, Relation::References, Confidence::Ambiguous, implode(',', array_keys($at))));
                ++$citations;
                $citing[$file] = true;
            }
        }

        return new ActionStats(
            \count($actions),
            $linked,
            $toClass,
            \count($unlinked),
            \array_slice($unlinked, 0, self::LISTED),
            $forwards,
            $forwardsLinked,
            \count($templates),
            \count($unresolvedTargets),
            \array_slice(array_map('strval', array_keys($unresolvedTargets)), 0, self::LISTED),
            $citations,
            \count($citing),
            $unreadable,
        );
    }

    /**
     * A code worth looking for between quotes: `saveReservation`, `SO_080`, not `default` or `login`, words any file
     * quotes for its own reasons.
     */
    private static function distinctive(string $code): bool
    {
        return preg_match('/[A-Z0-9_]/', $code) === 1 || \strlen($code) >= 10;
    }

    /**
     * @return list<array{mapping: ActionMapping, code: string, line: int, controller: string, method: string, targets: list<array{string, string}>, id: string}>|null
     */
    private function read(string $path, ActionMapping $mapping): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $document = new \DOMDocument();
        // No network, no external entity: the file is data.
        if (!@$document->load($path, \LIBXML_NONET)) {
            return null;
        }
        $elements = @(new \DOMXPath($document))->query($mapping->element);
        if ($elements === false) {
            return null;
        }

        $actions = [];
        foreach ($elements as $element) {
            if (!$element instanceof \DOMElement || trim($element->getAttribute($mapping->code)) === '') {
                continue;
            }
            $targets = [];
            $target = $mapping->target === null ? '' : trim($element->getAttribute($mapping->target));
            if ($target !== '') {
                $targets[] = ['target', $target];
            }
            foreach ($mapping->forward === null ? [] : $element->childNodes as $child) {
                if ($child instanceof \DOMElement && $child->nodeName === $mapping->forward && trim($child->getAttribute($mapping->forwardTarget)) !== '') {
                    $result = trim($child->getAttribute($mapping->forwardResult));
                    $targets[] = [trim('forward ' . $result), trim($child->getAttribute($mapping->forwardTarget))];
                }
            }
            $actions[] = [
                'mapping' => $mapping,
                'code' => trim($element->getAttribute($mapping->code)),
                'line' => $element->getLineNo(),
                'controller' => trim($element->getAttribute($mapping->controller)),
                'method' => trim($element->getAttribute($mapping->method)),
                'targets' => $targets,
                'id' => '',
            ];
        }

        return $actions;
    }

    /**
     * The class of the file the controller attribute names, else the class it names.
     *
     * @param array<string, list<Node>> $classesByFile
     */
    private function controller(string $controller, ActionMapping $mapping, array $classesByFile, string $service): ?string
    {
        if ($controller === '') {
            return null;
        }
        if ($mapping->controllerFile !== null) {
            $file = ActionMapping::fill($mapping->controllerFile, $controller);
            $classes = $classesByFile[$file] ?? [];
            $named = array_values(array_filter($classes, static fn (Node $node): bool => strcasecmp($node->label, basename($file, '.php')) === 0));
            $class = $named[0] ?? $classes[0] ?? null;
            if ($class !== null) {
                return $class->id;
            }
        }
        if ($mapping->controllerClass !== null) {
            $class = $this->names->canonical(ActionMapping::fill($mapping->controllerClass, $controller), $service);
            if ($this->graph->node($class)?->kind->isClassLike() === true) {
                return $class;
            }
        }

        return null;
    }

    private function expected(string $controller, ActionMapping $mapping): string
    {
        return implode(' or ', array_filter([
            $mapping->controllerFile === null ? null : ActionMapping::fill($mapping->controllerFile, $controller),
            $mapping->controllerClass === null ? null : ActionMapping::fill($mapping->controllerClass, $controller),
        ]));
    }

    /**
     * The project file a target names: the same path, else the only file of that name whose path holds the target's
     * directories in order (`_Stock/SO_080/Resa.html` is `_Stock/views/SO_080/Resa.html`), else the only one in the
     * controller's first directory.
     *
     * @param list<string> $files
     */
    private function template(string $target, string $controller, array $files): ?string
    {
        $target = ltrim(str_replace('\\', '/', $target), '/');
        if ($this->byName === [] && $files !== []) {
            foreach ($files as $file) {
                $this->byName[strtolower(basename($file))][] = $file;
            }
        }
        $candidates = $this->byName[strtolower(basename($target))] ?? [];
        if (\in_array($target, $candidates, true)) {
            return $target;
        }
        $directories = array_values(array_filter(explode('/', \dirname($target)), static fn (string $part): bool => $part !== '.' && $part !== ''));
        $candidates = array_values(array_filter($candidates, static function (string $file) use ($directories): bool {
            $parts = explode('/', \dirname($file));
            $at = 0;
            foreach ($directories as $directory) {
                $found = array_search($directory, \array_slice($parts, $at), true);
                if ($found === false) {
                    return false;
                }
                $at += (int) $found + 1;
            }

            return true;
        }));
        if (\count($candidates) > 1) {
            $module = explode('/', $controller)[0];
            $candidates = array_values(array_filter($candidates, static fn (string $file): bool => explode('/', $file)[0] === $module));
        }

        return \count($candidates) === 1 ? $candidates[0] : null;
    }

    private function fileNode(string $file): string
    {
        $id = 'file:' . $file;
        if (!$this->graph->hasNode($id)) {
            $this->graph->addNode(new Node($id, $file, NodeKind::File, $file, null));
        }

        return $id;
    }

    /**
     * @param list<string> $extensions
     * @param list<string> $excludePatterns
     *
     * @return list<string> the project files of those extensions, relative to the root
     */
    private function files(string $root, array $extensions, array $excludePatterns): array
    {
        if ($extensions === []) {
            return [];
        }
        $gitignore = new ProjectGitignore($root);
        $finder = Finder::create()->files()->in($root)
            ->name(array_map(static fn (string $extension): string => '*.' . $extension, $extensions))
            ->exclude(SourceFiles::DEFAULT_EXCLUDES)
            ->filter(static fn (\SplFileInfo $file): bool => !$gitignore->isIgnored($file->getPathname()))
            ->sortByName();
        foreach ($excludePatterns as $pattern) {
            $finder->notPath($pattern);
        }
        $files = [];
        foreach ($finder as $file) {
            $files[] = str_replace('\\', '/', $file->getRelativePathname());
        }

        return $files;
    }
}
