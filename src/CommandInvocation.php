<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime;

/**
 * A console command an authenticated CRON Invocation asks to run.
 *
 * Two independent checks, as each alone could fail: the Event must lack the client address headers the platform sets
 * on every HTTP call, an undocumented behaviour, and the CRON arguments must carry the secret set in
 * SCALEWAY_CRON_SECRET (see docs/adr/0005-authenticate-command-invocations-with-a-shared-secret.md).
 * Otherwise a body naming a command is a plain HTTP body.
 */
final readonly class CommandInvocation
{
    public const string SECRET_VAR = 'SCALEWAY_CRON_SECRET';

    /** Set by the platform on every HTTP call, overwriting client values, and absent from CRON Events. */
    public const array HTTP_ONLY_HEADERS = ['x-real-ip', 'x-envoy-external-address'];

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

        $headers = \is_array($event['headers'] ?? null) ? array_change_key_case($event['headers']) : [];
        $reason = match (true) {
            [] !== array_intersect_key($headers, array_flip(self::HTTP_ONLY_HEADERS)) => 'the Event comes from an HTTP call, not a CRON trigger',
            !\is_string($secret) || '' === $secret || !\is_string($data['secret'] ?? null) || !hash_equals($secret, $data['secret']) => \sprintf('the "secret" does not match %s', self::SECRET_VAR),
            default => null,
        };
        if (null !== $reason) {
            // Only bodies meant as Command Invocations are worth a line: APIs may legitimately take a "command" field.
            if (\array_key_exists('secret', $data)) {
                fwrite($errorLog, \sprintf('Command Invocation rejected: %s, the body goes to the Application as a plain HTTP body.', $reason).\PHP_EOL);
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
