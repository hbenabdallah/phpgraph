<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * The classes of Symfony container services, read from their definitions: YAML (`services:`), XML (`<container>`),
 * and PHP configurators (collected by the extraction). Lets a route name its controller by service id.
 *
 * Read only: no container is compiled. Services generated at runtime by a bundle, without a definition in the
 * project, stay unknown.
 */
final class ContainerServices
{
    private const MAX_ALIASES = 5;

    /** @var array<string, array<string, string>> application service => id => class or `@alias` */
    private array $definitions = [];

    /** @var array<string, array<string, string>> application service => parameter => value */
    private array $parameters = [];

    /** @var list<array{service: string, id: ?string, instanceof: ?string, name: string, attributes: array<string, string>}> */
    private array $tags = [];

    /** @var list<array{service: string, id: string, tag: ?string, target: ?string, locator: bool}> */
    private array $arguments = [];

    /** @var array<string, array{int, int, array<mixed>}> file => [modification time, size, what it declares] */
    private array $cache = [];

    /** @var array<string, string> while reading a file: id => class or `@alias` */
    private array $fileDefinitions = [];

    /** @var array<string, string> */
    private array $fileParameters = [];

    /** @var list<array{id: ?string, instanceof: ?string, name: string, attributes: array<string, string>}> */
    private array $fileTags = [];

    /** @var list<array{id: string, tag: ?string, service: ?string, locator?: bool}> */
    private array $fileArguments = [];

    /**
     * @param list<string>                            $files   YAML and XML files of config directories, relative to the root
     * @param array<string, array<string, string>>    $declared definitions collected from PHP, by application service
     * @param array<string, list<array{id: ?string, instanceof: ?string, name: string, attributes: array<string, string>}>> $declaredTags
     *                                                         tags collected from PHP, by application service
     * @param array<string, array{int, int, array<mixed>}> $cache files read by the last build of this process
     * @param array<string, list<array{id: string, tag: ?string, service: ?string, locator?: bool}>> $declaredArguments
     *                                                         injections collected from PHP, by application service
     */
    public function __construct(string $root, array $files, ServiceMap $services, array $declared = [], array $declaredTags = [], array $cache = [], array $declaredArguments = [])
    {
        foreach ($declaredArguments as $service => $arguments) {
            foreach ($arguments as $argument) {
                $this->arguments[] = ['service' => (string) $service, 'id' => $argument['id'], 'tag' => $argument['tag'], 'target' => $argument['service'], 'locator' => $argument['locator'] ?? false];
            }
        }
        foreach ($declared as $service => $definitions) {
            foreach ($definitions as $id => $target) {
                $this->definitions[$service][$id] ??= $target;
            }
        }
        foreach ($declaredTags as $service => $tags) {
            foreach ($tags as $tag) {
                $this->tags[] = ['service' => (string) $service] + $tag;
            }
        }

        foreach ($files as $file) {
            $path = $root . '/' . $file;
            $modified = (int) @filemtime($path);
            $size = (int) @filesize($path);
            [$cachedModified, $cachedSize, $declared] = $cache[$file] ?? [null, null, null];
            if ($cachedModified !== $modified || $cachedSize !== $size || !\is_array($declared)) {
                $declared = $this->read($file, (string) @file_get_contents($path));
            }
            $this->cache[$file] = [$modified, $size, $declared];
            $this->merge($declared, $services->serviceOf($file));
        }
    }

    /**
     * What each configuration file declared, for the next build of this process.
     *
     * @return array<string, array{int, int, array<mixed>}>
     */
    public function cache(): array
    {
        return $this->cache;
    }

    /**
     * @return array{definitions: array<string, string>, parameters: array<string, string>, tags: list<array{id: ?string, instanceof: ?string, name: string, attributes: array<string, string>}>}
     */
    private function read(string $file, string $content): array
    {
        $this->fileDefinitions = $this->fileParameters = $this->fileTags = $this->fileArguments = [];
        if (SourceFiles::isYaml($file)) {
            $this->readYaml($content);
        } elseif (str_contains(substr($content, 0, 2000), '<container')) {
            $this->readXml($content);
        }

        return ['definitions' => $this->fileDefinitions, 'parameters' => $this->fileParameters, 'tags' => $this->fileTags, 'arguments' => $this->fileArguments];
    }

    /**
     * @param array<mixed> $declared as returned by read()
     */
    private function merge(array $declared, string $service): void
    {
        foreach (\is_array($declared['definitions'] ?? null) ? $declared['definitions'] : [] as $id => $target) {
            $this->definitions[$service][(string) $id] ??= (string) $target;
        }
        foreach (\is_array($declared['parameters'] ?? null) ? $declared['parameters'] : [] as $name => $value) {
            $this->parameters[$service][(string) $name] = (string) $value;
        }
        foreach (\is_array($declared['tags'] ?? null) ? $declared['tags'] : [] as $tag) {
            /** @var array{id: ?string, instanceof: ?string, name: string, attributes: array<string, string>} $tag */
            $this->tags[] = ['service' => $service] + $tag;
        }
        foreach (\is_array($declared['arguments'] ?? null) ? $declared['arguments'] : [] as $argument) {
            /** @var array{id: string, tag: ?string, service: ?string, locator?: bool} $argument */
            $this->arguments[] = ['service' => $service, 'id' => $argument['id'], 'tag' => $argument['tag'], 'target' => $argument['service'], 'locator' => $argument['locator'] ?? false];
        }
    }

    /**
     * What services receive by configuration: the services of a tag, or one service named by id.
     *
     * @return list<array{service: string, id: string, tag: ?string, target: ?string, locator: bool}>
     */
    public function arguments(): array
    {
        return $this->arguments;
    }

    /**
     * The first segment of the service ids the project defines (`sales_order` for `sales_order.price_list.loader`):
     * an id with one of them is likely the project's own.
     *
     * @return array<string, true>
     */
    public function idPrefixes(): array
    {
        $prefixes = [];
        foreach ($this->definitions as $definitions) {
            foreach (array_keys($definitions) as $id) {
                $id = (string) $id;
                if (!str_contains($id, '\\') && str_contains($id, '.')) {
                    $prefixes[strtolower(explode('.', $id)[0])] = true;
                }
            }
        }

        return $prefixes;
    }

    /**
     * The class of a service, following aliases and parameters, in the application service then in the root code.
     */
    public function classOf(string $id, string $service = ''): ?string
    {
        foreach (array_unique([$service, ServiceMap::ROOT, '']) as $scope) {
            $target = $id;
            for ($step = 0; $step <= self::MAX_ALIASES; ++$step) {
                $definition = $this->definitions[$scope][ltrim($target, '@')] ?? null;
                if ($definition === null) {
                    break;
                }
                if (!str_starts_with($definition, '@')) {
                    $class = $this->parameter($definition, $scope);

                    return str_contains($class, '\\') || preg_match('/^[A-Z]\w*$/', $class) === 1 ? ltrim($class, '\\') : null;
                }
                $target = $definition;
            }
        }

        return null;
    }

    /**
     * The tags of the services: `messenger.message_handler`, `kernel.event_listener`... A tag set through
     * `_instanceof` names the type its services implement instead of a service id.
     *
     * @return list<array{service: string, id: ?string, instanceof: ?string, name: string, attributes: array<string, string>}>
     */
    public function tags(): array
    {
        return $this->tags;
    }

    private function readYaml(string $content): void
    {
        // Most YAML files of a config directory are not service definitions: skip them before parsing.
        if (preg_match('/^(services|parameters)\s*:/m', $content) !== 1) {
            return;
        }

        try {
            $data = Yaml::parse($content, Yaml::PARSE_CUSTOM_TAGS);
        } catch (ParseException) {
            return;
        }
        if (!\is_array($data)) {
            return;
        }

        foreach (\is_array($data['parameters'] ?? null) ? $data['parameters'] : [] as $name => $value) {
            if (\is_string($value)) {
                $this->fileParameters[(string) $name] = $value;
            }
        }

        $definitions = \is_array($data['services'] ?? null) ? $data['services'] : [];
        foreach (\is_array($definitions['_instanceof'] ?? null) ? $definitions['_instanceof'] : [] as $type => $definition) {
            foreach ($this->yamlTags($definition) as $tag) {
                $this->fileTags[] = ['id' => null, 'instanceof' => ltrim((string) $type, '\\')] + $tag;
            }
        }

        foreach ($definitions as $id => $definition) {
            $id = (string) $id;
            if (str_starts_with($id, '_')) {
                continue;
            }
            // A resource, `App\Rule\: { resource: '../src/Rule/', tags: [app.rule] }`, tags every class of its namespace.
            foreach ($this->yamlTags($definition) as $tag) {
                $this->fileTags[] = ['id' => $id, 'instanceof' => null] + $tag;
            }
            if (str_ends_with($id, '\\')) {
                continue;
            }
            // Method names of `calls` are plain strings: only the injected values are read.
            foreach (\is_array($definition) ? [$definition['arguments'] ?? [], $definition['calls'] ?? [], $definition['properties'] ?? []] : [] as $values) {
                foreach ($this->yamlInjections($values) as $injected) {
                    $this->fileArguments[] = ['id' => $id] + $this->undecorated($injected, $id, \is_array($definition) && \is_string($definition['decorates'] ?? null) ? $definition['decorates'] : null);
                }
            }

            $target = match (true) {
                $definition === null => $id,
                \is_string($definition) => $definition,
                \is_array($definition) && \is_string($definition['class'] ?? null) => $definition['class'],
                \is_array($definition) && \is_string($definition['alias'] ?? null) => '@' . $definition['alias'],
                \is_array($definition) && \is_string($definition['parent'] ?? null) && !str_contains($id, '\\') => '@' . $definition['parent'],
                default => $id,
            };
            $this->fileDefinitions[$id] ??= $target;
        }
    }

    private function readXml(string $content): void
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        // No network access and no entity expansion: the file is data from the analysed project.
        $loaded = $document->loadXML($content, \LIBXML_NONET);
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return;
        }

        foreach ($document->getElementsByTagName('parameter') as $parameter) {
            $key = $parameter->getAttribute('key');
            if ($key !== '' && $parameter->getAttribute('type') === '') {
                $this->fileParameters[$key] = trim($parameter->textContent);
            }
        }

        foreach ($document->getElementsByTagName('instanceof') as $definition) {
            foreach ($this->xmlTags($definition) as $tag) {
                $this->fileTags[] = ['id' => null, 'instanceof' => ltrim($definition->getAttribute('id'), '\\')] + $tag;
            }
        }

        foreach ($document->getElementsByTagName('service') as $definition) {
            $id = $definition->getAttribute('id');
            if ($id === '') {
                continue;
            }
            foreach ($this->xmlTags($definition) as $tag) {
                $this->fileTags[] = ['id' => $id, 'instanceof' => null] + $tag;
            }
            foreach ($definition->getElementsByTagName('argument') as $argument) {
                $type = $argument->getAttribute('type');
                if (\in_array($type, ['tagged_iterator', 'tagged_locator', 'tagged'], true) && $argument->getAttribute('tag') !== '') {
                    $this->fileArguments[] = ['id' => $id, 'tag' => $argument->getAttribute('tag'), 'service' => null, 'locator' => $type === 'tagged_locator'];
                } elseif ($type === 'service' && $argument->getAttribute('id') !== '') {
                    $decorates = $definition->getAttribute('decorates');
                    $this->fileArguments[] = ['id' => $id] + $this->undecorated(['tag' => null, 'service' => $argument->getAttribute('id')], $id, $decorates === '' ? null : $decorates);
                }
            }
            $target = match (true) {
                $definition->getAttribute('class') !== '' => $definition->getAttribute('class'),
                $definition->getAttribute('alias') !== '' => '@' . $definition->getAttribute('alias'),
                $definition->getAttribute('parent') !== '' && !str_contains($id, '\\') => '@' . $definition->getAttribute('parent'),
                default => $id,
            };
            $this->fileDefinitions[$id] ??= $target;
        }
    }

    /**
     * `tags: [name]`, `tags: [{ name: name, handles: X }]`, or since Symfony 5.3 `tags: [{ name: { handles: X } }]`.
     *
     * @return list<array{name: string, attributes: array<string, string>}>
     */
    private function yamlTags(mixed $definition): array
    {
        $tags = [];
        foreach (\is_array($definition) && \is_array($definition['tags'] ?? null) ? $definition['tags'] : [] as $tag) {
            if (\is_string($tag)) {
                $tags[] = ['name' => $tag, 'attributes' => []];
            } elseif (\is_array($tag) && \is_string($tag['name'] ?? null)) {
                $tags[] = ['name' => $tag['name'], 'attributes' => $this->strings($tag)];
            } elseif (\is_array($tag) && \count($tag) === 1 && \is_string(array_key_first($tag))) {
                $tags[] = ['name' => (string) array_key_first($tag), 'attributes' => $this->strings(\is_array(reset($tag)) ? reset($tag) : [])];
            }
        }

        return $tags;
    }

    /**
     * `!tagged_iterator app.rule`, `!tagged_iterator { tag: app.rule }`, `!tagged_locator ...`, and `'@app.mailer'`
     * (not `'@?optional'` nor the escaped `'@@'`), in a list or by parameter name.
     *
     * @return list<array{tag: ?string, service: ?string, locator?: bool}>
     */
    private function yamlInjections(mixed $values): array
    {
        $injected = [];
        foreach (\is_array($values) ? $values : [$values] as $value) {
            if ($value instanceof TaggedValue && \in_array($value->getTag(), ['tagged_iterator', 'tagged_locator', 'tagged'], true)) {
                $tag = \is_array($value->getValue()) ? ($value->getValue()['tag'] ?? null) : $value->getValue();
                if (\is_string($tag) && $tag !== '') {
                    $injected[] = ['tag' => $tag, 'service' => null, 'locator' => $value->getTag() === 'tagged_locator'];
                }
            } elseif (\is_string($value) && preg_match('/^@([^@?=].*)$/', $value, $match) === 1) {
                $injected[] = ['tag' => null, 'service' => $match[1]];
            } elseif (\is_array($value)) {
                array_push($injected, ...$this->yamlInjections($value));
            }
        }

        return $injected;
    }

    /**
     * A decorator receives the service it decorates: `.inner` (or `<id>.inner`) is that service.
     *
     * @param array{tag: ?string, service: ?string, locator?: bool} $injected
     *
     * @return array{tag: ?string, service: ?string, locator?: bool}
     */
    private function undecorated(array $injected, string $id, ?string $decorates): array
    {
        if ($decorates !== null && ($injected['service'] === '.inner' || $injected['service'] === $id . '.inner')) {
            $injected['service'] = $decorates;
        }

        return $injected;
    }

    /**
     * @return list<array{name: string, attributes: array<string, string>}>
     */
    private function xmlTags(\DOMElement $definition): array
    {
        $tags = [];
        foreach ($definition->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->localName !== 'tag') {
                continue;
            }
            $attributes = [];
            foreach ($child->attributes as $attribute) {
                if ($attribute->name !== 'name') {
                    $attributes[$attribute->name] = $attribute->value;
                }
            }
            $name = $child->getAttribute('name') !== '' ? $child->getAttribute('name') : trim($child->textContent);
            if ($name !== '') {
                $tags[] = ['name' => $name, 'attributes' => $attributes];
            }
        }

        return $tags;
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<string, string>
     */
    private function strings(array $values): array
    {
        $strings = [];
        foreach ($values as $key => $value) {
            if (\is_string($key) && $key !== 'name' && \is_scalar($value)) {
                $strings[$key] = (string) $value;
            }
        }

        return $strings;
    }

    /**
     * `%pim_enrich.controller.product.class%` replaced by the parameter value.
     */
    private function parameter(string $value, string $service): string
    {
        return preg_replace_callback(
            '/%([^%]+)%/',
            fn (array $match): string => $this->parameters[$service][$match[1]] ?? $this->parameters[ServiceMap::ROOT][$match[1]] ?? $this->parameters[''][$match[1]] ?? $match[0],
            $value,
        ) ?? $value;
    }
}
