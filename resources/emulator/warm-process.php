<?php

declare(strict_types=1);

/*
 * The Warm Process of the Emulator: loads the application's handler.php once, then calls handle()
 * for each Event the router forwards, as Scaleway does. Usage: warm-process.php <handler.php> <unix://socket>.
 */

[, $handlerFile, $socket] = ($argv ?? []) + [1 => '', 2 => ''];

require $handlerFile;

// A previous Warm Process that crashed leaves its socket file behind, which would make binding fail.
@unlink(substr($socket, strlen('unix://')));
$server = stream_socket_server($socket, $errorCode, $errorMessage);
if (false === $server) {
    fwrite(\STDERR, "Cannot listen on {$socket}: {$errorMessage}".\PHP_EOL);
    exit(1);
}

// Accepting only fails when interrupted; the Warm Process then exits and the Emulator starts a new one.
while (false !== $connection = @stream_socket_accept($server, -1)) {
    try {
        $payload = (string) stream_get_contents($connection);
        // The Emulator connects without a payload to check this process is up.
        if ('' === $payload) {
            continue;
        }
        $event = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        if (!is_array($event)) {
            throw new UnexpectedValueException('The Event must be a JSON object.');
        }
        // Scaleway's context shape is not documented; the probe function records it.
        $response = handle($event, []);
        fwrite($connection, json_encode($response, \JSON_THROW_ON_ERROR));
    } catch (Throwable $e) {
        // Scaleway would fail the Invocation too, e.g. for a Function Response that is not JSON-encodable.
        fwrite(\STDERR, 'Emulator: invalid Invocation: '.$e.\PHP_EOL);
    } finally {
        fclose($connection);
    }
}
