# The deployment zip ships vendor/ and a warmed-up cache

Scaleway can install Composer dependencies itself at deploy time, which is its documented path.
We instead build the zip with `composer install --no-dev -o` and a prod `cache:warmup`, so builds are reproducible and Invocations never pay container compilation on a possibly read-only filesystem.
