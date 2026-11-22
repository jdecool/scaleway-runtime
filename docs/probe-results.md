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

### Failed scripts and installs

Measured on 2026-11-14 on `php85` with a scratch function requiring `psr/log`, whose `handler.php` answers a version string set per zip, whether `vendor/autoload.php` loads `Psr\Log\LoggerInterface`, and the marker a `post-install-cmd` writes; the function was deployed with Terraform, one zip after the other, and a second function deployed once.

| Deployment | Function after the apply | What answers |
| --- | --- | --- |
| Second function, first deployment, `post-install-cmd` writing its marker then exiting `1` | `ready`, `error_message` and `build_message` null | The new code, with a complete `vendor/` and a working autoloader, and the script's marker |
| `v1`, scripts succeeding | `ready` | `v1`, with `vendor/` and the marker |
| `v2` over `v1`, `post-install-cmd` writing its marker then exiting `1` | `ready`, `error_message` and `build_message` null | `v2`, with a complete `vendor/` and a working autoloader, and `v2`'s marker |
| `v3` over `v2`, the lock also installing a package whose dist URL does not resolve | `ready`, `error_message` and `build_message` null | `v3`, without any `vendor/` nor marker: the zip's files only |
| `v4` over `v3`, the same failing lock, with a `handler.php` requiring `vendor/autoload.php` first as the one `scaleway-init` writes | `ready` | `500` with `Error 500: Internal Server Error`, and the log line `Warning: require(/home/app/function/vendor/autoload.php): Failed to open stream: No such file or directory in /home/app/function/handler.php on line 4` on stdout; later Invocations answered, as this `handle()` uses nothing from `vendor/` |
| In a second run of `v1` to `v3`, a zip over `v3` whose lock requires an extension no runtime loads (`ext-review-absent`, ignored by the local `composer update`) | `ready`, `error_message` and `build_message` null | The new code, without any `vendor/` nor marker, as a platform requirement the runtime does not meet fails the install too |
| A Symfony application requiring `jdecool/scaleway-runtime` `v0.1.0` from Packagist, its `handler.php` written by `scaleway-init`, first deployed working, then with an extra locked package whose dist URL does not resolve | `ready`, `error_message` and `build_message` null | Every Invocation a `500`: the first one of the instance `Error 500: Internal Server Error`, logging the `Failed to open stream` warning above on stdout, the next ones `Error while executing function`, logging `Class "JDecool\ScalewayRuntime\Handler" not found` on stderr |

- A deployment always serves the new zip: Scaleway never keeps the previous version, whatever Composer does.
- `post-install-cmd` runs once `vendor/` and its autoloader are complete, as with a local `composer install`, so a failing script leaves a working install behind.
- A failing install leaves no `vendor/` at all, Composer downloading every package before installing any.
  The runtime's `Handler` being in `vendor/`, every Invocation of an application then fails.
- The `php85` runtime loads 121 extensions, `intl`, `gd`, `redis`, `amqp`, `mongodb`, `pdo_pgsql` and `pdo_mysql` included, so an unmet platform requirement is more likely the PHP version of a lock resolved for another one.
- Nothing tells a failed deployment from a successful one: the status, `error_message`, `build_message` and `runtime_message` (empty) are the same, and Composer's output reaches neither Cockpit (no entry under the function's `resource_id`, nor anywhere in the project's logs) nor any `scw function` command.
- The new instance logs `Function is not ready to serve requests yet...` and `Function is now ready to serve requests!` as usual, and the previous one `Forked function has terminated` about 40 seconds later.

## Function Runtime Catalogue

Measured on 2026-11-22 with the probe's `runtime` mode on `php84` and `php85`, region `fr-par`, the probe deployed with its Terraform for each, then destroyed.

| Function Runtime | PHP | Loaded extensions | Without `phpversion()` |
| --- | --- | --- | --- |
| `php84` | 8.4.28 | 123 | none |
| `php85` | 8.5.13 | 120 | none |

- `php84` loads `geospatial`, `memcache`, `parle`, `php_trie` and `Stomp`, which `php85` does not; `php85` loads `lexbor` and `uri`, new in PHP 8.5.
- Bundled extensions report the PHP version; `dom` reports `20031129`, and `mysqlnd` reports `mysqlnd 8.4.28`, which Composer cannot parse and sees as version `0`, as the catalogue records it.
- Scaleway upgraded PHP on both Function Runtimes within eight days, without notice: `php84` from 8.4.27 to 8.4.28, `php85` from 8.5.12 to 8.5.13.

The raw outputs are [tests/Fixtures/FunctionRuntimes](../tests/Fixtures/FunctionRuntimes), from which [probe/catalogue.php](../probe/catalogue.php) generates [resources/function-runtimes.php](../resources/function-runtimes.php), naming and versioning PHP and its extensions as Composer's `PlatformRepository` does.
A test fails when the catalogue is not the one the fixtures generate.
The list of Function Runtimes follows [Scaleway's runtime reference](https://www.scaleway.com/en/docs/serverless-functions/reference-content/functions-runtimes/), restricted to those the package's `php` requirement allows.

### Updating the catalogue

The [Function Runtime Catalogue workflow](../.github/workflows/function-runtimes.yml) runs every Monday, and by hand from the Actions tab before each release:

1. it deploys the probe on each Function Runtime, saves its `?mode=runtime` output as the fixture, and destroys the probe whatever the outcome;
2. it regenerates the catalogue, and does nothing more when only the measurement dates changed;
3. otherwise it opens a pull request from the `catalogue-update` branch, or force-pushes the open one, listing the changes.

It needs the repository secrets `SCW_ACCESS_KEY`, `SCW_SECRET_KEY` and `SCW_DEFAULT_PROJECT_ID`, for an API key limited to Serverless Functions in a project dedicated to the probe.
Pull requests opened with the default token do not trigger the CI: set a `CATALOGUE_PR_TOKEN` secret, a fine-grained token with the contents and pull requests permissions, for the CI to check them.
A failed deployment or query fails the job, without retry.
Merging does not release: users get the new catalogue with the next version.

By hand, deploy the probe as its [README](../probe/README.md) says, save `curl -sS "$PROBE_URL/?mode=runtime"` as `tests/Fixtures/FunctionRuntimes/<runtime>.json` for each Function Runtime, and run `php probe/catalogue.php`, which prints what changed.
A new Function Runtime gets its own fixture; the script names it after the PHP version measured, and refuses two fixtures of the same one.

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

## Absolute URLs

Measured on 2026-11-14 with the example deployed on `php85` (runtime `2bca487`, whose host handling is the current one), its `GET /urls` answering the router's `ABSOLUTE_URL`, `getUri()` and `getSchemeAndHttpHost()`, and its `GET /redirect` a `RedirectResponse` to an absolute route URL.
Each request also sent `X-Forwarded-Host: spoofed.example`, `Forwarded: host=spoofed.example` and `X-Forwarded-Port: 8443`, which never showed in any URL.

| `host` (`SCALEWAY_HOST`) | `GET /urls?a=1` | `GET /redirect` |
| --- | --- | --- |
| Unset | `https://localhost/hello?name=url`, `https://localhost/urls?a=1`, `https://localhost` | `302`, `Location: https://localhost/hello?name=redirect` |
| The function's `domain_name`, after a first apply | `https://<domain_name>/hello?name=url`, `https://<domain_name>/urls?a=1`, `https://<domain_name>` | `302`, `Location: https://<domain_name>/hello?name=redirect`, which `curl -L` follows to `{"message":"Hello redirect!"}` |
| `api.example.com`, standing for a custom domain | `https://api.example.com/hello?name=url`, `https://api.example.com/urls?a=1`, `https://api.example.com` | `302`, `Location: https://api.example.com/hello?name=redirect` |

Changing `host` is an in-place update of the function, whose new value is served once the apply returns.
With `framework.trusted_hosts: ['^api\.example\.com$']`, `host` set to `api.example.com` answered `200`, and `host` unset answered every Request with a `400`, forged `X-Forwarded-Host: api.example.com` and `Forwarded: host=api.example.com` not helping, and a `Host: api.example.com` routing to the platform's `404`.

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

## CRON Invocations

Measured on 2026-11-14 on `php85` with the [example](../example), whose lock pins `jdecool/scaleway-runtime` at `dev-main` 2bca487 (v0.1.0 but for an Emulator fix), its two triggers set to every minute in a copy of its Terraform, with two more: the `app:greet` arguments with a wrong secret, and with `--fail`.
For three minutes, each of the four triggers fired once a minute, 1 to 15 seconds past it, each Invocation preceded by `DEBUG - Function Triggered: / source=core`.
Their stderr lines, without the `DEBUG - ` prefix and ` source=user stream=stderr` suffix:

| Trigger arguments | Logged |
| --- | --- |
| `{"job": "hourly"}` | `[info] Matched route "app_demo_cron".`, then `[info] CRON Invocation received with the arguments ["job"]` |
| `app:greet`, `{"name": "CRON"}`, the right secret | `[info] Command app:greet said: Hello CRON!` only: no route matched, no failure |
| `app:greet`, a wrong secret | `Command Invocation rejected: the "secret" does not match SCALEWAY_CRON_SECRET, the body goes to the Application as a plain HTTP body.`, then the `POST /` lines |
| `app:greet`, `{"--fail": true}`, the right secret | `[info] Command app:greet said: Hello world!`, then `Command "app:greet" failed with exit code 1: Hello world!` and one entry per line of the output, blank ones included |

- The wrong secret was rejected for the secret, not for the HTTP-only headers: CRON Events lack them, as the probe found.
- The same `app:greet` body with the right secret, sent over HTTP by `example/e2e/run.sh`, logged `Command Invocation rejected: the Event comes from an HTTP call, not a CRON trigger, ...` and landed on `POST /`.
- The failing command was not retried: one Invocation a minute, like the others.
- The status code of a CRON Invocation's Function Response appears nowhere in the logs.
- The CRON secret appeared in none of the 110 entries of the run.

## Context

An `array`: `{"memoryLimitInMb": 256, "functionName": "probe-php85", "functionVersion": ""}`.

## Function Response

- Array header values are sent as repeated headers (`Set-Cookie` included).
- Binary bodies with `isBase64Encoded: true` are decoded by the platform.
- Without `headers`, the response gets `Content-Type: text/plain; charset=utf-8`.
- Custom status codes pass through (`418`).
- `HEAD` responses carry `Content-Length` and no body.
- The platform adds `Access-Control-Allow-Origin: *` and `Access-Control-Allow-Headers: Content-Type` to every response.

### Size and encodings

Measured on 2026-11-22 on `php85` (256 MB) with the probe's `size` and `encoded` modes (step 12), sizes being the decoded body.

- No size limit showed up: text bodies of up to 28 MiB and base64 ones of up to 12 MiB reached the client whole.
- From 10 MiB on, responses failed intermittently with a `504` after exactly 60 seconds, text and base64 alike (10 MiB: 4 of 8 failed; 16 to 28 MiB base64: all failed); a successful 28 MiB text response took 44 seconds.
- Up to 5 MiB, all 15 base64 responses of 1, 3 and 5 MiB succeeded; 6 to 8 MiB succeeded in a single run each.
- The platform does not compress responses: a 100 KiB text body with `Accept-Encoding: gzip, br` came back without `Content-Encoding`.
- A `Content-Encoding` set by the function, `gzip` or `zstd`, reaches the client with `Vary`, and the base64 body decoded by the platform is the compressed stream as sent.
- The `php85` runtime loads 120 extensions: `zlib` and `zstd`, but not `brotli`.

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
