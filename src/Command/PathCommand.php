<?php

declare(strict_types=1);

namespace PhpGraph\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'path', description: 'Shortest chain of relations between two nodes')]
final class PathCommand extends AbstractGraphCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('from', InputArgument::REQUIRED, 'Start node')
            ->addArgument('to', InputArgument::REQUIRED, 'End node');
        $this->addGraphOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln($this->loadPresenter($input)->path(
            (string) $input->getArgument('from'),
            (string) $input->getArgument('to'),
        ), OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
