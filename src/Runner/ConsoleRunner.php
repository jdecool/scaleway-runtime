<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Runner;

use JDecool\ScalewayRuntime\CommandInvocation;
use JDecool\ScalewayRuntime\HttpConverter;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs Command Invocations through the Console Application, and hands every other Invocation to the HTTP runner.
 *
 * A command exiting with 0 answers a 200, any other exit code a 500, both with the command output as body.
 */
final class ConsoleRunner implements InvocationRunner
{
    private ?Application $console = null;

    /**
     * @param \Closure(): Application $createConsole called on the first Command Invocation only, so HTTP-only
     *                                               Applications never need symfony/console
     * @param InvocationRunner|null   $httpRunner    null for Applications serving Command Invocations only
     * @param resource                $errorLog
     */
    public function __construct(
        private readonly \Closure $createConsole,
        private readonly ?InvocationRunner $httpRunner,
        private readonly mixed $errorLog,
    ) {
    }

    public function invoke(array $event, mixed $context): array
    {
        $invocation = CommandInvocation::fromEvent($event, $this->errorLog);
        if (null === $invocation) {
            return $this->httpRunner?->invoke($event, $context)
                ?? ['statusCode' => 403, 'headers' => ['Content-Type' => 'text/plain; charset=UTF-8'], 'body' => 'Forbidden'];
        }

        if (null === $this->console) {
            $console = ($this->createConsole)();
            // A Warm Process must survive the command, and its output must reach the Function Response.
            $console->setAutoExit(false);
            $console->setCatchExceptions(true);
            $console->setCatchErrors(true);
            $this->console = $console;
        }

        // A single-command Application has no "command" argument: it runs its command whatever the name, as from a shell.
        $input = new ArrayInput($this->console->isSingleCommand() ? $invocation->args : ['command' => $invocation->command] + $invocation->args);
        // Nobody can answer a question during a CRON Invocation: questions get their default answer.
        $input->setInteractive(false);
        $output = new BufferedOutput(decorated: false);

        // The Kernel resets its services before handling a request, not before a command, nor after one.
        $this->console->reset();
        try {
            $exitCode = $this->console->run($input, $output);
        } finally {
            $this->console->reset();
        }

        $body = $output->fetch();
        if (0 !== $exitCode) {
            // A CRON trigger may drop the Function Response, so the log is where the failure stays visible.
            fwrite($this->errorLog, \sprintf('Command "%s" failed with exit code %d: %s', $invocation->command, $exitCode, $body).\PHP_EOL);
        }

        return HttpConverter::toFunctionResponse(new Response($body, 0 === $exitCode ? 200 : 500, ['Content-Type' => 'text/plain; charset=UTF-8']));
    }
}
