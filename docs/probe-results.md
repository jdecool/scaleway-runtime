# Probe results

Measured on 2026-11-14 with the [probe](../probe/README.md) on the `php85` (PHP 8.5.12) and `php84` (PHP 8.4.27) runtimes, region `fr-par`.
Both runtimes behaved identically.
Client addresses are replaced with documentation addresses (`2001:db8::1`, `203.0.113.66`).

## Process

- The runtime is one long-lived PHP CLI process (`sapi` = `cli`, pid 8) running a ReactPHP HTTP server (`/home/app/index.php`, `SCW_RUNTIME_BRIDGE`) that includes `/home/app/function/handler.php` and calls `handle()`.
- Warm Processes are reused: consecutive Invocations hit the same pid with an increasing counter.
- `$_SERVER['SCRIPT_FILENAME']`, `PHP_SELF` and `argv[0]` are `/home/app/index.php`; `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES` are empty.
- The working directory is `/home/app/function`, which is writable, as is `/tmp`.
- Function environment variables (plain and secret) reach `getenv()`; the platform also sets `SCW_APPLICATION_NAME`, `SCW_APPLICATION_ID`, `SCW_NAMESPACE_ID`, `SCW_APPLICATION_REGION`, `SCW_APPLICATION_MEMORY`, `SCW_PAYLOAD_MAX_SIZE` (6291456), `SCW_PUBLIC`, `SCW_HANDLER_NAME`, `SCW_HANDLER_PATH`.
- Anything written to stdout does not end up in the response body.
- An uncaught exception yields `500` with the body `Error while executing function`.

## Deployment and Composer

- When the zip has a `composer.json` at its root, Scaleway runs `composer install` at deploy time (`COMPOSER_DEV_MODE=0`, i.e. `--no-dev`) in `/home/app/function`, replacing any shipped `vendor/`.
- A shipped `composer.lock` is respected (a package pinned to an older version in the lock was installed at that version).
- Composer scripts run (`post-install-cmd`; `post-update-cmd` when no lock is shipped).
- Function environment variables are not available to Composer scripts at deploy time (`APP_ENV` and custom variables were unset).
- A failing Composer script does not fail the deployment: the function still becomes `ready`.
- With `composer.json` and `vendor/` in a subdirectory instead of the zip root, Scaleway does not run Composer and keeps the shipped `vendor/`.

## HTTP Event

Keys: `resource`, `path`, `httpMethod`, `headers`, `multiValueHeaders` (null), `queryStringParameters`, `multiValueQueryStringParameters` (null), `pathParameters` (null), `stageVariables`, `requestContext` (with `httpMethod`), `body`, `isBase64Encoded`.

- The method is in `httpMethod`; there is no `method` key.
- `queryStringParameters` is decoded, sorted by key, and keeps the last value of repeated keys: `?repeated=a&repeated=b&list[]=x&list[]=y` gives `{"repeated": "b", "list[]": "y"}`; it is an empty list `[]` when there is no query string.
- Header names are canonicalised (`X-Custom`, `User-Agent`), single string values.
- There is no `Host` header.
- `isBase64Encoded` is `true` with a base64 body for binary and multipart bodies, `true` with an empty body for GET, and absent for text bodies (JSON, plain text).
- Bodies up to 6 MiB are accepted; 7 MiB is rejected by the platform with `413` before reaching the function.

Headers added by the platform on an external HTTP call:

```
Forwarded: for="[2001:db8::1]";proto=https, for=100.96.1.187
K-Proxy-Request: activator
X-Envoy-External-Address: 2001:db8::1
X-Forwarded-For: 2001:db8::1, 100.96.1.187, 100.96.38.33
X-Forwarded-Proto: https
X-Real-Ip: 2001:db8::1
X-Request-Id: <uuid>
```

Headers sent by the client:

| Header | Behaviour |
| --- | --- |
| `X-Forwarded-For` | Client value prepended: `203.0.113.66,2001:db8::1, 100.96.2.180, 100.96.36.219` |
| `X-Forwarded-Host`, `X-Forwarded-Port` | Passed through unchanged (forgeable) |
| `Forwarded` | Client value replaces the platform one (forgeable) |
| `X-Forwarded-Proto` | Overwritten with `https` |
| `X-Real-Ip`, `X-Envoy-External-Address`, `X-Request-Id`, `K-Proxy-Request` | Overwritten by the platform |
| `Host` | Not forwarded to the function (an unknown host routes to a 404) |

## CRON Event

```json
{
    "path": "/",
    "httpMethod": "POST",
    "headers": {
        "Content-Length": "40",
        "Content-Type": "application/json",
        "Forwarded": "for=100.96.2.180;proto=http",
        "K-Proxy-Request": "activator",
        "User-Agent": "Go-http-client/1.1",
        "X-Forwarded-For": "100.96.2.180, 100.96.36.219",
        "X-Forwarded-Proto": "http",
        "X-Request-Id": "<uuid>"
    },
    "queryStringParameters": [],
    "body": "{\"probe\":\"cron\",\"nested\":{\"list\":[1,2]}}"
}
```

- The trigger `args` arrive as a JSON string in `body`, without `isBase64Encoded`.
- CRON Events carry neither `X-Real-Ip` nor `X-Envoy-External-Address`, which the platform always sets (overwriting client values) on external HTTP calls.

## Context

An `array`: `{"memoryLimitInMb": 256, "functionName": "probe-php85", "functionVersion": ""}`.

## Function Response

- Array header values are sent as repeated headers (`Set-Cookie` included).
- Binary bodies with `isBase64Encoded: true` are decoded by the platform.
- Without `headers`, the response gets `Content-Type: text/plain; charset=utf-8`.
- Custom status codes pass through (`418`).
- `HEAD` responses carry `Content-Length` and no body.
- The platform adds `Access-Control-Allow-Origin: *` and `Access-Control-Allow-Headers: Content-Type` to every response.

## Logs

Measured with the Loki HTTP API of the project's `Scaleway Logs` data source (`scw cockpit data-source list origin=scaleway types.0=logs`), with a `read_only_logs` Cockpit token sent as `Authorization: Bearer`; requests without it get `401`.

- stdout and stderr written by the handler both reach Cockpit, `echo` and `error_log()` alike, as does a `php://stderr` stream opened with `fopen()` like the runtime's.
- An entry is visible through the API 1.5 to 2.4 seconds after the Invocation (7 samples, polling every 0.3 to 1 second).
- Labels: `resource_type="serverless_function"`, `service_name="serverless_function"`, `resource_id` (the function's UUID), `resource_name` (`<namespace name and suffix>-<function name>`, e.g. `scalewayruntimeprobekzb5ehst-probe-php85`), `project_id`, `project_name`, `region`, and `detected_level`.
- Each entry is a JSON line; `stream` tells stdout from stderr, `resource_instance` the instance:

```json
{"message":"DEBUG - probe: written to stdout by the handler source=user stream=stdout","stream":"stdout","resource_instance":"scalewayruntimeprobekzb5ehst-probe-php85-00001-deployment-h4658"}
{"message":"DEBUG - probe: exception escaping the handler source=user stream=stderr","stream":"stderr","resource_instance":"scalewayruntimeprobekzb5ehst-probe-php85-00001-deployment-h4658"}
```

- Everything the function writes is at the `DEBUG` level (`detected_level="debug"`), stderr included.
  The continuations of a split line (below) lack the `DEBUG - ` prefix and get `detected_level="unknown"`.
- The platform logs its own lines with `source=core`: `Function is not ready to serve requests yet...` every second while an instance starts, `Function is now ready to serve requests!`, `Function Triggered: /` before each Invocation, and `INFO  - Forked function has terminated` when an instance stops.
- Each line is a separate entry: a stack trace written in one `fwrite()` gave 29 entries, one per line, with consecutive timestamps.
- A line over 16 KiB is split into entries of 16384 characters (counting the `DEBUG - ` prefix), and cut after 64 KiB: lines of 65536 and 300000 bytes both ended in 4 entries, the last one ending with `... (truncated, line too long)`; a 20000 byte line arrived complete, in 2 entries.
- The runtime's own failure line, checked with a published `jdecool/scaleway-runtime` v0.1.0 application whose Front Controller throws, arrives the same way, one entry per line: `DEBUG - Invocation failed: RuntimeException: <message> in /home/app/function/public/index.php:6 source=user stream=stderr`, then `DEBUG - Stack trace: ...` and one entry per frame.
- An uncaught exception logs its message only, on stderr (`probe: exception escaping the handler` above), without its class, file, line or trace; the Warm Process survives it (same pid, the counter went on).

## Terraform

- Replacing a function (for example changing `runtime`) deletes its CRON triggers server side; Terraform recreates them only on the next apply.

## Not measured

- Whether a deployment with a failing Composer script serves the new code or keeps the previous version.
