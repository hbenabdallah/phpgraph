<?php

declare(strict_types=1);

namespace PhpGraph\Command;

use PhpGraph\Values;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mcp-config', description: 'Print the MCP configuration of an agent (Sherpa, Claude Code, Codex, Cursor...), ready to copy, with the paths filled in')]
final class McpConfigCommand extends Command
{
    private const AGENTS = ['sherpa', 'claude', 'codex', 'cursor', 'json'];

    /**
     * For the instructions file of the project: agents reach for grep first, even with the tools listed. Sherpa reads
     * none, the MCP instructions say it.
     */
    private const INSTRUCTION = 'On PHP code, use phpgraph before grep or reading files: overview for the structure, outline to '
        . 'understand a feature, impact_of before changing a class or a method. Then read only the files it points to.';

    protected function configure(): void
    {
        $this
            ->addArgument('agent', InputArgument::OPTIONAL, 'sherpa, claude (Claude Code), codex, cursor, or json for any other MCP client over stdio', 'claude')
            ->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'Project to serve', '.')
            ->addOption('docker', null, InputOption::VALUE_REQUIRED, 'Run phpgraph from this Docker image instead of PHP, for example phpgraph');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agent = Values::text($input->getArgument('agent'));
        $project = realpath(Values::text($input->getOption('project')));
        if (!\in_array($agent, self::AGENTS, true) || $project === false) {
            $output->writeln(sprintf('<error>%s</error>', $project === false ? 'Project path not found.' : 'Agent must be one of: ' . implode(', ', self::AGENTS) . '.'));

            return Command::INVALID;
        }

        // Cursor reads .cursor/mcp.json in the project and expands ${workspaceFolder}: the file can be committed.
        // Sherpa starts its servers from the project it runs in: without a path, one declaration serves them all.
        $explicitProject = $input->hasParameterOption(['--project', '-p']);
        $everyProject = $agent === 'sherpa' && !$explicitProject && $input->getOption('docker') === null;
        if ($agent === 'cursor' && !$explicitProject) {
            $project = '${workspaceFolder}';
        }

        $image = $input->getOption('docker');
        // In Docker, as the current user: graph.json and the cache belong to them, not to root.
        $user = \function_exists('posix_getuid') && \function_exists('posix_getgid') ? ['-u', posix_getuid() . ':' . posix_getgid()] : [];
        if (\is_string($image)) {
            $command = 'docker';
            $arguments = ['run', '--rm', '-i', ...$user, '-v', $project . ':/project', $image, 'serve', '/project'];
        } else {
            [$command, $arguments] = $this->runner();
            $arguments[] = 'serve';
            if (!$everyProject) {
                $arguments[] = $project;
            }
        }

        $server = ['command' => $command, 'args' => $arguments];
        if ($agent === 'cursor') {
            $server = ['type' => 'stdio', ...$server];
        }
        $json = json_encode(['mcpServers' => ['phpgraph' => $server]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $lines = match ($agent) {
            'sherpa' => [
                $everyProject
                    ? '# Sherpa starts its MCP servers from the project it runs in: this serves every project.'
                    : '# Sherpa:',
                'sherpa mcp add phpgraph -- ' . implode(' ', array_map($this->shellArgument(...), [$command, ...$arguments])),
                '',
                '# Or add it to ~/.config/sherpa/mcp.json:',
                $json,
            ],
            'claude' => [
                '# Claude Code, from the project directory:',
                'claude mcp add phpgraph -- ' . implode(' ', array_map($this->shellArgument(...), [$command, ...$arguments])),
                '',
                '# Or share it with the team in .mcp.json at the project root:',
                $json,
            ],
            'codex' => [
                '# Codex: add to ~/.codex/config.toml (or .codex/config.toml in a trusted project).',
                '# The first tool call builds the graph: more than the default 60 s on a large project.',
                '[mcp_servers.phpgraph]',
                'command = ' . json_encode($command, JSON_UNESCAPED_SLASHES),
                'args = [' . implode(', ', array_map(static fn (string $argument): string => (string) json_encode($argument, JSON_UNESCAPED_SLASHES), $arguments)) . ']',
                'tool_timeout_sec = 300',
                '',
                '# Or from the command line (then raise tool_timeout_sec in config.toml):',
                'codex mcp add phpgraph -- ' . implode(' ', array_map($this->shellArgument(...), [$command, ...$arguments])),
            ],
            'cursor' => [
                $explicitProject
                    ? '# Cursor: .cursor/mcp.json at the project root, or ~/.cursor/mcp.json'
                    : '# Cursor: .cursor/mcp.json at the project root (${workspaceFolder} is the project: the file can be committed)',
                $json,
            ],
            default => ['# Any MCP client over stdio (mcpServers format)', $json],
        };
        $file = match ($agent) {
            'sherpa' => null,
            'claude' => 'CLAUDE.md',
            'codex', 'cursor' => 'AGENTS.md',
            default => 'the instructions file of your agent (AGENTS.md, CLAUDE.md...)',
        };
        if ($file !== null) {
            $lines = [...$lines, '', "# So that the agent reaches for phpgraph before grep, add to {$file}:", self::INSTRUCTION];
        }

        $output->writeln($lines, OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }

    private function shellArgument(string $argument): string
    {
        return preg_match('#^[\w/.:@=-]+$#', $argument) === 1 ? $argument : escapeshellarg($argument);
    }

    /**
     * Opcache and its JIT for the server: builds about 20% faster, the same graph. Off by default on the command line,
     * and only settable when PHP starts. Harmless when opcache is not installed: PHP ignores the settings.
     */
    private const OPCACHE = ['-d', 'opcache.enable_cli=1', '-d', 'opcache.jit=tracing', '-d', 'opcache.jit_buffer_size=128M'];

    /**
     * The command and its first arguments: the standalone executable itself (a static PHP runtime with the PHAR
     * appended, needing no PHP), else php with the PHAR, or with bin/phpgraph of this checkout.
     *
     * @return array{string, list<string>}
     */
    private function runner(): array
    {
        $phar = \Phar::running(false);
        if (\PHP_SAPI === 'micro' && $phar !== '') {
            return [$phar, []];
        }

        return ['php', [...self::OPCACHE, $phar !== '' ? $phar : (string) realpath(__DIR__ . '/../../bin/phpgraph')]];
    }
}
