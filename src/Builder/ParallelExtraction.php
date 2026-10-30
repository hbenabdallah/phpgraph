<?php

declare(strict_types=1);

namespace PhpGraph\Builder;

use PhpGraph\Extractor\CachingExtractor;
use PhpGraph\Extractor\FileExtraction;
use PhpGraph\Extractor\FileExtractor;
use PhpParser\Error;

/**
 * Parses the files of a build in worker processes (pcntl_fork): each file is read on its own, so the workers share
 * nothing but the sources handed to them. Without pcntl (Windows) or for a few files, the files are parsed here.
 *
 * A worker writes its extractions to a temporary file, read back with unserialize() restricted to the extraction
 * classes, and ends itself with SIGKILL: an exit would run the shutdown functions of this process (the cache saved
 * by ProjectGraph, PHPUnit's handler). A worker that leaves nothing readable has its files parsed here instead, where
 * an unexpected exception surfaces as in a build without workers.
 */
final class ParallelExtraction
{
    /** Fewer files than this per worker: forking costs more than it saves. */
    private const FILES_PER_WORKER = 25;

    private int $workersUsed = 0;

    /**
     * @param int|null $workers worker processes, null for one per processor (`PHPGRAPH_WORKERS` sets it)
     */
    public function __construct(
        private readonly FileExtractor $extractor,
        private readonly ?int $workers = null,
    ) {
    }

    /**
     * @param array<string, string> $codes relative path => source
     *
     * @return array<string, FileExtraction|string> relative path => its extraction, or why it could not be parsed
     */
    public function extract(array $codes): array
    {
        $cache = $this->extractor instanceof CachingExtractor ? $this->extractor : null;
        $results = [];
        $toParse = [];
        foreach ($codes as $path => $code) {
            $reused = $cache?->reuse($code, $path);
            if ($reused === null) {
                $toParse[$path] = $code;
            } else {
                $results[$path] = $reused;
            }
        }

        foreach ($this->parse($cache?->inner() ?? $this->extractor, $toParse) as $path => $result) {
            $results[$path] = $cache !== null && $result instanceof FileExtraction ? $cache->keep($toParse[$path], $path, $result) : $result;
        }

        return $results;
    }

    /**
     * The worker processes whose extractions the last call used, 0 when it parsed the files itself.
     */
    public function workersUsed(): int
    {
        return $this->workersUsed;
    }

    public static function processors(): int
    {
        $configured = getenv('PHPGRAPH_WORKERS');
        if (\is_string($configured) && ctype_digit($configured)) {
            return max(1, (int) $configured);
        }
        $cpuInfo = is_readable('/proc/cpuinfo') ? (string) file_get_contents('/proc/cpuinfo') : '';
        if ($cpuInfo !== '') {
            return max(1, preg_match_all('/^processor\s*:/m', $cpuInfo));
        }
        if (PHP_OS_FAMILY === 'Darwin') {
            return max(1, (int) shell_exec('sysctl -n hw.logicalcpu'));
        }

        return 1;
    }

    /**
     * @param array<string, string> $codes
     *
     * @return array<string, FileExtraction|string>
     */
    private function parse(FileExtractor $extractor, array $codes): array
    {
        $workers = min($this->workers ?? self::processors(), intdiv(\count($codes), self::FILES_PER_WORKER));
        $this->workersUsed = 0;
        if ($workers < 2 || !\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            return self::parseHere($extractor, $codes);
        }

        $children = [];
        foreach ($this->shares($codes, $workers) as $share) {
            $output = tempnam(sys_get_temp_dir(), 'phpgraph-');
            $pid = $output === false ? -1 : pcntl_fork();
            if ($pid === 0) {
                self::work($extractor, $share, $output);
            }
            $children[] = ['pid' => $pid, 'share' => $share, 'output' => $output];
        }

        $results = [];
        foreach ($children as $child) {
            $parsed = null;
            if ($child['pid'] > 0) {
                pcntl_waitpid($child['pid'], $status);
                $parsed = self::read((string) $child['output']);
            }
            if ($parsed !== null) {
                ++$this->workersUsed;
            }
            if (\is_string($child['output'])) {
                @unlink($child['output']);
            }
            $results += $parsed ?? self::parseHere($extractor, $child['share']);
        }

        return $results;
    }

    /**
     * The files split into one share per worker, of about the same size: the largest file first, each to the share
     * holding the least.
     *
     * @param array<string, string> $codes
     *
     * @return list<array<string, string>>
     */
    private function shares(array $codes, int $workers): array
    {
        $sizes = array_map('strlen', $codes);
        arsort($sizes);
        $shares = array_fill(0, $workers, []);
        $loads = array_fill(0, $workers, 0);
        foreach ($sizes as $path => $size) {
            $lightest = 0;
            foreach ($loads as $worker => $load) {
                if ($load < $loads[$lightest]) {
                    $lightest = $worker;
                }
            }
            $shares[$lightest][$path] = $codes[$path];
            $loads[$lightest] += $size;
        }

        return array_values(array_filter($shares, static fn (array $share): bool => $share !== []));
    }

    /**
     * @param array<string, string> $codes
     */
    private static function work(FileExtractor $extractor, array $codes, string $output): never
    {
        try {
            // One payload per file: unserialize() slows down far more than the size on one large payload (5.5 s
            // instead of 0.9 s for PrestaShop's files).
            file_put_contents($output, serialize(array_map('serialize', self::parseHere($extractor, $codes))));
        } catch (\Throwable) {
            // Nothing written: the parent parses these files again itself, and meets the exception there.
        }
        posix_kill(posix_getpid(), \SIGKILL);

        exit(1);
    }

    /**
     * @param array<string, string> $codes
     *
     * @return array<string, FileExtraction|string>
     */
    private static function parseHere(FileExtractor $extractor, array $codes): array
    {
        $results = [];
        foreach ($codes as $path => $code) {
            try {
                $results[$path] = $extractor->extract($code, $path);
            } catch (Error $error) {
                $results[$path] = $error->getMessage();
            }
        }

        return $results;
    }

    /**
     * @return array<string, FileExtraction|string>|null null when the worker left nothing readable
     */
    private static function read(string $output): ?array
    {
        $content = @file_get_contents($output);
        $payloads = \is_string($content) && $content !== '' ? @unserialize($content, ['allowed_classes' => false]) : false;
        if (!\is_array($payloads)) {
            return null;
        }

        $parsed = [];
        foreach ($payloads as $path => $payload) {
            $result = \is_string($payload) ? @unserialize($payload, ['allowed_classes' => CachingExtractor::ALLOWED_CLASSES]) : null;
            if (!$result instanceof FileExtraction && !\is_string($result)) {
                return null;
            }
            $parsed[(string) $path] = $result;
        }

        return $parsed;
    }
}
