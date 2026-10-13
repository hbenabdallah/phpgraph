<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Command\McpConfigCommand;
use PhpGraph\Tests\Support\Dig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class McpConfigTest extends TestCase
{
    public function testPrintsAReadyToCopyConfiguration(): void
    {
        $project = (string) realpath(__DIR__ . '/Fixtures/src');
        $tester = new CommandTester(new McpConfigCommand());

        $tester->execute(['agent' => 'cursor', '--project' => $project]);
        $json = $this->json($tester->getDisplay());
        self::assertSame(['type' => 'stdio', 'command' => 'php', 'args' => ['-d', 'opcache.enable_cli=1', '-d', 'opcache.jit=tracing', '-d', 'opcache.jit_buffer_size=128M', (string) realpath(__DIR__ . '/../bin/phpgraph'), 'serve', $project]], Dig::at($json, 'mcpServers', 'phpgraph') ?? null);

        $tester->execute(['agent' => 'cursor']);
        self::assertStringContainsString('"${workspaceFolder}"', $tester->getDisplay(), 'Without --project, the shareable form');

        $tester->execute(['agent' => 'claude', '--project' => $project]);
        self::assertStringContainsString('claude mcp add phpgraph -- php ', $tester->getDisplay());

        $tester->execute(['agent' => 'codex', '--project' => $project, '--docker' => 'phpgraph']);
        self::assertStringContainsString("[mcp_servers.phpgraph]\ncommand = \"docker\"", $tester->getDisplay());
        self::assertStringContainsString('tool_timeout_sec = 300', $tester->getDisplay());
        self::assertStringContainsString('codex mcp add phpgraph -- docker run', $tester->getDisplay());
        self::assertStringContainsString('"' . $project . ':/project", "phpgraph", "serve", "/project"]', $tester->getDisplay());

        $tester->execute(['agent' => 'sherpa']);
        $json = $this->json($tester->getDisplay());
        self::assertSame(['command' => 'php', 'args' => ['-d', 'opcache.enable_cli=1', '-d', 'opcache.jit=tracing', '-d', 'opcache.jit_buffer_size=128M', (string) realpath(__DIR__ . '/../bin/phpgraph'), 'serve']], Dig::at($json, 'mcpServers', 'phpgraph') ?? null, 'Sherpa: no path, the project it runs in');
        self::assertStringContainsString('sherpa mcp add phpgraph -- php -d opcache.enable_cli=1 ', $tester->getDisplay());
        self::assertStringEndsWith(' serve', strstr($tester->getDisplay(), "\n\n", true) ?: '', 'Sherpa: no path in the command either');
        self::assertStringContainsString('~/.config/sherpa/mcp.json', $tester->getDisplay());

        self::assertStringNotContainsString('before grep', $tester->getDisplay(), 'Sherpa reads no instructions file: the MCP instructions say it');

        $tester->execute(['agent' => 'codex']);
        self::assertStringContainsString("add to AGENTS.md:\nOn PHP code, use phpgraph before grep", $tester->getDisplay());

        self::assertSame(2, $tester->execute(['agent' => 'unknown']));
    }

    private function json(string $display): mixed
    {
        $start = (int) strpos($display, '{');

        return json_decode(substr($display, $start, (int) strrpos($display, '}') - $start + 1), true);
    }
}
