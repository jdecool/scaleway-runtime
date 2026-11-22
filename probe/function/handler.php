<?php

/*
 * Probe function: answers the open platform questions by reporting what Scaleway passes to,
 * and accepts from, a PHP handler. Dependency-free on purpose; see ../README.md.
 */

// Loading a shipped vendor/ from the handler's directory is one of the questions.
require __DIR__.'/vendor/autoload.php';

const PROBE_HISTORY = '/tmp/probe-history.jsonl';

function handle($event, $context)
{
    static $invocations = 0;
    static $firstInvocationAt = null;
    ++$invocations;
    $firstInvocationAt ??= date(DATE_ATOM);

    // CRON Invocations have nobody to answer to: the logs and the history keep their Events.
    $record = ['at' => date(DATE_ATOM), 'pid' => getmypid(), 'invocation' => $invocations, 'event' => $event, 'context' => $context];
    error_log('probe: '.json_encode($record, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
    @file_put_contents(PROBE_HISTORY, json_encode($record, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND);

    $query = is_array($event['queryStringParameters'] ?? null) ? $event['queryStringParameters'] : [];

    switch ($query['mode'] ?? 'facts') {
        case 'headers':
            // Are list values accepted, and sent as repeated headers?
            return [
                'statusCode' => 200,
                'headers' => [
                    'Content-Type' => 'text/plain',
                    'X-Probe-Single' => 'one',
                    'X-Probe-Multi' => ['first', 'second'],
                    'Set-Cookie' => ['probe_a=1; Path=/', 'probe_b=2; Path=/; HttpOnly'],
                ],
                'body' => "Check the response headers.\n",
            ];

        case 'binary':
            return [
                'statusCode' => 200,
                'headers' => ['Content-Type' => 'application/octet-stream'],
                'body' => base64_encode(implode('', array_map('chr', range(0, 255)))),
                'isBase64Encoded' => true,
            ];

        case 'size':
            // The largest Function Response the platform accepts bounds the Static Files the runtime can serve.
            // Capped so the public probe cannot exhaust its memory.
            $bytes = min(max((int) ($query['bytes'] ?? 0), 0), 32 << 20);
            if ('base64' === ($query['encoding'] ?? 'text')) {
                return [
                    'statusCode' => 200,
                    'headers' => ['Content-Type' => 'application/octet-stream'],
                    'body' => base64_encode(str_repeat("\xFF", $bytes)),
                    'isBase64Encoded' => true,
                ];
            }

            return ['statusCode' => 200, 'headers' => ['Content-Type' => 'text/plain'], 'body' => str_repeat('a', $bytes)];

        case 'encoded':
            // Does a Content-Encoding set by the function reach the client with the body untouched?
            $text = str_repeat("Static File compressed by the function.\n", 1000);
            $encoders = ['gzip' => 'gzencode', 'br' => 'brotli_compress', 'zstd' => 'zstd_compress'];
            $encoding = isset($encoders[$query['encoding'] ?? '']) ? $query['encoding'] : 'gzip';
            if (!function_exists($encoders[$encoding])) {
                return ['statusCode' => 501, 'headers' => ['Content-Type' => 'text/plain'], 'body' => "No extension compresses with {$encoding}.\n"];
            }

            return [
                'statusCode' => 200,
                'headers' => ['Content-Type' => 'text/plain', 'Content-Encoding' => $encoding, 'Vary' => 'Accept-Encoding', 'X-Probe-Sha256' => hash('sha256', $text)],
                'body' => base64_encode($encoders[$encoding]($text)),
                'isBase64Encoded' => true,
            ];

        case 'status':
            return ['statusCode' => (int) ($query['code'] ?? 418), 'headers' => ['Content-Type' => 'text/plain'], 'body' => "status\n"];

        case 'no-headers':
            // The documented minimal response: which Content-Type does the platform add?
            return ['statusCode' => 200, 'body' => 'no headers'];

        case 'body':
            $body = (string) ($event['body'] ?? '');
            $decoded = true === ($event['isBase64Encoded'] ?? null) ? base64_decode($body) : $body;

            return json_response([
                'isBase64Encoded' => $event['isBase64Encoded'] ?? null,
                'receivedLength' => strlen($body),
                'decodedLength' => strlen($decoded),
                'decodedSha256' => hash('sha256', $decoded),
            ]);

        case 'stdout':
            echo "probe: written to stdout by the handler\n";

            return ['statusCode' => 200, 'headers' => ['Content-Type' => 'text/plain'], 'body' => "Is the stdout line in the logs or in this body?\n"];

        case 'stderr':
            // Written like the runtime logs its failures: does a multi-line exception stay one log entry?
            $stderr = fopen('php://stderr', 'w');
            fwrite($stderr, 'probe: written to stderr: '.new RuntimeException('probe: multi-line').PHP_EOL);
            // Is a long line, like a whole stack trace on one line, kept or cut? Capped so the public probe cannot exhaust its memory.
            fwrite($stderr, 'probe: long line '.str_repeat('a', min(max((int) ($query['bytes'] ?? 65536), 0), 1 << 20)).' end of the long line'.PHP_EOL);

            return ['statusCode' => 200, 'headers' => ['Content-Type' => 'text/plain'], 'body' => "Check the stderr lines in the logs.\n"];

        case 'exception':
            throw new RuntimeException('probe: exception escaping the handler');

        case 'history':
            return ['statusCode' => 200, 'headers' => ['Content-Type' => 'application/x-ndjson'], 'body' => (string) @file_get_contents(PROBE_HISTORY)];

        default:
            return json_response(facts($event, $context, $invocations, $firstInvocationAt));
    }
}

function facts($event, $context, int $invocations, string $firstInvocationAt): array
{
    $tmpProbe = '/tmp/probe-write-'.getmypid();
    $codeProbe = __DIR__.'/probe-write-'.getmypid();

    return [
        'process' => [
            'pid' => getmypid(),
            'ppid' => function_exists('posix_getppid') ? posix_getppid() : null,
            'uid' => function_exists('posix_geteuid') ? posix_geteuid() : getmyuid(),
            'user' => get_current_user(),
            'invocationsInThisProcess' => $invocations,
            'firstInvocationAt' => $firstInvocationAt,
            'sapi' => PHP_SAPI,
            'phpVersion' => PHP_VERSION,
            'memoryUsage' => memory_get_usage(true),
            'obLevel' => ob_get_level(),
            'includedFiles' => get_included_files(),
        ],
        'event' => $event,
        'eventKeys' => is_array($event) ? array_keys($event) : null,
        'context' => $context,
        'contextType' => get_debug_type($context),
        'filesystem' => [
            'cwd' => getcwd(),
            'handlerFile' => __FILE__,
            'codeDirWritable' => is_writable(__DIR__),
            'codeDirWriteWorks' => false !== @file_put_contents($codeProbe, 'x') && @unlink($codeProbe),
            'codeDirListing' => scandir(__DIR__),
            'vendorListing' => @scandir(__DIR__.'/vendor'),
            'vendorMarkerKept' => defined('PROBE_VENDOR_MARKER'),
            'composerLockPresent' => is_file(__DIR__.'/composer.lock'),
            // Written by the composer.json scripts: tells whether Composer ran its scripts, and with --no-dev or not.
            'composerRun' => json_decode((string) @file_get_contents(__DIR__.'/composer-run.json'), true),
            'tmpWritable' => is_writable('/tmp'),
            'tmpWriteWorks' => false !== @file_put_contents($tmpProbe, 'x') && @unlink($tmpProbe),
            'tmpFreeBytes' => @disk_free_space('/tmp'),
            'tmpListing' => @scandir('/tmp'),
            'sysTempDir' => sys_get_temp_dir(),
        ],
        'globals' => [
            'server' => redact($_SERVER),
            'argv' => $GLOBALS['argv'] ?? null,
            'get' => $_GET,
            'post' => $_POST,
            'cookie' => $_COOKIE,
            'files' => $_FILES,
        ],
        'env' => redact(getenv()),
        'ini' => array_combine($names = [
            'memory_limit', 'max_execution_time', 'upload_max_filesize', 'post_max_size', 'output_buffering',
            'display_errors', 'log_errors', 'error_log', 'error_reporting', 'enable_post_data_reading', 'variables_order',
            'opcache.enable', 'opcache.enable_cli', 'opcache.validate_timestamps', 'opcache.preload', 'disable_functions',
            'date.timezone', 'default_socket_timeout', 'arg_separator.output',
        ], array_map(static fn (string $name) => ini_get($name), $names)),
        'extensions' => get_loaded_extensions(),
    ];
}

// The facts are meant to be pasted back, so secrets must not travel with them.
function redact(array $values): array
{
    foreach ($values as $name => $value) {
        if (is_string($value) && preg_match('/SECRET|TOKEN|PASSWORD|KEY/i', (string) $name)) {
            $values[$name] = 'redacted, '.strlen($value).' chars';
        }
    }

    return $values;
}

function json_response(array $data): array
{
    return [
        'statusCode' => 200,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
    ];
}
