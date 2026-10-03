<?php

declare(strict_types=1);

namespace PhpGraph\Command;

use PhpGraph\Presentation\TextPresenter;
use PhpGraph\Project\BuildOptions;
use PhpGraph\Project\ProjectGraph;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'build', description: 'Build the knowledge graph of a PHP project')]
final class BuildCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Project root', '.')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output directory (default: <path>/phpgraph-out)')
            ->addOption('exclude', 'e', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Path pattern to exclude (repeatable)')
            ->addOption('depth', null, InputOption::VALUE_REQUIRED, 'Namespace depth used for the cross-boundary report', '2')
            ->addOption('no-vendor', null, InputOption::VALUE_NONE, 'Do not read dependency signatures from vendor/')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Parse every file again instead of reusing the extractions of unchanged files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = realpath((string) $input->getArgument('path'));
        if ($root === false || !is_dir($root)) {
            $output->writeln('<error>Project path not found.</error>');

            return Command::FAILURE;
        }

        /** @var list<string> $excludes */
        $excludes = $input->getOption('exclude');
        $project = new ProjectGraph(
            $root,
            \is_string($input->getOption('output')) ? $input->getOption('output') : $root . '/' . ProjectGraph::OUTPUT_DIRECTORY,
            new BuildOptions($excludes, !$input->getOption('no-vendor'), max(1, (int) $input->getOption('depth'))),
            cache: !$input->getOption('no-cache'),
        );
        $result = $project->build();

        $output->writeln(sprintf(
            '<info>%d files parsed, %d failed, %d nodes, %d edges.</info>',
            $result->filesParsed,
            \count($result->failures),
            $result->graph->nodeCount(),
            $result->graph->edgeCount(),
        ));
        $output->writeln('Graph: ' . $project->graphPath());

        $unread = TextPresenter::unreadSources($result->phpFilesNotRead, $root);
        if ($unread !== null) {
            $output->writeln('<error>' . $unread . '</error>');

            return Command::FAILURE;
        }

        if ($result->duplicates !== []) {
            $output->writeln(sprintf('<comment>%d names are declared more than once, see GRAPH_REPORT.md.</comment>', \count($result->duplicates)));
        }

        foreach ($result->failures as $file => $message) {
            $output->writeln(sprintf('<comment>Skipped %s: %s</comment>', $file, $message), OutputInterface::VERBOSITY_VERBOSE);
        }

        return Command::SUCCESS;
    }
}
