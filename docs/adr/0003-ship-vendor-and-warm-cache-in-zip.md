# The deployment zip ships vendor/ and a warmed-up cache

Scaleway can install Composer dependencies itself at deploy time, which is its documented path.
We instead build the zip with `composer install --no-dev -o` and a prod `cache:warmup`, so builds are reproducible and Invocations never pay container compilation on a possibly read-only filesystem.

## Consequences

- `scaleway-package` requires a committed `composer.lock` and installs with `--classmap-authoritative`, as Symfony recommends for production: the warmed container is loaded by path, not through the autoloader, so nothing generated at runtime depends on autoload fallbacks.
- `.env` files are shipped as they are, without `composer dump-env prod`: that command comes from Symfony Flex, which applications may not have, and `.env` is parsed once per Warm Process anyway; `.env.local` and `.env.local.php` stay out of the zip as machine-local configuration.
- The zip also ships `composer.json`, which the Kernel needs to find its project dir; whether Scaleway then runs Composer over the shipped `vendor/` is checked by the probe function.
