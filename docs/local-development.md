# Local development with the Emulator

```sh
vendor/bin/scaleway-serve [127.0.0.1:8000]
```

The Emulator turns each HTTP request into an Event, calls your `handle()` and turns the Function Response back into the HTTP response.
`handle()` runs in one long-lived PHP process, as in a Warm Process: restart the Emulator after changing code.
It sets `APP_ENV=dev` unless started with an `APP_ENV`, as the deployed function defaults to `prod`.
A crashed process is replaced by a fresh one, as Scaleway starts a new instance.

PHP's built-in server receives the requests, and forwards the Events to that process over a Unix socket in a private temporary directory.

The Event and the context are built the way the platform builds them ([measured](probe-results.md)):

- the `httpMethod`, `queryStringParameters` (sorted by key, the last value of a repeated key winning, `[]` without query string) and other keys of the platform;
- header names in `Title-Case`, repeated headers joined with `, `, no `Host`;
- `X-Real-Ip`, `X-Envoy-External-Address`, `X-Request-Id` and `X-Forwarded-Proto: https` set whatever the client sends, the client's `X-Forwarded-For` kept in front of its address, its `X-Forwarded-Host` passed through;
- empty, multipart and non UTF-8 bodies base64-encoded with `isBase64Encoded: true`, text bodies without the flag.

So the Requests are `https`, and their host is `localhost` unless you set `SCALEWAY_HOST`, as on the platform.

No HTTP call can be a CRON Invocation, so the Emulator turns a `POST` on its reserved `/_scaleway/cron` path into a CRON Event whose arguments are the request body:

```sh
curl -X POST http://127.0.0.1:8000/_scaleway/cron --data '{"job": "cleanup"}'
```

Simulate a Command Invocation by starting the Emulator with `SCALEWAY_CRON_SECRET` set, and sending the [Command Invocation body](cron-and-commands.md#command-invocations) the same way:

```sh
SCALEWAY_CRON_SECRET=local-secret vendor/bin/scaleway-serve
curl -X POST http://127.0.0.1:8000/_scaleway/cron --data '{"command": "app:greet", "args": {"name": "Scaleway"}, "secret": "local-secret"}'
```

To run what gets deployed, install the zip the way Scaleway does, without the function's variables, then serve it in `prod`:

```sh
vendor/bin/scaleway-package
unzip -q function.zip -d /tmp/function
composer install --working-dir=/tmp/function --no-dev
APP_ENV=prod APP_SECRET=local php /tmp/function/vendor/bin/scaleway-serve
```

The [example](../example/README.md#run-it-locally)'s `e2e/deploy-locally.sh` does the same with the runtime of this repository.

## Windows

`scaleway-serve` relies on Unix sockets: it runs on macOS and Linux, not on Windows outside WSL, and `scaleway-package` is only tested on macOS and Linux.
Without the `pcntl` extension, stopping the Emulator with `kill` may leave its PHP processes running.
