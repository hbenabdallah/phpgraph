<?php

declare(strict_types=1);

namespace PhpGraph\Command;

use PhpGraph\Query\Direction;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'explain', description: 'Show a node with all its connections')]
final class ExplainCommand extends AbstractGraphCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Short or fully qualified name')
            ->addOption('direction', 'd', InputOption::VALUE_REQUIRED, 'in, out or both', 'both');
        $this->addGraphOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $direction = Direction::tryFrom((string) $input->getOption('direction'));
        if ($direction === null) {
            $output->writeln('<error>Direction must be in, out or both.</error>');

            return Command::INVALID;
        }

        $output->writeln($this->loadPresenter($input)->explain(
            (string) $input->getArgument('name'),
            $direction,
        ), OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
