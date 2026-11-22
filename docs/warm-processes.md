# Warm Processes

The Application is built once per Warm Process and reused by the following Invocations, like with FrankenPHP's worker mode or RoadRunner ([ADR 0002](adr/0002-reuse-application-across-invocations.md)).
Scaleway keeps processes warm: consecutive Invocations of an instance run in the same PHP process.

Between Invocations, for a Kernel:

- `terminate()` runs after each Invocation, and a failure in it is logged without changing the Function Response;
- the `services_resetter` resets the services tagged `kernel.reset` before the next request, as Symfony's Kernel does;
- uploaded files are deleted, and output buffers the Application left open are closed.

Everything else survives, so your application must be safe for long-running execution:

- do not keep per-request state in static properties or in services that do not implement `ResetInterface`;
- in-memory caches and collections grow across Invocations unless reset;
- connections (database, HTTP clients) may have been closed by the server while the process was idle;
- `exit()` or a fatal error ends the process, and the next Invocation pays a cold start.
