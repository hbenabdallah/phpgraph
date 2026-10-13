<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

/**
 * A routing file of the project's own making (phpgraph.yaml, `actions:`): XML elements mapping an action code, sent by
 * the front end, to a method of a controller, with the actions or templates it goes on to.
 *
 *     actions:
 *       - file: Framework/jacActions.xml
 *         element: //action                            # XPath of the action elements
 *         code: code                                   # their attributes: the code, the controller, its method
 *         controller: controller
 *         method: method
 *         controller_file: '{dir}/control/{name}CTRL.php'  # _Stock/Reservation: {dir} _Stock, {name} Reservation
 *         target: target                               # the action or template it goes on to
 *         forward: { element: forward, result: result, target: target }
 *         cited_in: [html, js]                         # files citing the codes: the screens sending them
 */
final readonly class ActionMapping
{
    public const KEYS = ['file', 'element', 'code', 'controller', 'method', 'controller_file', 'controller_class', 'target', 'forward', 'cited_in'];

    /**
     * @param string       $file            the XML file, relative to the root
     * @param string       $element         XPath of the action elements
     * @param ?string      $controllerFile  the controller's file from the controller attribute: `{value}`, `{dir}` (before
     *                                      its last /), `{name}` (after it)
     * @param ?string      $controllerClass the controller's class, the same way, when the file does not say it
     * @param ?string      $target          attribute naming the action or template an action goes on to
     * @param ?string      $forward         child elements of an action: a result and the action or template it leads to
     * @param list<string> $citedIn         extensions of the files whose quoted codes are linked to the actions (AMBIGUOUS)
     */
    public function __construct(
        public string $file,
        public string $element = '//action',
        public string $code = 'code',
        public string $controller = 'controller',
        public string $method = 'method',
        public ?string $controllerFile = null,
        public ?string $controllerClass = null,
        public ?string $target = null,
        public ?string $forward = null,
        public string $forwardResult = 'result',
        public string $forwardTarget = 'target',
        public array $citedIn = [],
    ) {
    }

    /**
     * @param array<mixed> $data one entry of `actions:`
     */
    public static function fromArray(array $data, string $source): self
    {
        $unknown = array_diff(array_keys($data), self::KEYS);
        if ($unknown !== []) {
            throw new \RuntimeException(\sprintf('%s: unknown key "%s" in actions. Known: %s.', $source, implode('", "', $unknown), implode(', ', self::KEYS)));
        }
        $text = static function (string $key, ?string $default) use ($data, $source): ?string {
            $value = $data[$key] ?? $default;
            if ($value !== null && (!\is_string($value) || $value === '')) {
                throw new \RuntimeException(\sprintf('%s: actions.%s must be a non-empty string.', $source, $key));
            }

            return $value;
        };
        $file = $text('file', null);
        if ($file === null) {
            throw new \RuntimeException(\sprintf('%s: each entry of actions needs a file.', $source));
        }
        if (!isset($data['controller_file']) && !isset($data['controller_class'])) {
            throw new \RuntimeException(\sprintf('%s: actions needs controller_file or controller_class to find the controllers.', $source));
        }
        $forward = $data['forward'] ?? null;
        if (\is_string($forward)) {
            $forward = ['element' => $forward];
        }
        if ($forward !== null && (!\is_array($forward) || !\is_string($forward['element'] ?? null)
            || array_diff(array_keys($forward), ['element', 'result', 'target']) !== [])) {
            throw new \RuntimeException(\sprintf('%s: actions.forward must name its element, with result and target attributes: { element: forward, result: result, target: target }.', $source));
        }
        $citedIn = $data['cited_in'] ?? [];
        if (!\is_array($citedIn) || array_filter($citedIn, static fn (mixed $extension): bool => !\is_string($extension) || $extension === '') !== []) {
            throw new \RuntimeException(\sprintf('%s: actions.cited_in must be a list of file extensions.', $source));
        }

        return new self(
            ltrim($file, '/'),
            (string) $text('element', '//action'),
            (string) $text('code', 'code'),
            (string) $text('controller', 'controller'),
            (string) $text('method', 'method'),
            $text('controller_file', null),
            $text('controller_class', null),
            $text('target', null),
            \is_array($forward) && \is_string($forward['element'] ?? null) ? $forward['element'] : null,
            \is_array($forward) && \is_string($forward['result'] ?? null) ? $forward['result'] : 'result',
            \is_array($forward) && \is_string($forward['target'] ?? null) ? $forward['target'] : 'target',
            array_values(array_map(static fn (string $extension): string => ltrim($extension, '.'), array_filter($citedIn, 'is_string'))),
        );
    }

    /**
     * `{dir}/control/{name}CTRL.php` for `_Stock/Reservation`: `_Stock/control/ReservationCTRL.php`.
     */
    public static function fill(string $pattern, string $value): string
    {
        $slash = strrpos($value, '/');
        $filled = strtr($pattern, [
            '{value}' => $value,
            '{dir}' => $slash === false ? '' : substr($value, 0, $slash),
            '{name}' => $slash === false ? $value : substr($value, $slash + 1),
        ]);

        return ltrim((string) preg_replace('#/{2,}#', '/', $filled), '/');
    }
}
