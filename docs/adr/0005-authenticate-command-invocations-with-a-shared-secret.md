# Command Invocations are authenticated by a shared secret carried in the CRON arguments

A CRON Invocation reaches the function as an Event whose body holds the trigger's arguments, and nothing documented tells it apart from an HTTP Invocation: a public function would otherwise run any console command an internet client POSTs as `{"command": ...}`.
We therefore only run a Command Invocation when its body also carries a `secret` equal, in constant time, to the `SCALEWAY_CRON_SECRET` environment variable (a secret one on Scaleway); otherwise, and always when that variable is unset or empty, the body goes to the Application as a plain HTTP body, so Command Invocations are off by default.
The check lives in `CommandInvocation::fromEvent()` alone, to be replaced or complemented once the probe finds a platform marker that HTTP clients cannot forge.

## Considered Options

- Trusting a body naming a command, or a CRON-looking Event shape (method, path, missing headers): rejected, any HTTP client can send the same.
- A platform marker (header, context field): preferred once known, but the CRON Event shape is still unmeasured, and a header is only a marker if the platform strips it from HTTP calls.
- Private functions, authenticated by Scaleway tokens: rejected as the default, as the Application could no longer serve public HTTP Invocations, and whether CRON Invocations of a private function carry anything verifiable is unknown.
- An allowlist of commands: rejected, as the CRON arguments that name the commands already live next to the secret, in the Scaleway project and its Terraform state, so whoever can read the secret can usually redeploy the function too.

## Consequences

- The secret is stored in clear in the CRON arguments, visible to anyone who can read the function's triggers or the Terraform state, and it is the same for every run: rotating it means updating the variable and every CRON trigger together.
- A body carrying a wrong `secret` is logged, without its value, so a misconfigured trigger shows up in the logs instead of silently landing on `POST /`.
- A Front Controller returning a Console Application or a Command serves Command Invocations only, and answers every other Invocation with a 403.
