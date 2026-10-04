<?php

declare(strict_types=1);

namespace PhpGraph\Command;

use PhpGraph\Presentation\TextPresenter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'outline', description: 'The structural outline of a feature: its classes, families, flow, behaviour, wiring and tests')]
final class OutlineCommand extends AbstractGraphCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('topic', InputArgument::REQUIRED, 'The feature, in words or class names')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'text (compact), full (no list cut) or json', 'text');
        $this->addGraphOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        if (!\in_array($format, TextPresenter::IMPACT_FORMATS, true)) {
            $output->writeln(\sprintf('<error>Unknown format "%s": %s.</error>', \is_string($format) ? $format : '', implode(', ', TextPresenter::IMPACT_FORMATS)));

            return Command::INVALID;
        }
        $output->writeln($this->loadPresenter($input)->outline((string) $input->getArgument('topic'), $format), OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
