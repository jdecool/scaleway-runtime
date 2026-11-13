<?php

declare(strict_types=1);

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;

return static function (): Application {
    $application = new class extends Application {
        public function reset(): void
        {
            throw new LogicException('Reset failed on purpose.');
        }
    };
    $command = new Command('app:ok');
    $command->setCode(static fn (): int => Command::SUCCESS);
    $application->addCommand($command);

    return $application;
};
