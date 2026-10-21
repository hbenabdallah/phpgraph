<?php

declare(strict_types=1);

namespace PhpGraph\Command;

use PhpGraph\Presentation\TextPresenter;
use PhpGraph\Project\ProjectConfig;
use PhpGraph\Project\ProjectGraph;
use PhpGraph\Query\GraphQueryProvider;
use PhpGraph\Query\Result\LayerViolation;
use PhpGraph\Query\Result\RuleViolation;
use PhpGraph\Values;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'check',
    description: 'Check the layer dependency rules and the rules of phpgraph.yaml, for CI: fails on a breach not in the baseline',
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
        $root = realpath(Values::text($input->getArgument('path')));
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
        $rules = ProjectConfig::load($root)->rules;
        $layers = array_map(static fn (LayerViolation $violation): string => $violation->key(), $query->architecture()->violations);
        $breaches = $rules->isEmpty() ? [] : $query->ruleViolations($rules);
        $ruleKeys = array_map(static fn (RuleViolation $violation): string => $violation->key(), $breaches);

        if ($input->getOption('generate-baseline')) {
            $data = ['layerViolations' => $layers] + ($rules->isEmpty() ? [] : ['ruleViolations' => $ruleKeys]);
            file_put_contents($baseline, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            $output->writeln(sprintf('%d violations written to %s.', \count($layers) + \count($ruleKeys), $baseline));

            return Command::SUCCESS;
        }

        $acceptedLayers = $this->accepted($baseline, 'layerViolations');
        $acceptedRules = $this->accepted($baseline, 'ruleViolations');
        $presenter = new TextPresenter($query);
        $output->writeln($presenter->layerViolations($acceptedLayers), OutputInterface::OUTPUT_RAW);
        if (!$rules->isEmpty()) {
            $output->writeln(['', $presenter->ruleViolations($breaches, $acceptedRules)], OutputInterface::OUTPUT_RAW);
        }

        return array_diff($layers, $acceptedLayers) === [] && array_diff($ruleKeys, $acceptedRules) === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function accepted(string $baseline, string $section): array
    {
        $data = is_file($baseline) ? json_decode((string) file_get_contents($baseline), true) : null;
        $keys = \is_array($data) && \is_array($data[$section] ?? null) ? $data[$section] : [];

        return array_values(array_filter($keys, 'is_string'));
    }
}
