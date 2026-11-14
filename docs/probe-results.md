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

## Terraform

- Replacing a function (for example changing `runtime`) deletes its CRON triggers server side; Terraform recreates them only on the next apply.

## Not measured

- Cockpit logs (where stdout and uncaught exceptions are logged).
- Whether a deployment with a failing Composer script serves the new code or keeps the previous version.
