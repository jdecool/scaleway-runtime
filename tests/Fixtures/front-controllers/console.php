<?php

declare(strict_types=1);

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

return static function (): Application {
    $greet = new Command('app:greet');
    $greet->addArgument('name', InputArgument::OPTIONAL);
    $greet->addOption('tag', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY);
    $greet->addOption('fail', null, InputOption::VALUE_NONE);
    $greet->setCode(static function (InputInterface $input, OutputInterface $output): int {
        $name = $input->getArgument('name') ?? new SymfonyStyle($input, $output)->ask('Who?', 'nobody');
        assert(is_string($name));
        $tags = $input->getOption('tag');
        assert(is_array($tags));
        $output->writeln(sprintf('<info>Hello %s</info> [%s]', $name, implode(',', array_filter($tags, is_string(...)))));

        return $input->getOption('fail') ? 3 : Command::SUCCESS;
    });

    $throw = new Command('app:throw');
    $throw->setCode(static fn (): never => throw new LogicException('Thrown on purpose.'));

    $error = new Command('app:error');
    $error->setCode(static fn (): never => throw new Error('Error on purpose.'));

    $application = new Application();
    $application->addCommands([$greet, $throw, $error]);

    return $application;
};
