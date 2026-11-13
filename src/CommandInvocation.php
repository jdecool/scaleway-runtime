<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime;

/**
 * A console command an authenticated CRON Invocation asks to run.
 *
 * Nothing known in an Event tells a CRON Invocation from an HTTP one, so the CRON arguments must carry
 * the secret set in SCALEWAY_CRON_SECRET; without it, a body naming a command is a plain HTTP body
 * (see docs/adr/0005-authenticate-command-invocations-with-a-shared-secret.md).
 * This is the one place to change once the probe finds a platform marker HTTP clients cannot forge.
 */
final readonly class CommandInvocation
{
    public const string SECRET_VAR = 'SCALEWAY_CRON_SECRET';

    /**
     * @param array<string, mixed> $args ArrayInput parameters: argument names and "--option" names to values
     */
    private function __construct(
        public string $command,
        public array $args,
    ) {
    }

    /**
     * @param array<mixed> $event
     * @param resource     $errorLog
     */
    public static function fromEvent(array $event, mixed $errorLog): ?self
    {
        $secret = $_SERVER[self::SECRET_VAR] ?? $_ENV[self::SECRET_VAR] ?? getenv(self::SECRET_VAR);
        $body = \is_string($event['body'] ?? null) ? $event['body'] : '';
        if (true === ($event['isBase64Encoded'] ?? false)) {
            $body = (string) base64_decode($body, true);
        }
        $data = json_decode($body, true);
        if (!\is_array($data) || !\is_string($data['command'] ?? null)) {
            return null;
        }

        if (!\is_string($secret) || '' === $secret || !\is_string($data['secret'] ?? null) || !hash_equals($secret, $data['secret'])) {
            // Only bodies meant as Command Invocations are worth a line: APIs may legitimately take a "command" field.
            if (\array_key_exists('secret', $data)) {
                fwrite($errorLog, \sprintf('Command Invocation rejected: the "secret" does not match %s, the body goes to the Application as a plain HTTP body.', self::SECRET_VAR).\PHP_EOL);
            }

            return null;
        }

        $args = $data['args'] ?? [];
        if (!\is_array($args) || array_any($args, static fn (mixed $value, int|string $name): bool => \is_int($name))) {
            throw new \UnexpectedValueException('The "args" of a Command Invocation must be a JSON object mapping argument and "--option" names to values.');
        }

        return new self($data['command'], array_filter($args, \is_string(...), \ARRAY_FILTER_USE_KEY));
    }
}
