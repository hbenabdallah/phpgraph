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

#[AsCommand(name: 'impact', description: 'What depends on a class or a method, directly or not: what a change may break')]
final class ImpactCommand extends AbstractGraphCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Short or fully qualified name, or Class::method')
            ->addOption('depth', 'd', InputOption::VALUE_REQUIRED, 'Relations away from the change', '3')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Entries per list, 0 for all', '40')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Complete lists (same as --limit 0)')
            ->addOption('section', 's', InputOption::VALUE_REQUIRED, 'Only one list: ' . implode(', ', TextPresenter::IMPACT_SECTIONS))
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'text (compact), full (every relation spelled out) or json', 'text');
        $this->addGraphOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $section = $input->getOption('section');
        if ($section !== null && !\in_array($section, TextPresenter::IMPACT_SECTIONS, true)) {
            $output->writeln(\sprintf('<error>Unknown section "%s": %s.</error>', \is_string($section) ? $section : '', implode(', ', TextPresenter::IMPACT_SECTIONS)));

            return Command::INVALID;
        }

        $format = $input->getOption('format');
        if (!\in_array($format, TextPresenter::IMPACT_FORMATS, true)) {
            $output->writeln(\sprintf('<error>Unknown format "%s": %s.</error>', \is_string($format) ? $format : '', implode(', ', TextPresenter::IMPACT_FORMATS)));

            return Command::INVALID;
        }

        $output->writeln(
            $this->loadPresenter($input)->impact(
                (string) $input->getArgument('name'),
                max(1, (int) $input->getOption('depth')),
                $input->getOption('all') ? 0 : max(0, (int) $input->getOption('limit')),
                \is_string($section) ? $section : null,
                $format,
            ),
            OutputInterface::OUTPUT_RAW,
        );

        return Command::SUCCESS;
    }
}
