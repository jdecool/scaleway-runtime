# The Application is booted once per Warm Process and reset between Invocations

Rebuilding the Kernel on every Invocation makes every call pay the cold-start cost.
We boot the Application once per process and call `terminate()` plus the `services_resetter` between Invocations, like FrankenPHP worker mode or RoadRunner; if Scaleway does not keep processes warm, this degrades to a fresh boot per Invocation.

## Consequences

Applications must be safe for long-running execution (no request state kept in services that are not resettable).
