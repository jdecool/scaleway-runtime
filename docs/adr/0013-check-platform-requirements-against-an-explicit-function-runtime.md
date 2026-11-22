# Check declared Platform Requirements against an explicit Function Runtime

A Platform Requirement the Function Runtime does not meet makes Scaleway's `composer install` fail at deploy time without failing the deployment: the function is `ready` without any `vendor/`, and every Invocation answers a `500` ([probe results](../probe-results.md#failed-scripts-and-installs)).
We therefore check, before packaging, the Platform Requirements that install checks, against a catalogue of what each Function Runtime was measured to provide.
`scaleway-doctor --runtime=<name>` runs the check alone, and `scaleway-package --runtime=<name>` runs it before writing the zip.

The check reads the lock as Scaleway's `composer install --no-dev` does: the requirements of the locked `packages` and the root ones the lock records under `platform`, met by the Function Runtime or by a `provide` or `replace`.
Constraints are evaluated with `composer/semver`, Composer's own engine, and the catalogue names and versions PHP and its extensions as Composer's `PlatformRepository` does, down to its fallback versions, so that the outcome predicts what the install on Scaleway will do.

The catalogue holds measurements only, each a complete inventory made by the probe on Scaleway, dated, and generated from probe output committed in the repository.
A weekly workflow measures each Function Runtime again and opens a pull request when something changed; publishing it stays a maintainer's decision.

## Considered Options

- Inferring the Function Runtime from the local PHP: rejected, the local PHP says nothing about what the function declares.
- Comparing with the local PHP environment: rejected, local availability never establishes what Scaleway provides, and the Emulator running on the local PHP is precisely what hides such an incompatibility.
- Catalogue entries derived from Scaleway's documentation: rejected, it lists neither every extension nor their versions, and every supported Function Runtime can be measured.
- Downloading the catalogue at run time: rejected, the check must work offline and without Scaleway credentials, and a release keeps the check reproducible.
- Reimplementing Composer's constraints: rejected, `^`, `~`, `||` and stabilities are easy to get subtly wrong, and `composer/semver` is small and dependency-free.
- Documenting `config.platform.php` instead of checking: rejected, it only states Composer's resolution assumptions, not what Scaleway provides.

## Consequences

- A confirmed incompatibility blocks packaging, as the deployment would serve nothing but `500`s; an unknown only warns, as blocking on missing evidence would refuse working applications.
- Requirements the probe does not measure, `lib-*` and `php-64bit` among them, stay unknown; Composer's own APIs are ignored, Scaleway installing with Composer 2.
- The measured PHP patch version is authoritative: a requirement on a newer patch is refused until the catalogue records the upgrade.
- A measurement describes the Function Runtime on its date, not a guarantee: an extension Scaleway removes after it is caught by `scaleway-check` after the deployment, and by the next weekly measurement.
- `composer/semver` ships with every application, although only the check uses it.
- A successful check covers declared Platform Requirements only, not whether the application runs.
- Installing custom native extensions and a Serverless Containers target remain separate work, which the check points to.
