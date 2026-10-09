<?php

declare(strict_types=1);

namespace PhpGraph\Command;

use PhpGraph\Mcp\McpServer;
use PhpGraph\Project\BuildOptions;
use PhpGraph\Project\ProjectGraph;
use PhpGraph\Query\GraphQueryProvider;
use PhpGraph\Version;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'serve',
    description: 'Expose the graph of a project as an MCP server over stdio, built on first use and rebuilt when the code changes',
)]
final class ServeCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Project root', '.')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output directory (default: <path>/phpgraph-out)')
            ->addOption('exclude', 'e', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Path pattern to exclude (repeatable, default: those of the last build)')
            ->addOption('no-vendor', null, InputOption::VALUE_NONE, 'Do not read dependency signatures from vendor/')
            ->addOption('graph', 'g', InputOption::VALUE_REQUIRED, 'Serve this graph.json as is: never build it, reload it when it changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // stdout carries the JSON-RPC stream: a PHP warning printed there would corrupt it.
        ini_set('display_errors', 'stderr');
        // Its stdin may be a socket (Node gives its children socketpairs): waiting for the next request never times out.
        ini_set('default_socket_timeout', '-1');
        $graph = $input->getOption('graph');
        if (\is_string($graph)) {
            (new McpServer(new GraphQueryProvider($graph), Version::get()))->run();

            return Command::SUCCESS;
        }

        $root = realpath((string) $input->getArgument('path'));
        if ($root === false || !is_dir($root)) {
            fwrite(STDERR, "phpgraph: project path not found\n");

            return Command::FAILURE;
        }

        /** @var list<string> $excludes */
        $excludes = $input->getOption('exclude');
        $noVendor = (bool) $input->getOption('no-vendor');
        $options = $excludes === [] && !$noVendor ? null : new BuildOptions($excludes, !$noVendor);

        $project = ProjectGraph::reusingSavedOptions(
            $root,
            \is_string($input->getOption('output')) ? $input->getOption('output') : $root . '/' . ProjectGraph::OUTPUT_DIRECTORY,
            $options,
            static function (string $message): void {
                fwrite(STDERR, $message . "\n");
            },
        );

        $project->deferCacheWrites();
        (new McpServer(GraphQueryProvider::forProject($project), Version::get()))->run();

        return Command::SUCCESS;
    }
}
