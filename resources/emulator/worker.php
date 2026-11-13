<?php

declare(strict_types=1);

/*
 * The Warm Process of the Emulator: loads the application's handler.php once, then calls handle()
 * for each Event the router forwards, as Scaleway does. Usage: worker.php <handler.php> <port>.
 */

[, $handlerFile, $port] = ($argv ?? []) + [1 => '', 2 => ''];

require $handlerFile;

$server = stream_socket_server('tcp://127.0.0.1:'.$port, $errorCode, $errorMessage);
if (false === $server) {
    fwrite(\STDERR, "Cannot listen on port {$port}: {$errorMessage}".\PHP_EOL);
    exit(1);
}

// Accepting only fails when interrupted; the worker then exits and the Emulator starts a new one.
while (false !== $connection = @stream_socket_accept($server, -1)) {
    try {
        $payload = (string) stream_get_contents($connection);
        // The Emulator connects without a payload to check this worker is up.
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
