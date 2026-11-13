<?php

declare(strict_types=1);

/*
 * Agent benchmark: Claude Code headless (`claude -p`) on the tasks of tools/agent-bench/tasks/, with phpgraph (its MCP
 * server) and without (shell, grep and file reading only). Run it through tools/agent-bench/bench, which provides PHP,
 * git and Claude Code in a container:
 *
 *   run.php [run] [--tasks=ddd-*,sylius-*] [--arms=phpgraph,baseline] [--runs=3] [--model=sonnet]
 *           [--max-tokens=3000000] [--timeout=900] [--keep] [--resume=<batch>]
 *   run.php report [build/agent-bench/results/<batch>.jsonl]
 *   run.php check [task patterns]       patches apply, expected classes are declared (no agent)
 *
 * Each run works on a throwaway copy of the corpus checkout (without .git, so that a seeded bug does not show in
 * `git diff`, and without phpgraph-out), with the task's patch applied. The phpgraph arm gets a graph built before the
 * run, written outside the copy. Results: one JSON line per run in build/agent-bench/results/<batch>.jsonl, the stream
 * and the changes of each run in build/agent-bench/runs/<batch>/; `report` prints the tables of docs/BENCHMARK.md.
 */

use PhpGraph\Values;
use Symfony\Component\Yaml\Yaml;

require __DIR__ . '/../../vendor/autoload.php';

const ROOT = __DIR__ . '/../..';
const TASKS_DIR = __DIR__ . '/tasks';
const OUT_DIR = ROOT . '/build/agent-bench';
const CHECKOUTS_DIR = ROOT . '/corpus/checkouts';
const ARMS = ['phpgraph', 'baseline'];
const TOOLS = 'Bash,Read,Grep,Glob,Edit,Write';
const ANSWER_SCHEMA = [
    'type' => 'object',
    'properties' => [
        'classes' => ['type' => 'array', 'items' => ['type' => 'string']],
        'explanation' => ['type' => 'string'],
    ],
    'required' => ['classes', 'explanation'],
];
const ANSWER_INSTRUCTIONS = "\n\nDo not modify any file. Answer in the structured output: `classes` lists the fully qualified names of the "
    . "project's classes and interfaces that answer the question, and nothing else; `explanation` says briefly why.";
const FIX_INSTRUCTIONS = "\n\nFix the cause in the source code. Do not modify the tests. When the tests pass, reply with one line saying "
    . 'what the bug was.';

final readonly class Task
{
    /**
     * @param list<string> $expected  classes the answer must give (recall)
     * @param list<string> $allowed   classes the answer may give besides (precision)
     * @param list<string> $tests     commands that must pass after the run; the first fails before it
     * @param list<string> $protected directories the agent must not change
     */
    public function __construct(
        public string $id,
        public string $project,
        public string $kind,
        public string $prompt,
        public array $expected,
        public array $allowed,
        public float $minRecall,
        public float $minPrecision,
        public ?string $patch,
        public array $tests,
        public array $protected,
    ) {
    }

    public static function fromFile(string $file): self
    {
        $id = basename($file, '.yaml');
        $data = Yaml::parseFile($file);
        $data = is_array($data) ? $data : [];
        $kind = Values::text($data['kind'] ?? null);
        if (!in_array($kind, ['find', 'impact', 'explain', 'fix'], true)) {
            fail("Task {$id}: unknown kind '{$kind}'");
        }
        $project = Values::text($data['project'] ?? null);
        if (!is_dir(CHECKOUTS_DIR . '/' . $project)) {
            fail("Task {$id}: corpus/checkouts/{$project} is missing (composer corpus:fetch)");
        }
        $patch = Values::optionalText($data['patch'] ?? null);

        return new self(
            $id,
            $project,
            $kind,
            trim(Values::text($data['prompt'] ?? null)),
            texts($data['expected'] ?? null),
            texts($data['allowed'] ?? null),
            (float) Values::text($data['min_recall'] ?? 1),
            (float) Values::text($data['min_precision'] ?? 1),
            $patch === null ? null : dirname($file) . '/' . $patch,
            texts($data['tests'] ?? null),
            texts($data['protected'] ?? null),
        );
    }

    public function answers(): bool
    {
        return $this->kind !== 'fix';
    }
}

final readonly class Options
{
    /**
     * @param list<string> $tasks patterns of task ids
     * @param list<string> $arms
     */
    public function __construct(
        public array $tasks = ['*'],
        public array $arms = ARMS,
        public int $runs = 3,
        public string $model = 'sonnet',
        public int $maxTokens = 3_000_000,
        public int $timeout = 900,
        public bool $keep = false,
        public ?string $resume = null,
    ) {
    }

    /**
     * @param list<string> $arguments
     */
    public static function parse(array $arguments): self
    {
        $values = [];
        foreach ($arguments as $argument) {
            if ($argument === 'run') {
                continue;
            }
            if ($argument === '--keep') {
                $values['keep'] = true;

                continue;
            }
            if (preg_match('/^--([a-z-]+)=(.+)$/', $argument, $match) !== 1) {
                fail('Unknown argument: ' . $argument);
            }
            match ($match[1]) {
                'tasks' => $values['tasks'] = explode(',', $match[2]),
                'arms' => $values['arms'] = self::arms(explode(',', $match[2])),
                'runs' => $values['runs'] = max(1, (int) $match[2]),
                'model' => $values['model'] = $match[2],
                'max-tokens' => $values['maxTokens'] = max(1, (int) $match[2]),
                'timeout' => $values['timeout'] = max(1, (int) $match[2]),
                'resume' => $values['resume'] = $match[2],
                default => fail('Unknown option: --' . $match[1]),
            };
        }

        return new self(...$values);
    }

    /**
     * @param list<string> $arms
     *
     * @return list<string>
     */
    private static function arms(array $arms): array
    {
        $unknown = array_diff($arms, ARMS);
        if ($unknown !== []) {
            fail('Unknown arm: ' . implode(', ', $unknown));
        }

        return $arms;
    }
}

final readonly class Usage
{
    public function __construct(
        public int $input = 0,
        public int $cacheCreation = 0,
        public int $cacheRead = 0,
        public int $output = 0,
    ) {
    }

    public static function fromEvent(mixed $usage): self
    {
        $usage = is_array($usage) ? $usage : [];

        return new self(
            Values::number($usage['input_tokens'] ?? 0),
            Values::number($usage['cache_creation_input_tokens'] ?? 0),
            Values::number($usage['cache_read_input_tokens'] ?? 0),
            Values::number($usage['output_tokens'] ?? 0),
        );
    }

    public function plus(self $other): self
    {
        return new self(
            $this->input + $other->input,
            $this->cacheCreation + $other->cacheCreation,
            $this->cacheRead + $other->cacheRead,
            $this->output + $other->output,
        );
    }

    public function total(): int
    {
        return $this->input + $this->cacheCreation + $this->cacheRead + $this->output;
    }

    /**
     * @return array{input: int, cache_creation: int, cache_read: int, output: int, total: int}
     */
    public function toArray(): array
    {
        return [
            'input' => $this->input,
            'cache_creation' => $this->cacheCreation,
            'cache_read' => $this->cacheRead,
            'output' => $this->output,
            'total' => $this->total(),
        ];
    }
}

/**
 * What one `claude -p` run did, read from its stream-json events.
 */
final class Session
{
    public ?Usage $finalUsage = null;
    public float $costUsd = 0.0;
    public int $turns = 0;
    public mixed $answer = null;
    public string $result = '';
    public ?string $error = null;
    public bool $capped = false;
    public string $model = '';
    public string $version = '';
    public float $seconds = 0.0;

    /** HTTP status of an API error that ended the run (usage limit, overload): the run says nothing of the agent */
    public ?int $apiErrorStatus = null;

    /** @var list<string> */
    public array $commands = [];

    /** @var array<string, Usage> usage per assistant message: each content block repeats it */
    private array $usages = [];

    /** @var array<string, string> tool name per tool use */
    private array $toolUses = [];

    public function finished(): bool
    {
        return $this->finalUsage !== null;
    }

    public function usage(): Usage
    {
        return $this->finalUsage ?? array_reduce($this->usages, static fn (Usage $sum, Usage $usage): Usage => $sum->plus($usage), new Usage());
    }

    /**
     * @return array<string, int>
     */
    public function tools(): array
    {
        $tools = array_count_values($this->toolUses);
        ksort($tools);

        return $tools;
    }

    public function read(string $line): void
    {
        $event = json_decode($line, true);
        if (!is_array($event)) {
            return;
        }
        $type = Values::text($event['type'] ?? null);
        if ($type === 'system' && ($event['subtype'] ?? null) === 'init') {
            $this->model = Values::text($event['model'] ?? null);
            $this->version = Values::text($event['claude_code_version'] ?? null);
        }
        if ($type === 'assistant' && is_array($event['message'] ?? null)) {
            $this->readMessage($event['message']);
        }
        if ($type === 'result') {
            $this->readResult($event);
        }
    }

    /**
     * @param array<mixed> $message
     */
    private function readMessage(array $message): void
    {
        $this->usages[Values::text($message['id'] ?? count($this->usages))] = Usage::fromEvent($message['usage'] ?? null);
        foreach (Values::records($message['content'] ?? null) as $content) {
            $name = Values::text($content['name'] ?? null);
            if (($content['type'] ?? null) !== 'tool_use' || $name === 'StructuredOutput') {
                continue;
            }
            $this->toolUses[Values::text($content['id'] ?? count($this->toolUses))] = $name;
            $input = $content['input'] ?? null;
            if ($name === 'Bash' && is_array($input)) {
                $this->commands[] = Values::text($input['command'] ?? null);
            }
        }
    }

    /**
     * @param array<mixed> $event
     */
    private function readResult(array $event): void
    {
        $this->finalUsage = Usage::fromEvent($event['usage'] ?? null);
        $this->costUsd = round((float) Values::text($event['total_cost_usd'] ?? 0), 4);
        $this->turns = Values::number($event['num_turns'] ?? 0);
        $this->answer = $event['structured_output'] ?? null;
        $this->result = Values::text($event['result'] ?? null);
        $this->apiErrorStatus = Values::optionalNumber($event['api_error_status'] ?? null);
        if ($this->apiErrorStatus !== null) {
            $this->error = "API error {$this->apiErrorStatus}: {$this->result}";

            return;
        }
        if (($event['is_error'] ?? false) === true || ($event['subtype'] ?? null) !== 'success') {
            $this->error = 'agent error: ' . Values::text($event['subtype'] ?? null);
        }
    }
}

/**
 * @param list<string> $arguments
 */
function main(array $arguments): void
{
    $command = $arguments[0] ?? 'run';
    if ($command === 'report') {
        echo report(resultsFile($arguments[1] ?? null));

        return;
    }
    if ($command === 'check') {
        checkTasks(tasks(array_slice($arguments, 1) === [] ? ['*'] : array_slice($arguments, 1)));

        return;
    }
    if ($command !== 'run' && !str_starts_with($command, '--')) {
        fail('Unknown command: ' . $command);
    }
    runBenchmark(Options::parse($arguments));
}

/**
 * Checks the tasks without any agent: each patch applies to its checkout, each expected or allowed class is declared
 * in the checkout (outside vendor/, or in it for an allowed class). The classes are read in the source text, never with
 * phpgraph, so that the ground truth does not depend on the tool it grades.
 *
 * @param list<Task> $tasks
 */
function checkTasks(array $tasks): void
{
    $declared = [];
    $problems = 0;
    foreach ($tasks as $task) {
        $checkout = CHECKOUTS_DIR . '/' . $task->project;
        $declared[$task->project] ??= declaredClasses($checkout);
        $messages = [];
        if ($task->patch !== null && execute(['git', 'apply', '--check', $task->patch], $checkout, 60)['code'] !== 0) {
            $messages[] = 'patch does not apply';
        }
        foreach ([...$task->expected, ...$task->allowed] as $class) {
            $where = $declared[$task->project][className($class)] ?? null;
            if ($where === null || (in_array($class, $task->expected, true) && $where === 'vendor')) {
                $messages[] = 'not declared in the project: ' . $class;
            }
        }
        if ($task->answers() && $task->expected === []) {
            $messages[] = 'no expected class';
        }
        if (!$task->answers() && ($task->patch === null || $task->tests === [])) {
            $messages[] = 'a fix task needs a patch and tests';
        }
        $problems += count($messages);
        printf("%-40s %-8s %s\n", $task->id, $task->kind, $messages === [] ? 'ok' : implode('; ', $messages));
    }
    if ($problems > 0) {
        fail("{$problems} problem(s)");
    }
}

/**
 * Classes, interfaces, traits and enums declared in a project, by lower-case FQCN: 'project' or 'vendor'.
 *
 * @return array<string, 'project'|'vendor'>
 */
function declaredClasses(string $checkout): array
{
    $declared = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($checkout, FilesystemIterator::SKIP_DOTS),
        static fn (SplFileInfo $file): bool => !($file->isDir() && in_array($file->getFilename(), ['.git', 'node_modules', 'phpgraph-out', 'var', 'cache'], true)),
    ));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $code = (string) file_get_contents($file->getPathname());
        $namespace = preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $code, $match) === 1 ? $match[1] . '\\' : '';
        $where = str_contains($file->getPathname(), '/vendor/') ? 'vendor' : 'project';
        preg_match_all('/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $code, $matches);
        foreach ($matches[1] as $name) {
            $key = strtolower($namespace . $name);
            if (($declared[$key] ?? null) !== 'project') {
                $declared[$key] = $where;
            }
        }
    }

    return $declared;
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function directory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0o777, true)) {
        fail('Cannot create ' . $path);
    }
}

/**
 * @return list<string>
 */
function texts(mixed $values): array
{
    return array_values(array_map(Values::text(...), is_array($values) ? $values : []));
}

function runBenchmark(Options $options): void
{
    $tasks = tasks($options->tasks);
    if ($tasks === []) {
        fail('No task matches ' . implode(',', $options->tasks));
    }
    $batch = $options->resume ?? date('Ymd-His');
    $results = OUT_DIR . '/results/' . $batch . '.jsonl';
    directory(dirname($results));
    $done = $options->resume === null ? [] : keptRuns($results);

    foreach ($tasks as $task) {
        for ($run = 1; $run <= $options->runs; ++$run) {
            foreach ($options->arms as $arm) {
                if (isset($done["{$task->id} {$arm} {$run}"])) {
                    continue;
                }
                $record = runTask($task, $arm, $run, $options, OUT_DIR . "/runs/{$batch}/{$task->id}-{$arm}-{$run}");
                if ($record['api_error']) {
                    fail("{$task->id} {$arm} #{$run}: {$record['error']}\nNothing recorded for this run; continue with --resume={$batch}");
                }
                file_put_contents($results, json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
                fwrite(STDERR, sprintf(
                    "%s %s #%d: %s, %s tokens, %.0f s%s\n",
                    $task->id,
                    $arm,
                    $run,
                    $record['success'] ? 'success' : 'failure',
                    number_format($record['tokens']['total']),
                    $record['seconds'],
                    $record['error'] === null ? '' : ' (' . $record['error'] . ')',
                ));
            }
        }
    }

    echo report($results);
    fwrite(STDERR, "Results: build/agent-bench/results/{$batch}.jsonl\n");
}

/**
 * The runs of a batch to keep when it is resumed, by "task arm run": the others are run again. A run ended by an API
 * error (usage limit, overload) is not kept; batches recorded before the field existed show it in the result text.
 *
 * @return array<string, true>
 */
function keptRuns(string $results): array
{
    $kept = [];
    $lines = [];
    foreach (file($results, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $record = json_decode($line, true);
        if (!is_array($record) || brokenByApi($record)) {
            continue;
        }
        $kept[Values::text($record['task'] ?? null) . ' ' . Values::text($record['arm'] ?? null) . ' ' . Values::text($record['run'] ?? null)] = true;
        $lines[] = $line . "\n";
    }
    file_put_contents($results, implode('', $lines));

    return $kept;
}

/**
 * @param array<mixed> $record
 */
function brokenByApi(array $record): bool
{
    if (($record['api_error'] ?? false) === true) {
        return true;
    }

    return str_starts_with(Values::text($record['error'] ?? null), 'agent error')
        && preg_match('/hit your .*limit/i', Values::text($record['result'] ?? null)) === 1;
}

/**
 * @param list<string> $patterns
 *
 * @return list<Task>
 */
function tasks(array $patterns): array
{
    $tasks = [];
    foreach (glob(TASKS_DIR . '/*.yaml') ?: [] as $file) {
        $id = basename($file, '.yaml');
        if (array_any($patterns, static fn (string $pattern): bool => fnmatch($pattern, $id))) {
            $tasks[] = Task::fromFile($file);
        }
    }

    return $tasks;
}

/**
 * @return array{task: string, kind: string, arm: string, run: int, success: bool, recall: ?float, precision: ?float,
 *     found: list<string>, missed: list<string>, extra: list<string>, tests_failed_before: ?bool, tests_pass: ?bool,
 *     protected_changed: list<string>, changed: list<string>, build_seconds: ?float, used_phpgraph_in_shell: bool,
 *     tokens: array{input: int, cache_creation: int, cache_read: int, output: int, total: int}, cost_usd: float,
 *     turns: int, tools: array<string, int>, seconds: float, capped: bool, error: ?string, api_error: bool, model: string,
 *     claude_code: string, answer: mixed, result: string}
 */
function runTask(Task $task, string $arm, int $run, Options $options, string $directory): array
{
    $project = $directory . '/project';
    $graph = $directory . '/graph';
    directory($directory);
    prepare($task, $project);

    $failedBefore = null;
    if ($task->kind === 'fix') {
        $failedBefore = !testsPass([$task->tests[0] ?? 'false'], $project);
    }
    $before = snapshot($project);

    $buildSeconds = null;
    $servers = [];
    if ($arm === 'phpgraph') {
        $build = execute(['php', ROOT . '/bin/phpgraph', 'build', $project, '-o', $graph, '--quiet'], null, 1800);
        if ($build['code'] !== 0) {
            fail("phpgraph build failed for {$task->id}:\n" . $build['output']);
        }
        $buildSeconds = round($build['seconds'], 1);
        $servers['phpgraph'] = ['command' => 'php', 'args' => [(string) realpath(ROOT . '/bin/phpgraph'), 'serve', $project, '-o', $graph]];
    }
    file_put_contents($directory . '/mcp.json', json_encode(['mcpServers' => (object) $servers], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

    $session = agent($task, $options, $project, $directory);

    $changed = changedFiles($before, snapshot($project));
    file_put_contents($directory . '/changes.diff', changes(CHECKOUTS_DIR . '/' . $task->project, $project, $changed));
    $protectedChanged = array_values(array_filter(
        $changed,
        static fn (string $file): bool => array_any($task->protected, static fn (string $prefix): bool => str_starts_with($file, rtrim($prefix, '/') . '/')),
    ));

    $score = ['recall' => null, 'precision' => null, 'found' => [], 'missed' => [], 'extra' => []];
    $testsPass = null;
    if ($task->answers()) {
        $score = score($task, answeredClasses($session->answer));
        $success = $session->error === null && $score['recall'] >= $task->minRecall && $score['precision'] >= $task->minPrecision;
    } else {
        $testsPass = $session->error === null && testsPass($task->tests, $project);
        $success = $failedBefore === true && $testsPass && $protectedChanged === [];
    }

    if (!$options->keep) {
        execute(['rm', '-rf', $project, $graph], null, 300);
    }

    return [
        'task' => $task->id,
        'kind' => $task->kind,
        'arm' => $arm,
        'run' => $run,
        'success' => $success,
        ...$score,
        'tests_failed_before' => $failedBefore,
        'tests_pass' => $testsPass,
        'protected_changed' => $protectedChanged,
        'changed' => $changed,
        'build_seconds' => $buildSeconds,
        'used_phpgraph_in_shell' => usedPhpgraph($session->commands, $directory),
        'tokens' => $session->usage()->toArray(),
        'cost_usd' => $session->costUsd,
        'turns' => $session->turns,
        'tools' => $session->tools(),
        'seconds' => $session->seconds,
        'capped' => $session->capped,
        'error' => $session->error,
        'api_error' => $session->apiErrorStatus !== null,
        'model' => $session->model,
        'claude_code' => $session->version,
        'answer' => $session->answer,
        'result' => $session->result,
    ];
}

/**
 * Whether a shell command of the agent ran or read phpgraph: the arm without it must not. The run's directory lives in
 * this repository and is named after the arm, so a path into build/agent-bench/ (even written with a glob) is not a use.
 *
 * @param list<string> $commands
 */
function usedPhpgraph(array $commands, string $directory): bool
{
    $run = (string) realpath($directory);
    $copies = static fn (string $command): string => preg_replace('~\S*build/agent-bench/\S*~', '', str_replace($run, '', $command)) ?? $command;

    return array_any($commands, static fn (string $command): bool => str_contains($copies($command), 'phpgraph'));
}

function prepare(Task $task, string $project): void
{
    execute(['rm', '-rf', $project], null, 300);
    $copy = execute(['cp', '-a', CHECKOUTS_DIR . '/' . $task->project, $project], null, 600);
    if ($copy['code'] !== 0) {
        fail('Copy failed: ' . $copy['output']);
    }
    execute(['rm', '-rf', $project . '/.git', $project . '/phpgraph-out'], null, 300);
    if ($task->patch === null) {
        return;
    }
    $patch = execute(['git', 'apply', $task->patch], $project, 60);
    if ($patch['code'] !== 0) {
        fail("Patch of {$task->id} does not apply:\n" . $patch['output']);
    }
}

/**
 * @param list<string> $commands
 */
function testsPass(array $commands, string $project): bool
{
    foreach ($commands as $command) {
        if (execute(['sh', '-c', $command], $project, 600)['code'] !== 0) {
            return false;
        }
    }

    return true;
}

/**
 * The files of the copy outside vendor/, with a hash of their content.
 *
 * @return array<string, string>
 */
function snapshot(string $project): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($project, FilesystemIterator::SKIP_DOTS),
        static fn (SplFileInfo $file): bool => !($file->isDir() && in_array($file->getFilename(), ['vendor', 'node_modules', 'var'], true)),
    ));
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getFilename() !== '.phpunit.result.cache') {
            $files[substr($file->getPathname(), strlen($project) + 1)] = (string) sha1_file($file->getPathname());
        }
    }

    return $files;
}

/**
 * @param array<string, string> $before
 * @param array<string, string> $after
 *
 * @return list<string>
 */
function changedFiles(array $before, array $after): array
{
    $changed = array_map(strval(...), array_keys(array_diff_assoc($after, $before) + array_diff_key($before, $after)));
    sort($changed);

    return $changed;
}

/**
 * The agent's changes, against the original checkout (so a fix shows how far it is from the code before the seed).
 *
 * @param list<string> $files
 */
function changes(string $original, string $project, array $files): string
{
    $diff = '';
    foreach ($files as $file) {
        $from = is_file($original . '/' . $file) ? $original . '/' . $file : '/dev/null';
        $to = is_file($project . '/' . $file) ? $project . '/' . $file : '/dev/null';
        $diff .= execute(['git', 'diff', '--no-index', '--no-color', '--', $from, $to], null, 60)['output'] . "\n";
    }

    return str_replace([ltrim($original, '/') . '/', ltrim($project, '/') . '/'], '', $diff);
}

function agent(Task $task, Options $options, string $project, string $directory): Session
{
    $command = [
        'claude', '-p', $task->prompt . ($task->answers() ? ANSWER_INSTRUCTIONS : FIX_INSTRUCTIONS),
        '--model', $options->model,
        '--setting-sources', 'project',
        '--strict-mcp-config', '--mcp-config', $directory . '/mcp.json',
        '--tools', TOOLS,
        '--disable-slash-commands',
        '--no-session-persistence',
        '--dangerously-skip-permissions',
        '--output-format', 'stream-json', '--verbose',
    ];
    if ($task->answers()) {
        array_push($command, '--json-schema', json_encode(ANSWER_SCHEMA, JSON_THROW_ON_ERROR));
    }

    $session = new Session();
    $stream = fopen($directory . '/stream.jsonl', 'w');
    $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $directory . '/stderr.txt', 'w']];
    $process = proc_open($command, $descriptors, $pipes, $project, agentEnvironment());
    if (!is_resource($process) || $stream === false) {
        fail('Cannot start claude');
    }
    $start = microtime(true);
    foreach (lines($pipes[1], $start + $options->timeout) as $line) {
        fwrite($stream, $line . "\n");
        $session->read($line);
        if (!$session->finished() && $session->usage()->total() > $options->maxTokens) {
            $session->capped = true;
            $session->error = 'token cap reached';

            break;
        }
    }
    $session->seconds = round(microtime(true) - $start, 1);
    fclose($stream);
    proc_terminate($process, 9);
    proc_close($process);
    if (!$session->finished()) {
        $session->error ??= microtime(true) >= $start + $options->timeout ? 'timeout' : 'no result';
    }

    return $session;
}

/**
 * The lines a pipe gives until it closes or the deadline passes.
 *
 * @param resource $pipe
 *
 * @return Generator<int, string>
 */
function lines($pipe, float $deadline): Generator
{
    stream_set_blocking($pipe, false);
    $buffer = '';
    while (!feof($pipe) && microtime(true) < $deadline) {
        $read = [$pipe];
        $write = null;
        $except = null;
        if (stream_select($read, $write, $except, 1) > 0) {
            $buffer .= (string) fread($pipe, 65536);
        }
        $end = strpos($buffer, "\n");
        while ($end !== false) {
            yield substr($buffer, 0, $end);
            $buffer = substr($buffer, $end + 1);
            $end = strpos($buffer, "\n");
        }
    }
    if ($buffer !== '') {
        yield $buffer;
    }
}

/**
 * The environment of the agent: the caller's, without what would tell Claude Code it runs inside another session.
 *
 * @return array<string, string>
 */
function agentEnvironment(): array
{
    return array_filter(
        getenv(),
        static fn (string $name): bool => $name !== 'CLAUDECODE' && !str_starts_with($name, 'CLAUDE_CODE_'),
        ARRAY_FILTER_USE_KEY,
    );
}

/**
 * @return list<string>
 */
function answeredClasses(mixed $answer): array
{
    return texts(is_array($answer) ? ($answer['classes'] ?? null) : null);
}

/**
 * Recall over the expected classes, precision over expected and allowed ones. An answer without namespace counts when
 * its short name designates exactly one expected or allowed class; a method (`Class::method`) counts as its class.
 *
 * @param list<string> $answers
 *
 * @return array{recall: float, precision: float, found: list<string>, missed: list<string>, extra: list<string>}
 */
function score(Task $task, array $answers): array
{
    $expected = array_map(className(...), $task->expected);
    $accepted = array_values(array_unique([...$expected, ...array_map(className(...), $task->allowed)]));
    $byShortName = [];
    foreach ($accepted as $class) {
        $byShortName[shortName($class)][] = $class;
    }

    $given = [];
    $extra = [];
    foreach ($answers as $answer) {
        $class = className($answer);
        $candidates = $byShortName[$class] ?? [];
        if (!str_contains($class, '\\') && count($candidates) === 1) {
            $class = $candidates[0];
        }
        if (in_array($class, $accepted, true)) {
            $given[$class] = true;
        } else {
            $extra[$class] = true;
        }
    }
    $found = array_values(array_intersect($expected, array_map(strval(...), array_keys($given))));
    $answered = count($given) + count($extra);

    return [
        'recall' => $expected === [] ? 1.0 : round(count($found) / count($expected), 3),
        'precision' => $answered === 0 ? 0.0 : round(count($given) / $answered, 3),
        'found' => $found,
        'missed' => array_values(array_diff($expected, $found)),
        'extra' => array_map(strval(...), array_keys($extra)),
    ];
}

function className(string $name): string
{
    $name = trim($name, " \t\n`'\"");
    $name = preg_replace('/::.*$|\(\)$/', '', $name) ?? $name;

    return strtolower(ltrim($name, '\\'));
}

function shortName(string $class): string
{
    $position = strrpos($class, '\\');

    return $position === false ? $class : substr($class, $position + 1);
}

/**
 * @param list<string> $command
 *
 * @return array{code: int, output: string, seconds: float}
 */
function execute(array $command, ?string $directory, int $timeout): array
{
    $start = microtime(true);
    // The arguments reach the command as "$@", never parsed by the shell: it only joins stderr to stdout.
    $merged = ['sh', '-c', 'exec "$@" 2>&1', 'sh', ...$command];
    $process = proc_open($merged, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w']], $pipes, $directory);
    if (!is_resource($process)) {
        fail('Cannot run ' . implode(' ', $command));
    }
    $output = implode("\n", iterator_to_array(lines($pipes[1], $start + $timeout), false));
    $timedOut = !feof($pipes[1]);
    fclose($pipes[1]);
    if ($timedOut) {
        proc_terminate($process, 9);
    }
    $code = proc_close($process);

    return ['code' => $timedOut ? 124 : $code, 'output' => $output, 'seconds' => microtime(true) - $start];
}

function resultsFile(?string $file): string
{
    if ($file !== null) {
        return $file;
    }
    $files = glob(OUT_DIR . '/results/*.jsonl') ?: [];
    if ($files === []) {
        fail('No results in build/agent-bench/results/');
    }
    sort($files);

    return $files[count($files) - 1];
}

/**
 * The tables of docs/BENCHMARK.md: per task and arm, per arm, and the phpgraph tools called.
 */
function report(string $file): string
{
    $tasks = [];
    foreach (tasks(['*']) as $task) {
        $tasks[$task->id] = $task;
    }
    $groups = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $record = json_decode($line, true);
        if (!is_array($record)) {
            continue;
        }
        $task = $tasks[Values::text($record['task'] ?? null)] ?? null;
        $record['success_by_name'] = $task === null ? null : successByName($task, $record);
        $groups[Values::text($record['task'] ?? null)][Values::text($record['arm'] ?? null)][] = $record;
    }

    ksort($groups);

    return taskTable($groups) . "\n" . armTable($groups) . phpgraphTools($groups);
}

/**
 * Success when classes are compared by their short name only: tells a wrong answer from a right class given with a
 * wrong namespace (`Sylius\Bundle\CoreBundle\Tests\...` guessed from the path for `Tests\Sylius\Bundle\...`).
 *
 * @param array<mixed> $record
 */
function successByName(Task $task, array $record): bool
{
    if (!$task->answers()) {
        return ($record['success'] ?? false) === true;
    }
    if (($record['error'] ?? null) !== null) {
        return false;
    }
    $short = static fn (string $class): string => shortName(className($class));
    $expected = array_unique(array_map($short, $task->expected));
    $accepted = array_unique([...$expected, ...array_map($short, $task->allowed)]);
    $given = array_unique(array_map($short, answeredClasses($record['answer'] ?? null)));
    if ($given === []) {
        return false;
    }
    $recall = count(array_intersect($expected, $given)) / max(1, count($expected));
    $precision = count(array_intersect($given, $accepted)) / count($given);

    return $recall >= $task->minRecall && $precision >= $task->minPrecision;
}

/**
 * @param array<string, array<string, list<array<mixed>>>> $groups
 */
function taskTable(array $groups): string
{
    $text = "| Task | Kind | Arm | Success | By short name | Recall | Precision | Tokens (mean ± sd) | Cost (USD) | Tool calls | Time (s) |\n"
        . "|---|---|---|---|---|---|---|---|---|---|---|\n";
    foreach ($groups as $task => $arms) {
        foreach (ARMS as $arm) {
            $runs = $arms[$arm] ?? [];
            if ($runs === []) {
                continue;
            }
            $text .= sprintf(
                "| %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s |\n",
                $task,
                Values::text($runs[0]['kind'] ?? null),
                $arm,
                successes($runs),
                successes($runs, 'success_by_name'),
                meanOf($runs, 'recall', 2),
                meanOf($runs, 'precision', 2),
                meanOf($runs, 'tokens', 0) . ' ± ' . number_format(deviation(values($runs, 'tokens'))),
                meanOf($runs, 'cost_usd', 2),
                meanOf($runs, 'tool_calls', 1),
                meanOf($runs, 'seconds', 0),
            );
        }
    }

    return $text;
}

/**
 * @param array<string, array<string, list<array<mixed>>>> $groups
 */
function armTable(array $groups): string
{
    $text = "| Kind | Arm | Runs | Success | By short name | Tokens (mean) | Cost (USD, total) | Tool calls (mean) | Time (s, mean) |\n"
        . "|---|---|---|---|---|---|---|---|---|\n";
    $byKind = ['all' => []];
    foreach ($groups as $arms) {
        foreach ($arms as $arm => $runs) {
            foreach ($runs as $run) {
                $byKind[Values::text($run['kind'] ?? null)][$arm][] = $run;
                $byKind['all'][$arm][] = $run;
            }
        }
    }
    foreach (['find', 'impact', 'explain', 'fix', 'all'] as $kind) {
        foreach (ARMS as $arm) {
            $runs = $byKind[$kind][$arm] ?? [];
            if ($runs === []) {
                continue;
            }
            $text .= sprintf(
                "| %s | %s | %d | %s | %s | %s | %.2f | %s | %s |\n",
                $kind,
                $arm,
                count($runs),
                successes($runs),
                successes($runs, 'success_by_name'),
                meanOf($runs, 'tokens', 0),
                array_sum(values($runs, 'cost_usd')),
                meanOf($runs, 'tool_calls', 1),
                meanOf($runs, 'seconds', 0),
            );
        }
    }

    return $text;
}

/**
 * Calls of each phpgraph tool per task, in the phpgraph arm.
 *
 * @param array<string, array<string, list<array<mixed>>>> $groups
 */
function phpgraphTools(array $groups): string
{
    $counts = [];
    $used = [];
    foreach ($groups as $task => $arms) {
        foreach ($arms['phpgraph'] ?? [] as $run) {
            $usedInRun = false;
            foreach (is_array($run['tools'] ?? null) ? $run['tools'] : [] as $tool => $count) {
                $tool = (string) $tool;
                if (str_starts_with($tool, 'mcp__phpgraph__')) {
                    $name = substr($tool, strlen('mcp__phpgraph__'));
                    $counts[$task][$name] = ($counts[$task][$name] ?? 0) + Values::number($count);
                    $usedInRun = true;
                }
            }
            $used[$task] = ($used[$task] ?? 0) + ($usedInRun ? 1 : 0);
        }
    }
    $tools = [];
    foreach ($counts as $byTool) {
        $tools = [...$tools, ...array_keys($byTool)];
    }
    $tools = array_values(array_unique($tools));
    sort($tools);
    $text = "\n| Task | Runs using phpgraph | " . implode(' | ', $tools) . " |\n|---|---|" . str_repeat('---|', count($tools)) . "\n";
    $totals = array_fill_keys($tools, 0);
    foreach ($groups as $task => $arms) {
        $runs = count($arms['phpgraph'] ?? []);
        if ($runs === 0) {
            continue;
        }
        $cells = [];
        foreach ($tools as $tool) {
            $count = $counts[$task][$tool] ?? 0;
            $totals[$tool] += $count;
            $cells[] = (string) $count;
        }
        $text .= "| {$task} | " . ($used[$task] ?? 0) . "/{$runs} | " . implode(' | ', $cells) . " |\n";
    }
    $runsUsing = array_sum($used);
    $runs = array_sum(array_map(static fn (array $arms): int => count($arms['phpgraph'] ?? []), $groups));

    return $text . "| Total | {$runsUsing}/{$runs} | " . implode(' | ', array_map(strval(...), $totals)) . " |\n";
}

/**
 * @param list<array<mixed>> $runs
 */
function successes(array $runs, string $key = 'success'): string
{
    $successes = count(array_filter($runs, static fn (array $run): bool => ($run[$key] ?? false) === true));

    return $successes . '/' . count($runs);
}

/**
 * One number per run: `tokens` is the total, `tool_calls` the calls of all tools.
 *
 * @param list<array<mixed>> $runs
 *
 * @return list<float>
 */
function values(array $runs, string $key): array
{
    $values = [];
    foreach ($runs as $run) {
        $tokens = $run['tokens'] ?? null;
        $tools = $run['tools'] ?? null;
        $value = match ($key) {
            'tokens' => is_array($tokens) ? ($tokens['total'] ?? null) : null,
            'tool_calls' => is_array($tools) ? array_sum(array_map(Values::number(...), $tools)) : null,
            default => $run[$key] ?? null,
        };
        if (is_int($value) || is_float($value)) {
            $values[] = (float) $value;
        }
    }

    return $values;
}

/**
 * @param list<array<mixed>> $runs
 */
function meanOf(array $runs, string $key, int $decimals): string
{
    $values = values($runs, $key);

    return $values === [] ? '–' : number_format(mean($values), $decimals);
}

/**
 * @param list<float> $values
 */
function mean(array $values): float
{
    return $values === [] ? 0.0 : array_sum($values) / count($values);
}

/**
 * @param list<float> $values
 */
function deviation(array $values): float
{
    if (count($values) < 2) {
        return 0.0;
    }
    $mean = mean($values);
    $squares = array_map(static fn (float $value): float => ($value - $mean) ** 2, $values);

    return sqrt(array_sum($squares) / (count($values) - 1));
}

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
main(array_slice($argv, 1));
