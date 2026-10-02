<?php

declare(strict_types=1);

namespace PhpGraph\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'overview', description: 'Stack, structure and gaps of the project, the first thing to read')]
final class OverviewCommand extends AbstractGraphCommand
{
    protected function configure(): void
    {
        $this->addGraphOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln($this->loadPresenter($input)->overview(), OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
