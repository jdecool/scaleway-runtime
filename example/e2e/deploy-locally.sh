#!/usr/bin/env bash
#
# Serves the example as Scaleway deploys it, with the jdecool/scaleway-runtime of this checkout:
# packages a copy of the application, installs the zip with "composer install --no-dev" without the
# function's variables, as Scaleway does at deploy time, then serves it in prod through the Emulator.
# Usage: [APP_SECRET=...] [SCALEWAY_CRON_SECRET=...] example/e2e/deploy-locally.sh [<host>:<port>]
# Requires bash, composer, php with the zip extension, tar and unzip. Leaves the working tree untouched.

set -euo pipefail

address=${1:-127.0.0.1:8000}
example=$(cd "$(dirname "$0")/.." && pwd)
runtime=$(dirname "$example")
tmp=$(mktemp -d)
trap 'chmod -R u+w "$tmp"; rm -rf "$tmp"' EXIT

echo "> Publishing the runtime of $runtime as dev-main"
# Stands in for Packagist: a repository outside composer.json, so the zip ships the files users ship.
composer archive --working-dir="$runtime" --format=zip --dir="$tmp" --file=runtime --quiet
COMPOSER_CACHE_DIR=${COMPOSER_CACHE_DIR:-$(composer config --global cache-dir)}
export COMPOSER_CACHE_DIR COMPOSER_HOME="$tmp/composer-home"
composer config --global repositories.runtime "$(php -r '
    $package = json_decode(file_get_contents($argv[1]), true);
    $package["version"] = "dev-main";
    $package["dist"] = ["type" => "zip", "url" => $argv[2]];
    echo json_encode(["type" => "package", "package" => $package], JSON_UNESCAPED_SLASHES);
' "$runtime/composer.json" "$tmp/runtime.zip")"

echo "> Packaging a copy of $example"
mkdir "$tmp/app"
tar -C "$example" --exclude=./vendor --exclude=./var --exclude=./function.zip -cf - . | tar -C "$tmp/app" -xf -
# The repository "composer link-runtime" adds, if any, which scaleway-package would rightly refuse.
composer config --working-dir="$tmp/app" --unset repositories.local
composer update jdecool/scaleway-runtime --working-dir="$tmp/app" --no-interaction --no-progress --quiet
(cd "$tmp/app" && php vendor/bin/scaleway-package "$tmp/function.zip")

echo "> Installing the zip like Scaleway: composer install --no-dev, without the function's variables"
unzip -q "$tmp/function.zip" -d "$tmp/function"
env -u APP_ENV -u APP_DEBUG -u APP_SECRET -u SCALEWAY_CRON_SECRET -u SCALEWAY_HOST \
    composer install --working-dir="$tmp/function" --no-dev --no-interaction --no-progress
if [[ -d "$tmp/function/var/cache/prod" ]]; then
    echo "> The post-install-cmd warmed the prod cache"
else
    echo "> No prod cache warmed at deploy time: the first Invocation builds it"
fi

APP_ENV=prod php "$tmp/function/vendor/bin/scaleway-serve" "$address"
