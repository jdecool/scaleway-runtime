<?php

declare(strict_types=1);

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

// The SymfonyRuntime way: the runtime passes a blank Command for the Front Controller to configure.
return static function (Command $command): Command {
    $command->addArgument('name', InputArgument::REQUIRED);
    $command->addOption('yell', null, InputOption::VALUE_NONE);
    $command->setCode(static function (InputInterface $input, OutputInterface $output) use ($command): int {
        $name = $input->getArgument('name');
        assert(is_string($name));
        $output->write(sprintf('%s: hello %s', $command->getName(), $input->getOption('yell') ? strtoupper($name) : $name));

        return Command::SUCCESS;
    });

    return $command;
};
