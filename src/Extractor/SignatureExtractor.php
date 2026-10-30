<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;

/**
 * The declarations of a file without what its functions and methods do: what the signatures of a dependency need
 * (vendor/). The bodies are dropped before they are read, so reading a package costs its parsing only.
 */
final class SignatureExtractor implements FileExtractor
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
        $bodies = new class extends NodeVisitorAbstract {
            public function enterNode(Node $node): null
            {
                // An abstract method keeps its null body: it is what tells it apart.
                if (($node instanceof Node\Stmt\ClassMethod && $node->stmts !== null) || $node instanceof Node\Stmt\Function_) {
                    $node->stmts = [];
                }

                return null;
            }
        };
        $statements = (new NodeTraverser($bodies, $nameResolver, new DocTypeResolver($nameResolver->getNameContext())))->traverse($statements);

        $visitor = new ExtractionVisitor($relativePath);
        (new NodeTraverser($visitor))->traverse($statements);

        return $visitor->result();
    }
}
