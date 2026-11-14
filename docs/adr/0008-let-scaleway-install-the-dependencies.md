# Scaleway installs the dependencies, and the cache is built on the platform

The probe showed that when the zip has a `composer.json` at its root, Scaleway runs `composer install --no-dev` from the shipped `composer.lock` at deploy time, Composer scripts included, and replaces any shipped `vendor/`; the code directory is writable at runtime.
Shipping `vendor/` and a warmed cache (ADR 0003) is therefore wasted work, and a `path` repository the build resolved locally breaks the install on the platform.
We therefore zip the sources with `composer.json` and `composer.lock` only, without `vendor/` nor `var/`, and let the Kernel build its cache at the first Invocation of each Warm Process, as Symfony does on any writable server.

## Considered Options

- Shipping `vendor/` in a subdirectory, where Scaleway does not run Composer: rejected, it moves the Kernel's project dir away from `handler.php` and fights the documented path.
- Warming the cache in `scaleway-package`: rejected, Scaleway's install replaces the result anyway unless the warmup runs after it, which only a Composer script can do.

## Consequences

- `scaleway-package` no longer needs Composer, but refuses a missing lock, a lock out of sync with `composer.json` (Composer only warns about it), and `path` repositories or dists, as Scaleway cannot resolve them and a failing install does not fail the deployment.
- Function environment variables are not available to Composer scripts at deploy time, and a failing script does not fail the deployment: a `post-install-cmd` running `cache:warmup --env=prod` only helps applications whose warmup needs no secret, and Symfony Flex `auto-scripts` (`cache:clear` in the `.env`'s `dev`) fail silently unless made env-explicit.
- The first Invocation of each Warm Process pays the container compilation when the cache was not warmed at deploy time.
- Every locked package must be downloadable by Scaleway: private packages would need their credentials in the zip (an `auth.json`), which the probe did not check.
