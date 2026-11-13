<?php

declare(strict_types=1);

/*
 * The php -S router of the Emulator: the built-in server parses HTTP but wipes all PHP state after each
 * request, so the Handler runs in a separate long-lived worker, and this router forwards Events to it.
 */

use JDecool\ScalewayRuntime\Emulator;

require (string) getenv('SCALEWAY_EMULATOR_AUTOLOAD');

$server = static fn (string $key): string => is_string($_SERVER[$key] ?? null) ? $_SERVER[$key] : '';
$headers = [];
foreach (getallheaders() as $name => $value) {
    if (is_string($value)) {
        $headers[(string) $name] = $value;
    }
}
$event = Emulator::toEvent(
    $server('REQUEST_METHOD'),
    $server('REQUEST_URI'),
    $headers,
    // Raw even for form posts, as the server runs with enable_post_data_reading=0.
    (string) file_get_contents('php://input'),
    $server('REMOTE_ADDR'),
);

$response = null;
$connection = @stream_socket_client('tcp://127.0.0.1:'.getenv('SCALEWAY_EMULATOR_WORKER_PORT'), $errorCode, $errorMessage);
if (false !== $connection) {
    // Scaleway allows Invocations of up to 60 minutes.
    stream_set_timeout($connection, 3600);
    fwrite($connection, json_encode($event, \JSON_THROW_ON_ERROR));
    stream_socket_shutdown($connection, \STREAM_SHUT_WR);
    $response = json_decode((string) stream_get_contents($connection), true);
    fclose($connection);
}

if (!is_array($response)) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Emulator: the Warm Process returned no Function Response, see its output. ', $errorMessage, \PHP_EOL;

    return;
}

http_response_code(is_int($response['statusCode'] ?? null) ? $response['statusCode'] : 200);
foreach (is_array($response['headers'] ?? null) ? $response['headers'] : [] as $name => $values) {
    foreach (array_filter((array) $values, is_scalar(...)) as $value) {
        header($name.': '.$value, false);
    }
}
$body = is_string($response['body'] ?? null) ? $response['body'] : '';
echo true === ($response['isBase64Encoded'] ?? false) ? base64_decode($body) : $body;
