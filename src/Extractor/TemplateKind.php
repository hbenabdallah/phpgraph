<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

/**
 * What a template of a configuration helper is: a piece of text, or what builds it once the arguments are known.
 */
enum TemplateKind: string
{
    case Literal = 'lit';
    case Parameter = 'param';
    case Directory = 'dir';
    case Constant = 'const';
    case Concat = 'concat';
    case Call = 'call';
    case List = 'list';
}
