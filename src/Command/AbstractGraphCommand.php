<?php

declare(strict_types=1);

namespace PhpGraph\Command;

use PhpGraph\Presentation\TextPresenter;
use PhpGraph\Query\GraphQueryProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

abstract class AbstractGraphCommand extends Command
{
    public const DEFAULT_GRAPH = 'phpgraph-out/graph.json';

    protected function addGraphOption(): void
    {
        $this->addOption('graph', 'g', InputOption::VALUE_REQUIRED, 'Path to graph.json', self::DEFAULT_GRAPH);
    }

    protected function graphPath(InputInterface $input): string
    {
        return (string) $input->getOption('graph');
    }

    protected function loadPresenter(InputInterface $input): TextPresenter
    {
        return new TextPresenter((new GraphQueryProvider($this->graphPath($input)))->get());
    }
}
