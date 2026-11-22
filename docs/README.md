# Documentation

What each document in this folder is for.
Requirements and installation live in the root [README](../README.md), the vocabulary in [GLOSSARY.md](../GLOSSARY.md), and the contribution workflow in [CONTRIBUTING.md](../CONTRIBUTING.md).

## Using the runtime

- [Configuration](configuration.md) - the function handler, choosing the Front Controller, the environment variables to set, the host of absolute URLs, the cache and writable directories, and how errors are answered.
- [Supported Applications](applications.md) - what a Front Controller may return: an HttpKernel, a Response, a PSR-15 request handler, a Console Application or Command, or a raw callable.
- [Static Files](static-files.md) - which files of `public/` the runtime serves itself, their caching headers, compression and size limits.
- [CRON triggers and Command Invocations](cron-and-commands.md) - handling a CRON trigger as a route, running a console command from one, and the security model behind it.
- [Warm Processes](warm-processes.md) - what survives between Invocations, and what an application must do to be safe for long-running execution.
- [Requests and responses](requests-and-responses.md) - the platform caveats on superglobals, output, query strings, bodies and streaming, and how the client IP is resolved without trusting any proxy.

## Shipping

- [Compatibility check](compatibility-check.md) - checking the Platform Requirements of the lock against a Function Runtime with `scaleway-doctor`, before deploying.
- [Packaging](packaging.md) - building the deployment zip with `scaleway-package`, what it ships and what it refuses.
- [Deployment](deployment.md) - deploying with Terraform, redeploying, and what Scaleway's `composer install` does at deploy time.
- [Logs](logs.md) - where the function's output shows up in Cockpit, and querying it from the command line.

## Developing

- [Local development with the Emulator](local-development.md) - running the function locally with `scaleway-serve`, simulating CRON and Command Invocations, serving the zip as deployed, and Windows support.

## Reference

- [Probe results](probe-results.md) - the platform facts measured on Scaleway, and the Function Runtime Catalogue.

## Decisions

- [Architecture decision records](adr/README.md) - why the runtime is shaped the way it is, one file per decision, indexed by number.
