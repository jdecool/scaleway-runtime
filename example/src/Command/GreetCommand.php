<?php

namespace App\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

// Run by a CRON trigger whose arguments are {"command": "app:greet", "args": {"name": "..."}, "secret": "..."}.
#[AsCommand('app:greet', 'Greets someone, or fails on demand to show how a failed command is reported')]
final class GreetCommand
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument] string $name = 'world',
        #[Option] bool $yell = false,
        #[Option] bool $fail = false,
    ): int {
        $greeting = \sprintf('Hello %s!', $name);
        $io->writeln($yell ? strtoupper($greeting) : $greeting);
        // A CRON trigger drops the Function Response holding the output: the log shows the command ran.
        $this->logger->info('Command app:greet said: {greeting}', ['greeting' => $greeting]);
        if ($fail) {
            $io->error('Failing on purpose.');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
