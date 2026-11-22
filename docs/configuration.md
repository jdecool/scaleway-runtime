# Configuration

## Function handler

Set the handler of the Scaleway function to `handler.handle`.

## Front Controller

The Front Controller defaults to `public/index.php`, relative to the project dir.
Choose another one with the `front_controller` runtime option, in `composer.json`:

```json
{
    "extra": {
        "runtime": {
            "front_controller": "public/api.php"
        }
    }
}
```

Or with the `APP_RUNTIME_OPTIONS` environment variable, a JSON object that wins over `extra.runtime`:

```sh
APP_RUNTIME_OPTIONS='{"front_controller":"bin/console"}'
```

The other `SymfonyRuntime` options (`project_dir`, `env_var_name`, `dotenv_path`...) work as usual.
`extra.runtime.class` and `APP_RUNTIME` are ignored: they name the runtime the same application uses elsewhere (FPM, FrankenPHP), and the Handler always uses `JDecool\ScalewayRuntime\ScalewayRuntime`.

## Environment variables

Set these on the function, in production:

| Variable | Kind | Why |
| --- | --- | --- |
| `APP_SECRET` | Secret | Symfony's `kernel.secret`, used to sign URLs, cookies and CSRF tokens. |
| `SCALEWAY_HOST` | Plain | The public host of the function, for absolute URLs, see [Host](#host). |
| `SCALEWAY_CRON_SECRET` | Secret | Only to run console commands from CRON triggers, see [Command Invocations](cron-and-commands.md#command-invocations). |
| `APP_ENV` | Plain | Only to run another env than `prod`, see below. |

The `.env` file is shipped in the zip, and the one Symfony generates says `APP_ENV=dev`, whose error pages show stack traces to anyone.
So the runtime runs `prod` when the function's environment sets no `APP_ENV` (or an empty one), whatever the `.env` files say ([ADR 0006](adr/0006-default-to-the-prod-env.md)).
Only an `APP_ENV` variable of the function, or the `env` runtime option, picks another env.
`APP_DEBUG` follows: off in `prod`, unless set by the function's environment or a `.env` file.
The Emulator runs `dev` instead, unless it is started with an `APP_ENV`.

For every other variable, real environment variables win over `.env` files, as usual with Symfony's Dotenv.
`.env.local`, `.env.local.php` and `.env.*.local` are never shipped.

## Host

No `Host` header reaches the function, so the runtime cannot know the domain it is called on.
Every Request gets the host of the `host` runtime option, which defaults to the `SCALEWAY_HOST` environment variable, then to `localhost`:

```sh
SCALEWAY_HOST=api.example.com
```

The scheme comes from the platform's `X-Forwarded-Proto` (`https` for HTTP calls), the port is the scheme's default.
Absolute URLs (`UrlGeneratorInterface::ABSOLUTE_URL`, signed URLs, redirects built from the Request) are only right once the host is set.
Set it to the function's domain (its `domain_name` on Scaleway) or to its custom domain.
With Terraform, a function cannot reference its own `domain_name` in its variables (the reference would be a cycle): use a custom domain, or set the variable after a first apply.
An application configuring `framework.trusted_hosts` answers every Request with a 400 until the host is set to one of its trusted hosts.
Both were [measured on Scaleway](probe-results.md#absolute-urls), forged `X-Forwarded-Host` and `Forwarded` headers never changing the host.

## Cache and writable directories

The code directory is writable on Scaleway, so the Kernel builds its cache in `var/cache` at the first Invocation of each Warm Process, which pays the container compilation once.
To pay it at deploy time instead, warm the cache from a Composer script, which Scaleway runs after installing the dependencies:

```json
{
    "scripts": {
        "post-install-cmd": ["@php bin/console cache:warmup --env=prod"]
    }
}
```

Only do it when the warmup needs no secret: the function's environment variables are not available at deploy time, and a failing script does not fail the deployment, the first Invocation then building the cache as usual.

> [!WARNING]
> Symfony Flex's `auto-scripts` also run at deploy time, with the `APP_ENV` of the shipped `.env`, usually `dev`: `cache:clear` then fails silently, its dev bundles not being installed with `--no-dev`.
> Make them env-explicit (`"cache:clear --env=prod": "symfony-cmd"`), or remove `cache:clear` from them and warm the cache as above.

When the project's `var/` directory (or the project dir, without `var/`) is not writable, the runtime sets, unless your application already did:

- `APP_CACHE_DIR` to `<temp dir>/cache` and `APP_LOG_DIR` to `<temp dir>/log`;
- `APP_BUILD_DIR` to `var/cache`, only when `var/cache/<env>` holds a container warmed before, so that it is used as is.

Kernels using `MicroKernelTrait` read these variables.
Other Kernels must override `getCacheDir()`, `getBuildDir()` and `getLogDir()` themselves.
Logs written to files are lost with the instance: send them to `php://stderr` instead, which the runtime also uses for its own errors.

## Errors

An exception escaping the Application (a Kernel failing to boot, an unsupported Front Controller...) is logged to stderr ([Logs](logs.md)) and answered with a 500.
Its body is `Internal Server Error`, or the full exception when `APP_DEBUG` is true.
A Kernel that failed to boot is built again from scratch on the next Invocation.

Errors the Kernel handles itself get Symfony's web error pages, as the runtime sets `APP_RUNTIME_MODE=web=1&worker=1` unless your environment sets it.
Outside a web SAPI, FrameworkBundle would pick its CLI error renderer instead, which dumps the exception even in `prod`.
