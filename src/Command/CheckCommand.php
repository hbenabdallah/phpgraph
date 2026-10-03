<?php

declare(strict_types=1);

namespace PhpGraph\Command;

use PhpGraph\Presentation\TextPresenter;
use PhpGraph\Project\ProjectGraph;
use PhpGraph\Query\GraphQueryProvider;
use PhpGraph\Query\Result\LayerViolation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'check',
    description: 'Check the layer dependency rules, for CI: fails when a dependency breaks them and is not in the baseline',
)]
final class CheckCommand extends Command
{
    public const DEFAULT_BASELINE = 'phpgraph-baseline.json';

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Project root', '.')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output directory of the graph (default: <path>/phpgraph-out)')
            ->addOption('baseline', 'b', InputOption::VALUE_REQUIRED, 'Accepted violations (default: <path>/' . self::DEFAULT_BASELINE . ' when it exists)')
            ->addOption('generate-baseline', null, InputOption::VALUE_NONE, 'Write the current violations to the baseline and succeed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = realpath((string) $input->getArgument('path'));
        if ($root === false || !is_dir($root)) {
            $output->writeln('<error>Project path not found.</error>');

            return Command::FAILURE;
        }

        // Built when missing or stale, with the options of the last build.
        $project = ProjectGraph::reusingSavedOptions(
            $root,
            \is_string($input->getOption('output')) ? $input->getOption('output') : $root . '/' . ProjectGraph::OUTPUT_DIRECTORY,
            null,
            checkInterval: 0.0,
        );
        $query = GraphQueryProvider::forProject($project)->get();
        $baseline = \is_string($input->getOption('baseline')) ? $input->getOption('baseline') : $root . '/' . self::DEFAULT_BASELINE;

        if ($input->getOption('generate-baseline')) {
            $keys = array_map(static fn (LayerViolation $violation): string => $violation->key(), $query->architecture()->violations);
            file_put_contents($baseline, json_encode(['layerViolations' => $keys], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            $output->writeln(sprintf('%d violations written to %s.', \count($keys), $baseline));

            return Command::SUCCESS;
        }

        $accepted = $this->accepted($baseline);
        $output->writeln((new TextPresenter($query))->layerViolations($accepted), OutputInterface::OUTPUT_RAW);

        foreach ($query->architecture()->violations as $violation) {
            if (!\in_array($violation->key(), $accepted, true)) {
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function accepted(string $baseline): array
    {
        $data = is_file($baseline) ? json_decode((string) file_get_contents($baseline), true) : null;
        $keys = \is_array($data) && \is_array($data['layerViolations'] ?? null) ? $data['layerViolations'] : [];

        return array_values(array_filter($keys, 'is_string'));
    }
}
