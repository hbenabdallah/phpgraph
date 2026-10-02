<?php

declare(strict_types=1);

namespace PhpGraph\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'query', description: 'Answer a plain-language question with a scoped subgraph')]
final class QueryCommand extends AbstractGraphCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('question', InputArgument::REQUIRED, 'Question or keywords')
            ->addOption('depth', null, InputOption::VALUE_REQUIRED, 'Traversal depth', '2')
            ->addOption('budget', null, InputOption::VALUE_REQUIRED, 'Maximum number of nodes', '40');
        $this->addGraphOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln($this->loadPresenter($input)->query(
            (string) $input->getArgument('question'),
            max(1, (int) $input->getOption('depth')),
            max(1, (int) $input->getOption('budget')),
        ), OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
