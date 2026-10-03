<?php

declare(strict_types=1);

/*
 * Measures phpgraph on a corpus of real open-source projects pinned in corpus/projects.json.
 *
 *   php tools/corpus.php fetch [name...]       clone the projects at their pinned commit and install their dependencies
 *   php tools/corpus.php measure [name...]     measure and compare with corpus/baseline.json
 *   php tools/corpus.php measure --baseline    measure and overwrite the baseline
 */

use PhpGraph\Builder\GraphBuilder;
use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\NodeKind;
use PhpGraph\Query\GraphQuery;

require __DIR__ . '/../vendor/autoload.php';

const CORPUS_DIR = __DIR__ . '/../corpus';
const CHECKOUTS_DIR = CORPUS_DIR . '/checkouts';
const LOCKS_DIR = CORPUS_DIR . '/locks';
const BASELINE_FILE = CORPUS_DIR . '/baseline.json';
const RESULTS_FILE = CORPUS_DIR . '/RESULTS.md';

/**
 * @param list<string> $names
 *
 * @return list<array{name: string, repository: string, commit: string, stack: list<string>, why: string, composer?: list<string>}>
 */
function projects(array $names): array
{
    /** @var list<array{name: string, repository: string, commit: string, stack: list<string>, why: string, composer?: list<string>}> $projects */
    $projects = json_decode((string) file_get_contents(CORPUS_DIR . '/projects.json'), true, 512, JSON_THROW_ON_ERROR);
    if ($names === []) {
        return $projects;
    }

    $selected = array_values(array_filter($projects, static fn (array $project): bool => in_array($project['name'], $names, true)));
    $unknown = array_diff($names, array_column($selected, 'name'));
    if ($unknown !== []) {
        fail('Unknown project: ' . implode(', ', $unknown));
    }

    return $selected;
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function run(string $command): void
{
    passthru($command, $code);
    if ($code !== 0) {
        fail('Command failed: ' . $command);
    }
}

/**
 * @param array{name: string, repository: string, commit: string, stack: list<string>, why: string, composer?: list<string>} $project
 */
function fetch(array $project): void
{
    $directory = CHECKOUTS_DIR . '/' . $project['name'];
    $head = is_dir($directory . '/.git') ? trim((string) shell_exec('git -C ' . escapeshellarg($directory) . ' rev-parse HEAD 2>/dev/null')) : '';

    if ($head === $project['commit']) {
        echo $project['name'] . ": already at {$project['commit']}\n";
    } else {
        checkout($project, $directory);
    }

    foreach (composerDirectories($project, $directory) as $relative) {
        installDependencies($project['name'], $directory, $relative);
    }
}

/**
 * @param array{name: string, repository: string, commit: string, stack: list<string>, why: string, composer?: list<string>} $project
 */
function checkout(array $project, string $directory): void
{

    echo $project['name'] . ": fetching {$project['commit']}\n";
    if (!is_dir($directory . '/.git')) {
        @mkdir($directory, 0775, true);
        run('git -C ' . escapeshellarg($directory) . ' init -q');
        run('git -C ' . escapeshellarg($directory) . ' remote add origin ' . escapeshellarg($project['repository']));
    }
    run('git -C ' . escapeshellarg($directory) . ' fetch -q --depth 1 origin ' . escapeshellarg($project['commit']));
    run('git -C ' . escapeshellarg($directory) . ' -c advice.detachedHead=false checkout -q FETCH_HEAD');
}

/**
 * The directories holding an application composer.json: the root by default, or the "composer" globs of the project.
 *
 * @param array{name: string, repository: string, commit: string, stack: list<string>, why: string, composer?: list<string>} $project
 *
 * @return list<string> paths relative to the checkout, "." for the root
 */
function composerDirectories(array $project, string $directory): array
{
    $directories = [];
    foreach ($project['composer'] ?? ['.'] as $pattern) {
        foreach (glob($directory . '/' . $pattern . '/composer.json') ?: [] as $file) {
            $relative = substr(dirname($file), strlen($directory) + 1);
            $directories[] = $relative === '' || $relative === '.' ? '.' : $relative;
        }
    }

    return array_values(array_unique($directories));
}

/**
 * Installs vendor/ without running any project code (no scripts, no plugins) and without platform checks.
 * A project without composer.lock is resolved once and its lock kept in corpus/locks, so measurements stay
 * reproducible. A failure is reported and the project is measured without its dependencies.
 */
function installDependencies(string $name, string $directory, string $relative): void
{
    $target = $relative === '.' ? $directory : $directory . '/' . $relative;
    $label = $relative === '.' ? $name : $name . '/' . $relative;
    if (is_file($target . '/vendor/composer/installed.json')) {
        echo "{$label}: dependencies already installed\n";

        return;
    }

    $savedLock = LOCKS_DIR . '/' . $name . ($relative === '.' ? '' : '/' . $relative) . '/composer.lock';
    $hasOwnLock = is_file($target . '/composer.lock');
    if (!$hasOwnLock && is_file($savedLock)) {
        copy($savedLock, $target . '/composer.lock');
    }
    $resolve = !is_file($target . '/composer.lock');

    echo "{$label}: " . ($resolve ? 'resolving and installing' : 'installing') . " dependencies\n";
    $command = sprintf(
        'cd %s && COMPOSER_CACHE_DIR=%s COMPOSER_MEMORY_LIMIT=-1 composer %s -q --no-interaction --no-scripts --no-plugins --ignore-platform-reqs --prefer-dist 2>&1',
        escapeshellarg($target),
        escapeshellarg(CHECKOUTS_DIR . '/.composer-cache'),
        $resolve ? 'update' : 'install',
    );
    exec($command, $output, $code);
    if ($code !== 0) {
        fwrite(STDERR, "{$label}: installing dependencies failed, measured without them\n" . implode("\n", array_slice($output, -10)) . "\n");

        return;
    }

    if ($resolve) {
        @mkdir(dirname($savedLock), 0775, true);
        copy($target . '/composer.lock', $savedLock);
    }
}

/**
 * Runs in its own process so time and peak memory belong to this project only.
 *
 * @param array{name: string, repository: string, commit: string, stack: list<string>, why: string, composer?: list<string>} $project
 *
 * @return array<string, mixed>
 */
function measureOne(array $project): array
{
    $directory = CHECKOUTS_DIR . '/' . $project['name'];
    if (!is_dir($directory)) {
        fail(sprintf('%s is not fetched, run "php tools/corpus.php fetch %s".', $project['name'], $project['name']));
    }

    $start = hrtime(true);
    $result = (new GraphBuilder())->build($directory);
    $seconds = (hrtime(true) - $start) / 1e9;

    $graph = $result->graph;
    $edgesByConfidence = array_fill_keys(array_map(static fn (Confidence $c): string => $c->value, Confidence::cases()), 0);
    foreach ($graph->edges() as $edge) {
        ++$edgesByConfidence[$edge->confidence->value];
    }
    $nodesByKind = [];
    foreach ($graph->nodes() as $node) {
        $nodesByKind[$node->kind->value] = ($nodesByKind[$node->kind->value] ?? 0) + 1;
    }

    return [
        'name' => $project['name'],
        'commit' => $project['commit'],
        'filesParsed' => $result->filesParsed,
        'filesFailed' => count($result->failures),
        'duplicates' => count($result->duplicates),
        'nodes' => $graph->nodeCount(),
        'classLike' => array_sum(array_map(
            static fn (NodeKind $kind): int => $nodesByKind[$kind->value] ?? 0,
            array_filter(NodeKind::cases(), static fn (NodeKind $kind): bool => $kind->isClassLike()),
        )),
        'methods' => $nodesByKind[NodeKind::Method->value] ?? 0,
        'external' => $nodesByKind[NodeKind::External->value] ?? 0,
        'edges' => $graph->edgeCount(),
        'vendorFilesRead' => $result->vendorFilesRead,
        'edgesByConfidence' => $edgesByConfidence,
        'calls' => $result->calls->toArray(),
        'applicationCalls' => $result->applicationCalls()->toArray(),
        'testCalls' => $result->testCalls->toArray(),
        'bus' => $result->bus->toArray(),
        'http' => $result->http->toArray(),
        'injections' => $result->injections->toArray(),
        'services' => count($result->services),
        'architecture' => (static function () use ($graph): array {
            $architecture = (new GraphQuery($graph))->architecture();

            return [
                'layerViolations' => count($architecture->violations),
                'contextPairs' => count($architecture->contextDependencies),
                'layerFirst' => $architecture->layerFirst,
            ];
        })(),
        'seconds' => round($seconds, 2),
        'peakMemoryMb' => (int) round(memory_get_peak_usage(true) / 1048576),
    ];
}

/**
 * @param array{name: string, repository: string, commit: string, stack: list<string>, why: string, composer?: list<string>} $project
 *
 * @return array<string, mixed>
 */
function measureInSubprocess(array $project): array
{
    $command = sprintf('%s -d memory_limit=-1 %s measure-one %s', escapeshellarg(PHP_BINARY), escapeshellarg(__FILE__), escapeshellarg($project['name']));
    $output = shell_exec($command);
    $data = is_string($output) ? json_decode($output, true) : null;
    if (!is_array($data)) {
        fail(sprintf("Measuring %s failed:\n%s", $project['name'], (string) $output));
    }

    return $data;
}

function percent(int $part, int $total): string
{
    return $total === 0 ? '-' : sprintf('%.1f%%', 100 * $part / $total);
}

/**
 * @param float $tolerance relative change ignored as noise (build time varies between runs)
 */
function delta(float|int $value, float|int|null $before, bool $lowerIsBetter = false, float $tolerance = 0.0): string
{
    if ($before === null || $before == $value) {
        return '';
    }
    if ($tolerance > 0 && abs($value - $before) <= $tolerance * abs($before)) {
        return '';
    }

    $difference = $value - $before;
    $better = $lowerIsBetter ? $difference < 0 : $difference > 0;

    return sprintf(' (%s%s %s)', $difference > 0 ? '+' : '', is_float($difference) ? round($difference, 2) : $difference, $better ? '▲' : '▼');
}

/**
 * @param list<array<string, mixed>>   $results
 * @param array<string, array<string, mixed>> $baseline
 */
function render(array $results, array $baseline): string
{
    $lines = [
        '# Corpus results',
        '',
        'Generated by `php tools/corpus.php measure`. Calls are method call sites; "unknown receiver" is the share',
        'phpgraph could not type, the main target of type inference work. Arrows compare with `baseline.json`',
        '(▲ better, ▼ worse). Seconds and memory ignore changes under 20%: build time varies between runs.',
        '',
        '| Project | Files | Failed | Duplicates | Classes | Methods | Edges | Vendor files read | Seconds | Memory MB |',
        '|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|',
    ];

    foreach ($results as $r) {
        $b = $baseline[$r['name']] ?? null;
        $lines[] = sprintf(
            '| %s | %d%s | %d%s | %d%s | %d | %d | %d%s | %d | %.2f%s | %d%s |',
            $r['name'],
            $r['filesParsed'],
            delta($r['filesParsed'], $b['filesParsed'] ?? null),
            $r['filesFailed'],
            delta($r['filesFailed'], $b['filesFailed'] ?? null, true),
            $r['duplicates'],
            delta($r['duplicates'], $b['duplicates'] ?? null, true),
            $r['classLike'],
            $r['methods'],
            $r['edges'],
            delta($r['edges'], $b['edges'] ?? null),
            $r['vendorFilesRead'] ?? 0,
            $r['seconds'],
            delta($r['seconds'], $b['seconds'] ?? null, true, 0.2),
            $r['peakMemoryMb'],
            delta($r['peakMemoryMb'], $b['peakMemoryMb'] ?? null, true, 0.2),
        );
    }

    $sections = [
        'applicationCalls' => ['Method calls in application code', 'The main indicator: test code (see `TestFiles`) is left out.'],
        'testCalls' => ['Method calls in test code', 'Mocks and specs make test code harder to type; read it apart.'],
        'calls' => ['Method calls in all code', 'Application and test code together.'],
    ];

    foreach ($sections as $key => [$title, $intro]) {
        $lines[] = '';
        $lines[] = '## ' . $title;
        $lines[] = '';
        $lines[] = $intro;
        $lines[] = '';
        array_push($lines, ...callTable($results, $baseline, $key));
    }

    $lines[] = '';
    $lines[] = '## Architecture of application code';
    $lines[] = '';
    $lines[] = 'Class dependencies breaking the default layer rules, and pairs of bounded contexts depending on each other.';
    $lines[] = '';
    $lines[] = '| Project | Layer violations | Context pairs | Contexts read |';
    $lines[] = '|---|---:|---:|---|';
    foreach ($results as $r) {
        $a = $r['architecture'] ?? [];
        $b = $baseline[$r['name']]['architecture'] ?? null;
        $lines[] = sprintf(
            '| %s | %d%s | %d | %s |',
            $r['name'],
            $a['layerViolations'] ?? 0,
            delta($a['layerViolations'] ?? 0, $b['layerViolations'] ?? null, true),
            $a['contextPairs'] ?? 0,
            ($a['contextPairs'] ?? 0) === 0 ? '-' : (($a['layerFirst'] ?? false) ? 'after the layer' : 'before the layer'),
        );
    }
    $lines[] = '';
    $lines[] = '## HTTP and services';
    $lines[] = '';
    $lines[] = 'Routes and the controllers handling them; HTTP calls of application code reaching a route; services found.';
    $lines[] = '';
    $lines[] = '| Project | Services | Routes | With controller | Controller in dependencies | Controller not found | HTTP calls | To a route | To another service | Wrong method |';
    $lines[] = '|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|';
    foreach ($results as $r) {
        $h = $r['http'] ?? [];
        $b = $baseline[$r['name']]['http'] ?? null;
        $lines[] = sprintf(
            '| %s | %d | %d%s | %d%s | %d | %d | %d | %d | %d | %d |',
            $r['name'],
            $r['services'] ?? 0,
            $h['routes'] ?? 0,
            delta($h['routes'] ?? 0, $b['routes'] ?? null),
            $h['routesWithHandler'] ?? 0,
            delta($h['routesWithHandler'] ?? 0, $b['routesWithHandler'] ?? null),
            $h['routesToDependencies'] ?? 0,
            $h['routesToMissingControllers'] ?? 0,
            $h['requests'] ?? 0,
            $h['requestsToProject'] ?? 0,
            $h['requestsToServices'] ?? 0,
            $h['methodMismatchCount'] ?? 0,
        );
    }
    $lines[] = '';
    $lines[] = '## Container injections';
    $lines[] = '';
    $lines[] = 'What the container configuration injects by tag or by id (`receives`), and what could not be linked.';
    $lines[] = '';
    $lines[] = '| Project | Injections | Linked | Receives edges | Not linked |';
    $lines[] = '|---|---:|---:|---:|---:|';
    foreach ($results as $r) {
        $i = $r['injections'] ?? [];
        $b = $baseline[$r['name']]['injections'] ?? null;
        $lines[] = sprintf(
            '| %s | %d | %d%s | %d | %d |',
            $r['name'],
            $i['injections'] ?? 0,
            $i['linked'] ?? 0,
            delta($i['linked'] ?? 0, $b['linked'] ?? null),
            $i['edges'] ?? 0,
            $i['unlinkedCount'] ?? 0,
        );
    }
    $lines[] = '';
    $lines[] = '## Messages in application code';
    $lines[] = '';
    $lines[] = 'Handlers and sends linked to their message class, by confidence; then what could not be linked.';
    $lines[] = '';
    $lines[] = '| Project | Handlers EXTRACTED | INFERRED | AMBIGUOUS | Sends INFERRED | AMBIGUOUS | Untyped sends | Sent, no handler | Handled, never sent | Contracts between services |';
    $lines[] = '|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|';
    foreach ($results as $r) {
        $bus = $r['bus'] ?? [];
        $b = $baseline[$r['name']]['bus'] ?? null;
        $count = static fn (?array $values, string $key): int => (int) ($values[$key] ?? 0);
        $lines[] = sprintf(
            '| %s | %d%s | %d%s | %d | %d%s | %d | %d | %d | %d | %d |',
            $r['name'],
            $count($bus['handlers'] ?? null, 'EXTRACTED'),
            delta($count($bus['handlers'] ?? null, 'EXTRACTED'), $b === null ? null : $count($b['handlers'] ?? null, 'EXTRACTED')),
            $count($bus['handlers'] ?? null, 'INFERRED'),
            delta($count($bus['handlers'] ?? null, 'INFERRED'), $b === null ? null : $count($b['handlers'] ?? null, 'INFERRED')),
            $count($bus['handlers'] ?? null, 'AMBIGUOUS'),
            $count($bus['sends'] ?? null, 'INFERRED'),
            delta($count($bus['sends'] ?? null, 'INFERRED'), $b === null ? null : $count($b['sends'] ?? null, 'INFERRED')),
            $count($bus['sends'] ?? null, 'AMBIGUOUS'),
            $bus['untypedSends'] ?? 0,
            $bus['messagesWithoutHandlerCount'] ?? 0,
            $bus['messagesNeverSentCount'] ?? 0,
            $bus['contracts'] ?? 0,
        );
    }
    $lines[] = '';

    return implode("\n", $lines);
}

/**
 * @param list<array<string, mixed>>          $results
 * @param array<string, array<string, mixed>> $baseline
 *
 * @return list<string>
 */
function callTable(array $results, array $baseline, string $key): array
{
    $lines = [
        '| Project | Call sites | Inferred | Ambiguous | Outside project | Unknown receiver | of which chain left project |',
        '|---|---:|---:|---:|---:|---:|---:|',
    ];

    foreach ($results as $r) {
        $c = $r[$key];
        $bc = $baseline[$r['name']][$key] ?? null;
        $lines[] = sprintf(
            '| %s | %d | %s%s | %s | %s | %s%s | %s |',
            $r['name'],
            $c['total'],
            percent($c['inferred'], $c['total']),
            delta($c['inferred'], $bc['inferred'] ?? null),
            percent($c['ambiguous'], $c['total']),
            percent($c['outsideProject'], $c['total']),
            percent($c['unknownReceiver'], $c['total']),
            delta($c['unknownReceiver'], $bc['unknownReceiver'] ?? null, true),
            percent($c['chainOutsideProject'] ?? 0, $c['total']),
        );
    }

    return $lines;
}

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
$arguments = array_slice($argv, 1);
$command = array_shift($arguments) ?? 'help';
$writeBaseline = in_array('--baseline', $arguments, true);
$names = array_values(array_filter($arguments, static fn (string $argument): bool => !str_starts_with($argument, '--')));

switch ($command) {
    case 'fetch':
        foreach (projects($names) as $project) {
            fetch($project);
        }
        break;

    case 'measure-one':
        echo json_encode(measureOne(projects($names)[0]), JSON_THROW_ON_ERROR);
        break;

    case 'measure':
        $baseline = is_file(BASELINE_FILE)
            ? array_column(json_decode((string) file_get_contents(BASELINE_FILE), true, 512, JSON_THROW_ON_ERROR), null, 'name')
            : [];

        $results = [];
        foreach (projects($names) as $project) {
            fwrite(STDERR, $project['name'] . "...\n");
            $results[] = measureInSubprocess($project);
        }

        if ($writeBaseline) {
            $merged = array_values(array_merge($baseline, array_column($results, null, 'name')));
            file_put_contents(BASELINE_FILE, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            $baseline = [];
        }

        $report = render($results, $baseline);
        if ($names === []) {
            file_put_contents(RESULTS_FILE, $report);
        }
        echo $report;
        break;

    default:
        echo "Usage: php tools/corpus.php fetch|measure [--baseline] [name...]\n";
}
