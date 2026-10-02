<?php

declare(strict_types=1);

namespace PhpGraph\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'impact', description: 'What depends on a class or a method, directly or not: what a change may break')]
final class ImpactCommand extends AbstractGraphCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Short or fully qualified name, or Class::method')
            ->addOption('depth', 'd', InputOption::VALUE_REQUIRED, 'Relations away from the change', '3');
        $this->addGraphOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(
            $this->loadPresenter($input)->impact((string) $input->getArgument('name'), max(1, (int) $input->getOption('depth'))),
            OutputInterface::OUTPUT_RAW,
        );

        return Command::SUCCESS;
    }
}
