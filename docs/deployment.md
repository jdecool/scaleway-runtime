# Deployment

[example/terraform](../example/terraform/main.tf) deploys the [example application](../example) with its `php85` runtime, `handler.handle`, `APP_ENV=prod`, the `APP_SECRET` and `SCALEWAY_CRON_SECRET` secrets, an optional `SCALEWAY_HOST`, a CRON trigger calling `POST /` and one running a command.
Its [README](../example/README.md#deploy-it) gives the commands.
That Terraform code was written against the `scaleway/scaleway` provider v2.92 documentation and has never been applied; the [probe](../probe/terraform/main.tf)'s, written the same way, has.

Redeploy by running `scaleway-package` again, then `terraform apply`.
Replacing a function, for instance to change its `runtime`, deletes its CRON triggers on Scaleway's side: give them a `lifecycle { replace_triggered_by = [scaleway_function.<name>.id] }`, as the example does, so the same apply recreates them.

## Composer at deploy time

Scaleway runs `composer install --no-dev` in the function's directory when the zip holds a `composer.json` at its root, replacing any shipped `vendor/`.
Every locked package must therefore be downloadable from Scaleway, and the function's environment variables are not available to Composer scripts.
The lock must also install on the function's runtime: set `config.platform.php` in `composer.json` to its PHP version (`8.4` for `php84`, `8.5` for `php85`) so that `composer update` resolves for it.
`config.platform` only steers Composer's resolution: run the [compatibility check](compatibility-check.md), or package with `--runtime`, to check the lock against what Scaleway provides.
A failing install or script does not fail the deployment, and Scaleway serves the new code anyway, never the previous version ([measured](probe-results.md#failed-scripts-and-installs)):

- a failing script runs once `vendor/` and its autoloader are complete, so the new code works, without what the script should have done (a warmed cache, generated files...);
- a failing install, from a package Scaleway cannot download or a platform requirement its runtime does not meet, leaves no `vendor/` at all: every Invocation answers a `500`, the first one of each instance logging `Failed to open stream` for `vendor/autoload.php` on stdout, the next ones `Class "JDecool\ScalewayRuntime\Handler" not found` on stderr.

Neither shows anywhere: the function is `ready`, its `error_message` and `build_message` stay empty, and Composer's output reaches neither Cockpit nor `scw`.
So after each deploy, call a route that should answer a `200`, and check the function's [logs](logs.md) if it does not:

```sh
curl -sS -o /dev/null -w '%{http_code}\n' "https://$(scw function function list name=<function name> -o json | jq -r '.[0].domain_name')/<route>"
```

Keep deploy-time scripts to what the application can do without, as a cache warmup the first Invocation redoes, and run whatever it needs at runtime instead.
