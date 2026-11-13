# The Handler drives the Runtime lifecycle instead of autoload_runtime.php

Scaleway owns the PHP process and calls a global `handle($event, $context)` for each Invocation, while the generated `vendor/autoload_runtime.php` re-includes `$_SERVER['SCRIPT_FILENAME']` and ends with `exit()`.
We therefore ship a Handler that instantiates our `RuntimeInterface` itself, resolves the unchanged Front Controller through its resolver, and delegates each Invocation to a runner, keeping the `RuntimeInterface` contract and the `extra.runtime` options.

`RunnerInterface::run(): int` has no room for an Event nor a Function Response, so our runners implement an own `InvocationRunner::invoke($event, $context): array` instead.
`extra.runtime.class` and `APP_RUNTIME` are ignored: the Handler always uses `ScalewayRuntime`, as those name the runtime the same app uses elsewhere (FPM, FrankenPHP).

## Considered Options

- Custom `autoload_template` without `exit`: rejected, still coupled to `SCRIPT_FILENAME`, which Scaleway controls.
- Booting the Kernel by hand without the Runtime component: rejected, loses Front Controller portability and runtime options.
- A `RunnerInterface` built per Invocation around the Event: rejected, `getRunner()` only receives the Application, so the Event would travel through hidden mutable state on the runtime.
