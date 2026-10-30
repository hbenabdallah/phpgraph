<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

/**
 * A string expression of a configuration helper, evaluated by the builder (ConfigurationEvaluator) once the arguments
 * of a call are known: `$prefix . '.rule'`, `sprintf('%s.%s', $prefix, self::SUFFIX)`, `__DIR__`.
 */
final readonly class Template
{
    /**
     * @param string     $value the text, the parameter's name, the constant (`Class::NAME`) or the function called
     * @param list<self> $parts the parts of a concatenation, the arguments of a call, the items of a list
     */
    private function __construct(
        public TemplateKind $kind,
        public string $value = '',
        public array $parts = [],
    ) {
    }

    public static function literal(string $text): self
    {
        return new self(TemplateKind::Literal, $text);
    }

    public static function parameter(string $name): self
    {
        return new self(TemplateKind::Parameter, $name);
    }

    /**
     * `__DIR__`: the directory of the file declaring the helper.
     */
    public static function directory(): self
    {
        return new self(TemplateKind::Directory);
    }

    public static function constant(string $constant): self
    {
        return new self(TemplateKind::Constant, $constant);
    }

    /**
     * @param list<self> $parts
     */
    public static function concat(array $parts): self
    {
        return new self(TemplateKind::Concat, '', $parts);
    }

    /**
     * A call to one of ConfigurationHelpers::FUNCTIONS.
     *
     * @param list<self> $arguments
     */
    public static function call(string $function, array $arguments): self
    {
        return new self(TemplateKind::Call, $function, $arguments);
    }

    /**
     * An array of strings, an argument of str_replace() or implode().
     *
     * @param list<self> $items
     */
    public static function list(array $items): self
    {
        return new self(TemplateKind::List, '', $items);
    }

    /**
     * Whether the value depends on a parameter of the helper: known only when its caller is evaluated.
     */
    public function usesParameters(): bool
    {
        if ($this->kind === TemplateKind::Parameter) {
            return true;
        }
        foreach ($this->parts as $part) {
            if ($part->usesParameters()) {
                return true;
            }
        }

        return false;
    }
}
