# Scaleway Runtime

A [Symfony Runtime](https://symfony.com/doc/current/components/runtime.html) that runs Symfony and PHP applications on [Scaleway Serverless Functions](https://www.scaleway.com/en/serverless-functions/) (PHP runtimes `php84` and `php85`).

## Status

Work in progress: not usable yet.
See the [implementation plan](docs/plan.md) for the design and the roadmap.

## Tools

- `vendor/bin/scaleway-init` writes the `handler.php` stub at the application root; commit it and set the function handler to `handler.handle`.
  It refuses to replace a different `handler.php` unless run with `--force`.
- `vendor/bin/scaleway-package [function.zip]` builds the deployment zip: a copy of the application without local, test and secret files (see `Packager::EXCLUDES`), production dependencies installed from `composer.lock` with an authoritative class map, and the prod cache warmed up by `bin/console cache:warmup`.
  It needs the `zip` PHP extension and `composer` on the `PATH`, and warns when the zip exceeds Scaleway's limits (100 MiB zipped, 500 MiB unzipped).
- `vendor/bin/scaleway-serve [127.0.0.1:8000]` starts the Emulator: each HTTP request becomes an Event passed to the application's `handle()`, and the Function Response becomes the HTTP response.
  A CRON Invocation is simulated with a `POST /` carrying the trigger's arguments as JSON body.

The Emulator runs `handle()` in one long-lived PHP process, as a Warm Process on Scaleway: code changes need a restart, and a crashed process is replaced by a fresh one.
PHP's built-in server alone could not do this, since it keeps its process but wipes all PHP state after each request.
How Scaleway fills `X-Forwarded-*`, folds repeated query keys and names headers is still assumed.

## License

Released under the [MIT License](LICENSE).
