<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;

final class PhpFileExtractor implements FileExtractor
{
    private readonly PhpCodeParser $parser;

    public function __construct()
    {
        $this->parser = new PhpCodeParser();
    }

    public function extract(string $code, string $relativePath): FileExtraction
    {
        $statements = $this->parser->parse($code);
        $nameResolver = new NameResolver();
        $statements = (new NodeTraverser($nameResolver, new DocTypeResolver($nameResolver->getNameContext())))->traverse($statements);

        $visitor = new ExtractionVisitor($relativePath);
        (new NodeTraverser($visitor))->traverse($statements);

        return $visitor->result();
    }
}
