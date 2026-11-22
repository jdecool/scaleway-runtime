# CRON triggers and Command Invocations

## CRON triggers

A CRON trigger calls the Application with a `POST /` whose JSON body holds the trigger's arguments, over plain `http` and without client address (`getClientIp()` is `127.0.0.1`).
With a Kernel, handle it like any route:

```php
#[Route('/', methods: ['POST'])]
public function cron(Request $request): Response
{
    $args = $request->toArray(); // the trigger's arguments
    // ...
}
```

Any HTTP client can call `POST /` too: tell CRON Invocations apart with a secret of your own in the arguments, or run a command instead.

## Command Invocations

A CRON trigger may run a console command instead, through the Console Application of the same Kernel.

1. Generate a random secret, for instance with `openssl rand -hex 32`.
2. Set it as the `SCALEWAY_CRON_SECRET` secret environment variable of the function.
3. Give the CRON trigger these arguments:

```json
{"command": "app:sync", "args": {"source": "s3", "--force": true, "--tag": ["a", "b"]}, "secret": "<SCALEWAY_CRON_SECRET>"}
```

`args` is optional, and maps argument names, and option names with their dashes, to values, as Symfony's `ArrayInput` does.
The command runs without interaction: questions get their default answer.
Services are reset around each command, so it shares no state with the Invocations before and after it.

| Outcome | Function Response |
| --- | --- |
| Exit code 0 | 200, the command output as plain text body |
| Any other exit code | 500, the command output as plain text body, also logged to stderr |
| `args` not a JSON object | 500 |
| The Front Controller returns a Response, a PSR-15 handler or a plain `HttpKernelInterface` | 500, as there is no Console Application to run the command |

Nothing shows the Function Response of a CRON Invocation, and a failed one is not retried: the [logs](logs.md) are where the outcome of a command shows up, so log what it does ([measured](probe-results.md#cron-invocations)).

## Security model

A body only becomes a Command Invocation when both hold:

- the Event carries neither `X-Real-Ip` nor `X-Envoy-External-Address`, which the platform sets on every HTTP call, overwriting what the client sends, and which CRON Events lack;
- its `secret` matches `SCALEWAY_CRON_SECRET`, compared in constant time.

Otherwise the body reaches the Application as a plain HTTP body on `POST /`, and a rejected body carrying a `secret` is logged (without its value).
So no HTTP call can run a command, even with the secret, and without `SCALEWAY_CRON_SECRET`, or with an empty one, Command Invocations are off.
The first check relies on undocumented platform behaviour, which is why the secret stays required.

Keep in mind that:

- the secret is stored in clear in the trigger's arguments, readable by anyone who can read the function's triggers or the Terraform state;
- rotating it means updating the variable and every CRON trigger together.

See [ADR 0005](adr/0005-authenticate-command-invocations-with-a-shared-secret.md) for the alternatives considered.
