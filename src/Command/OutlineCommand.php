<?php

declare(strict_types=1);

namespace PhpGraph\Command;

use PhpGraph\Presentation\OutlineReport;
use PhpGraph\Presentation\TextPresenter;
use PhpGraph\Values;
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
            ->addOption('section', 's', InputOption::VALUE_REQUIRED, 'Only one section, uncut: ' . implode(', ', OutlineReport::SECTIONS))
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'text (compact), full (no list cut, much larger) or json', 'text');
        $this->addGraphOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        if (!\in_array($format, TextPresenter::IMPACT_FORMATS, true)) {
            $output->writeln(\sprintf('<error>Unknown format "%s": %s.</error>', \is_string($format) ? $format : '', implode(', ', TextPresenter::IMPACT_FORMATS)));

            return Command::INVALID;
        }
        $section = $input->getOption('section');
        if ($section !== null && !\in_array($section, OutlineReport::SECTIONS, true)) {
            $output->writeln(\sprintf('<error>Unknown section "%s": %s.</error>', \is_string($section) ? $section : '', implode(', ', OutlineReport::SECTIONS)));

            return Command::INVALID;
        }
        $output->writeln($this->loadPresenter($input)->outline(Values::text($input->getArgument('topic')), $format, $section), OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
