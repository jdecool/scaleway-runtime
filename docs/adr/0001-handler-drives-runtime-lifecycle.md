# The Handler drives the Runtime lifecycle instead of autoload_runtime.php

Scaleway owns the PHP process and calls a global `handle($event, $context)` for each Invocation, while the generated `vendor/autoload_runtime.php` re-includes `$_SERVER['SCRIPT_FILENAME']` and ends with `exit()`.
We therefore ship a Handler that instantiates our `RuntimeInterface` itself, resolves the unchanged Front Controller, and delegates each Invocation to the runner, keeping the `RuntimeInterface`/`RunnerInterface` contract and the `extra.runtime` options.

## Considered Options

- Custom `autoload_template` without `exit`: rejected, still coupled to `SCRIPT_FILENAME`, which Scaleway controls.
- Booting the Kernel by hand without the Runtime component: rejected, loses Front Controller portability and runtime options.
