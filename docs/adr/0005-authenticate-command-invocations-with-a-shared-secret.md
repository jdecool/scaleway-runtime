# Command Invocations need both a CRON-shaped Event and a shared secret carried in the CRON arguments

A CRON Invocation reaches the function as an Event whose body holds the trigger's arguments: a public function would otherwise run any console command an internet client POSTs as `{"command": ...}`.
We therefore only run a Command Invocation when its body also carries a `secret` equal, in constant time, to the `SCALEWAY_CRON_SECRET` environment variable (a secret one on Scaleway); otherwise, and always when that variable is unset or empty, the body goes to the Application as a plain HTTP body, so Command Invocations are off by default.
The probe then found that CRON Events carry neither `X-Real-Ip` nor `X-Envoy-External-Address`, which the platform sets on every HTTP call, overwriting client values: an Event carrying either is never a Command Invocation, even with the right secret.
Both checks live in `CommandInvocation::fromEvent()`, as defense in depth: the marker is undocumented platform behaviour that may change, and the secret alone would run commands for anyone who reads it.

## Considered Options

- Trusting a body naming a command, or a CRON-looking Event shape (method, path): rejected, any HTTP client can send the same.
- The marker alone: rejected, it is undocumented, so a platform change that stops setting those headers would open every public function.
- Private functions, authenticated by Scaleway tokens: rejected as the default, as the Application could no longer serve public HTTP Invocations.
- An allowlist of commands: rejected, as the CRON arguments that name the commands already live next to the secret, in the Scaleway project and its Terraform state, so whoever can read the secret can usually redeploy the function too.

## Consequences

- The secret is stored in clear in the CRON arguments, visible to anyone who can read the function's triggers or the Terraform state, and it is the same for every run: rotating it means updating the variable and every CRON trigger together.
- A body carrying a `secret` that is rejected, for a wrong value or because the Event comes from an HTTP call, is logged without its value, so a misconfigured trigger shows up in the logs instead of silently landing on `POST /`.
- Command Invocations cannot be sent over HTTP, so the Emulator simulates CRON triggers on a reserved path.
- A Front Controller returning a Console Application or a Command serves Command Invocations only, and answers every other Invocation with a 403.
