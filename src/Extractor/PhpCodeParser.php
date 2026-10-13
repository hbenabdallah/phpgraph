<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

use PhpParser\Error;
use PhpParser\Node\Stmt;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

/**
 * Parses for the newest PHP, then, when that fails, for PHP 5.6: legacy code keeps syntax removed since
 * (`$s{0}`, `=& new`, octals like `08`), which the PHP 5 grammar reads on the original source, lines and columns
 * unchanged. Only a file the newest grammar rejects is tried again: the PHP 5.6 lexer reads newer keywords
 * (`match`, `enum`, `#[`) as it did then.
 */
final class PhpCodeParser
{
    private readonly Parser $newest;

    private ?Parser $legacy = null;

    public function __construct()
    {
        $this->newest = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * @return array<Stmt>
     *
     * @throws Error the error of the newest grammar, when PHP 5.6 cannot read the file either
     */
    public function parse(string $code): array
    {
        try {
            return $this->newest->parse($code) ?? [];
        } catch (Error $error) {
            $this->legacy ??= (new ParserFactory())->createForVersion(PhpVersion::fromComponents(5, 6));
            try {
                return $this->legacy->parse($code) ?? [];
            } catch (Error) {
                throw $error;
            }
        }
    }
}
