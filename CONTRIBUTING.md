# Contributing

```sh
composer install
composer check   # PHP-CS-Fixer (dry run), PHPStan (max level) and PHPUnit
composer cs-fix  # applies the coding style
```

CI runs `composer validate --strict` and `composer check` on PHP 8.4 and 8.5, each against Symfony 7.4 and 8.x, the whole Symfony stack being pinned through Flex's `SYMFONY_REQUIRE`.
It also runs the [end-to-end scenarios](example/e2e/run.sh) of the [example application](example) through the Emulator, in dev and on its zip installed like Scaleway does ([e2e/deploy-locally.sh](example/e2e/deploy-locally.sh)).
The example installs the runtime of the checkout with `composer link-runtime`, see its [README](example/README.md#run-it-locally).
A weekly workflow measures the Function Runtimes on Scaleway again, and opens a pull request when the catalogue changes, see [docs/probe-results.md](docs/probe-results.md#function-runtime-catalogue).

The repository holds:

- `src/`, `bin/`, `resources/`: the package;
- `tests/`: PHPUnit tests, packaging included;
- `example/`: a demo Symfony application requiring the package, with Terraform code to deploy it;
- `probe/`: a dependency-free function measuring the platform facts, its [results](docs/probe-results.md), and the generator of the [Function Runtime Catalogue](docs/probe-results.md#function-runtime-catalogue);
- `docs/`: the [user documentation](docs/README.md) and the [ADRs](docs/adr/README.md).
