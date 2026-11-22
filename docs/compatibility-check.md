# Compatibility check

```sh
vendor/bin/scaleway-doctor --runtime=php84
```

A Platform Requirement (`php` or `ext-*`) the Function Runtime does not meet makes Scaleway's `composer install` fail at deploy time, without failing the deployment: every Invocation then answers a `500` (see [Composer at deploy time](deployment.md#composer-at-deploy-time)).
`scaleway-doctor` checks, before you deploy, the Platform Requirements that install checks, against the Function Runtime the function declares, named by `--runtime` (`php84` or `php85`).
It never guesses it from your local PHP.
It reads `composer.json` and `composer.lock`, needs neither Scaleway credentials nor network access, and refuses a stale lock as [`scaleway-package`](packaging.md) does.

It checks what `composer install --no-dev` checks on Scaleway:

- the root requirements the lock records, and those of every locked production package, development ones excluded;
- each one met by the Function Runtime, or by a `provide` or `replace` of the application or of a locked package;
- constraints evaluated by Composer's own engine, against the PHP and extension versions Composer would see on Scaleway.

Each requirement is:

- **satisfied**, when the measured version matches;
- **incompatible**, when the extension is not loaded, or the measured version of PHP or of the extension does not match;
- **unknown**, for the platform packages the catalogue does not measure, as `lib-icu` or `php-64bit`.

Composer's own APIs (`composer`, `composer-plugin-api`, `composer-runtime-api`) are not checked: Scaleway installs with Composer 2.

The report, on stderr, lists the incompatible and unknown requirements, with what declares them and what the Function Runtime provides:

```text
INCOMPATIBLE  ext-foo *     required by acme/bar v2.1.0      php84: not loaded (measured 2026-11-22)
              php85 meets it: deploy on php85, or use Serverless Containers for custom extensions or versions.
UNKNOWN       lib-icu >=72  required by symfony/intl v8.0.3  not in the Function Runtime Catalogue

48 satisfied, 1 unknown, 1 incompatible on php84 (measured 2026-11-22)
This covers declared Platform Requirements only, not whether the application runs.
```

It exits with `0` when every requirement is satisfied or only unknowns remain, and with `1` on an incompatible requirement or an invalid input.
For an incompatible requirement, it names the other Function Runtimes that meet it, if any.
Otherwise, custom extensions or versions need [Serverless Containers](https://www.scaleway.com/en/serverless-containers/), which this package does not target.

What each Function Runtime provides comes from the Function Runtime Catalogue shipped with the package, [resources/function-runtimes.php](../resources/function-runtimes.php).
It holds measurements only, made on Scaleway with the [probe](../probe), each a complete inventory: an extension it does not list is not loaded.
A measurement describes the Function Runtime on its date, which the report shows, not a guarantee that Scaleway keeps the same versions.
The measured PHP patch version is authoritative: if Scaleway has upgraded PHP since, update the package for a newer catalogue.

The [Emulator](local-development.md) runs on your local PHP, which may load extensions or versions the Function Runtime lacks: an application working locally can still fail to install on Scaleway, which is what this check catches.
A successful check covers declared Platform Requirements only: an extension used without being required is not checked, and calling a route after the deployment, as [Composer at deploy time](deployment.md#composer-at-deploy-time) shows, remains the proof that it works.
