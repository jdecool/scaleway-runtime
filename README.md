# Scaleway Runtime

A [Symfony Runtime](https://symfony.com/doc/current/components/runtime.html) that runs Symfony and PHP applications on [Scaleway Serverless Functions](https://www.scaleway.com/en/serverless-functions/) (PHP runtimes `php84` and `php85`).

## Status

Work in progress: not usable yet.
See the [implementation plan](docs/plan.md) for the design and the roadmap, and the [example application](example) for a working setup.

## Tools

- `vendor/bin/scaleway-init` writes the `handler.php` stub at the application root; commit it and set the function handler to `handler.handle`.
  It refuses to replace a different `handler.php` unless run with `--force`.
- `vendor/bin/scaleway-package [function.zip]` builds the deployment zip: a copy of the application without local, test and secret files (see `Packager::EXCLUDES`), production dependencies installed from `composer.lock` with an authoritative class map, and the prod cache warmed up by `bin/console cache:warmup`.
  It needs the `zip` PHP extension and `composer` on the `PATH`, and warns when the zip exceeds Scaleway's limits (100 MiB zipped, 500 MiB unzipped).
- `vendor/bin/scaleway-serve [127.0.0.1:8000]` starts the Emulator: each HTTP request becomes an Event passed to the application's `handle()`, and the Function Response becomes the HTTP response.
  A CRON Invocation is simulated with a `POST /` carrying the trigger's arguments as JSON body, a Command Invocation by starting the Emulator with `SCALEWAY_CRON_SECRET` set and sending its body the same way.

The Emulator runs `handle()` in one long-lived PHP process, as a Warm Process on Scaleway: code changes need a restart, and a crashed process is replaced by a fresh one.
PHP's built-in server alone could not do this, since it keeps its process but wipes all PHP state after each request.
How Scaleway fills `X-Forwarded-*`, folds repeated query keys and names headers is still assumed.

## CRON triggers and console commands

A CRON trigger calls the Application with a `POST /` whose JSON body holds the trigger's arguments.
To run a console command instead, set a random `SCALEWAY_CRON_SECRET` as a secret environment variable of the function (for instance `openssl rand -hex 32`), and give the trigger these arguments:

```json
{"command": "app:sync", "args": {"source": "s3", "--force": true, "--tag": ["a", "b"]}, "secret": "<SCALEWAY_CRON_SECRET>"}
```

`args` maps argument names, and option names with their dashes, to values, as Symfony's `ArrayInput` does; the command runs without interaction, through the Console Application of the same Kernel.
Exit code 0 answers a 200 and any other exit code a 500, both with the command output as plain text body; a failure is also logged to stderr with that output.
Without the variable, or with another secret, the body is a plain HTTP body and reaches `POST /`: an HTTP client cannot run commands without knowing the secret (see [ADR 0005](docs/adr/0005-authenticate-command-invocations-with-a-shared-secret.md)).

A Front Controller may also return a Console `Application` or a `Command`, as with SymfonyRuntime: the function then only runs Command Invocations, and answers any other Invocation with a 403.
A single `Command` runs whatever `command` the arguments name.
Running commands needs `symfony/console`, and `symfony/framework-bundle` for a Kernel.

## License

Released under the [MIT License](LICENSE).
