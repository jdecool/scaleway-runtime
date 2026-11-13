# A Front Controller returning a Response is called for every Invocation

SymfonyRuntime calls the Front Controller closure once per process: under FPM that is once per request, but its FrankenPHP worker mode serves the Response built at startup to every request, and resolves a `Request` argument from the globals of the worker's startup.
In a Warm Process that would freeze the Response (a `StreamedResponse` would even be empty from its second sending on) and make a `Request` argument meaningless.
We instead call the closure again for every Invocation, a `Request` argument receiving the Request built from the Invocation's Event, which keeps the FPM semantics and makes `fn (Request $request): Response` a function-like Front Controller.

## Consequences

- The Application type is only known once the closure has been called, and no Request exists before the first Invocation: a closure taking a `Request` is therefore called per Invocation without a call at startup, and must return a Response every time.
- A closure taking no `Request` is called at startup, which in practice runs inside the first Invocation; when it returns a Response, that Response answers the first Invocation and the closure is called again for the next ones.
- The closure must be cheap: it runs on every Invocation, unlike a Kernel which is built once (ADR 0002).
