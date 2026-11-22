# Architecture decision records

Why the runtime is shaped the way it is, one file per decision.
A superseded record stays, as the history of the decision, and names the one that replaces it.

| ADR | Decision |
| --- | --- |
| [0001](0001-handler-drives-runtime-lifecycle.md) | The Handler drives the Runtime lifecycle instead of `autoload_runtime.php` |
| [0002](0002-reuse-application-across-invocations.md) | The Application is booted once per Warm Process and reset between Invocations |
| [0003](0003-ship-vendor-and-warm-cache-in-zip.md) | The deployment zip ships `vendor/` and a warmed-up cache (superseded by [0008](0008-let-scaleway-install-the-dependencies.md)) |
| [0004](0004-call-response-front-controllers-per-invocation.md) | A Front Controller returning a Response is called for every Invocation |
| [0005](0005-authenticate-command-invocations-with-a-shared-secret.md) | Command Invocations need both a CRON-shaped Event and a shared secret carried in the CRON arguments |
| [0006](0006-default-to-the-prod-env.md) | The Handler defaults `APP_ENV` to `prod`, whatever the `.env` files say |
| [0007](0007-trust-only-headers-the-platform-overwrites.md) | The Request trusts only the headers the platform overwrites |
| [0008](0008-let-scaleway-install-the-dependencies.md) | Scaleway installs the dependencies, and the cache is built on the platform |
| [0009](0009-serve-static-files-from-the-function.md) | The function serves Static Files itself |
| [0013](0013-check-platform-requirements-against-an-explicit-function-runtime.md) | Check declared Platform Requirements against an explicit Function Runtime |
