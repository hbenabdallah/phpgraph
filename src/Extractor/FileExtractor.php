<?php

declare(strict_types=1);

namespace PhpGraph\Extractor;

/**
 * Turns the source of one file into graph facts. Cross-file resolution happens later in GraphBuilder,
 * so an implementation only needs what it can read from the file itself.
 */
interface FileExtractor
{
    /**
     * @throws \PhpParser\Error when the file cannot be parsed
     */
    public function extract(string $code, string $relativePath): FileExtraction;
}
