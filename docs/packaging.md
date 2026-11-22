# Packaging

```sh
vendor/bin/scaleway-package [--runtime=php84] [function.zip]
```

With `--runtime`, it first runs the [compatibility check](compatibility-check.md) against that Function Runtime, and refuses to build the zip on an incompatible Platform Requirement.

It zips your application as it is, `composer.json` and `composer.lock` included, but without `vendor/` nor `var/`: Scaleway runs `composer install --no-dev` from the shipped lock at deploy time, Composer scripts included, and replaces any shipped `vendor/` ([ADR 0008](adr/0008-let-scaleway-install-the-dependencies.md)).
Executable bits are kept, and symbolic links are followed: their target is zipped.

It refuses to build the zip, as Scaleway would install something else or fail without failing the deployment, when:

- `handler.php` or `composer.lock` is missing;
- `composer.lock` is not up to date with `composer.json`;
- `composer.json` declares a `path` repository, or `composer.lock` installs a package from a local path: Scaleway cannot resolve them.

| Shipped | Not shipped |
| --- | --- |
| Your code, `composer.json`, `composer.lock`, `handler.php` | `vendor/` and `var/` (installed and built on the platform) |
| `.env`, `.env.prod`... as they are | `tests/`, `phpunit.xml(.dist)`, `.phpunit.cache`, `.phpunit.result.cache`, `.php-cs-fixer.cache` |
| | `.env.local`, `.env.local.php`, `.env.*.local` |
| | `.git`, `.idea`, `.vscode`, `.DS_Store`, `node_modules` anywhere |
| | `.terraform`, `*.tfstate`, `*.tfstate.*` anywhere (Terraform state holds secrets in clear) |

The full list is `Packager::EXCLUDES` in [src/Packager.php](../src/Packager.php).

Scaleway accepts zips up to 100 MiB, and up to 500 MiB of unzipped code ([limits](https://www.scaleway.com/en/docs/serverless-functions/reference-content/functions-limitations/)).
`scaleway-package` warns when the zip or its content exceeds either, but still writes it.
