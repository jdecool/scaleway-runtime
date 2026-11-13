<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Tests\Fixtures\App;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shares the Counter with the HTTP routes, to tell whether state leaks between Command and HTTP Invocations.
 */
#[AsCommand('app:count')]
final class CountCommand extends Command
{
    public function __construct(
        private readonly Counter $counter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('times', null, InputOption::VALUE_REQUIRED, '', '1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $times = $input->getOption('times');
        \assert(is_numeric($times));
        for ($i = (int) $times; $i > 0; --$i) {
            $count = $this->counter->increment();
        }
        $output->write(\sprintf('count=%d kernelInstances=%d pid=%d', $count ?? 0, Kernel::$instances, getmypid()));

        return Command::SUCCESS;
    }
}
