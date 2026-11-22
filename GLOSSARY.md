# Scaleway Runtime

Symfony Runtime integration that runs PHP applications on Scaleway Serverless Functions.

## Language

**Invocation**:
A single call of the function by Scaleway, carrying one Event and expecting one Function Response.
_Avoid_: Request (reserved for the Symfony HTTP request), call, execution

**Event**:
The array Scaleway passes to the handler, describing an HTTP call or a CRON trigger.
_Avoid_: Payload, input

**Function Response**:
The array returned to Scaleway (status code, headers, body).
_Avoid_: Output, result

**Handler**:
The global `handle($event, $context)` function Scaleway calls for each Invocation.
_Avoid_: Entry point, front controller

**Front Controller**:
The application's unchanged entry script, `public/index.php` by default, returning the closure that builds the Application.
_Avoid_: Handler, bootstrap

**Application**:
The object built by the Front Controller closure (Kernel, Response, PSR-15 handler, Console application, callable).
_Avoid_: App, service

**Warm Process**:
A PHP process that survives between Invocations and keeps the booted Application in memory.
_Avoid_: Worker, long-running process

**HTTP Invocation**:
An Invocation triggered by an HTTP call to the function's endpoint.

**CRON Invocation**:
An Invocation triggered by a Scaleway CRON trigger, carrying the trigger's configured arguments as body.
_Avoid_: Scheduled job, cron task

**Command Invocation**:
A CRON Invocation whose body names a console command to run instead of an HTTP route, and carries the CRON Secret.

**CRON Secret**:
The shared secret, known to the function and to its CRON triggers, that proves a CRON Invocation may run a console command.
_Avoid_: Token, API key

**Emulator**:
The local server that turns real HTTP requests into Events and calls the Handler, to run an application without deploying it.
_Avoid_: Local runtime, dev server

**Static File**:
A file present under the application's web root when an HTTP Invocation asks for it, answered as it is, without building the Application.
_Avoid_: Asset (the Symfony component), public file

**Function Runtime**:
The PHP environment managed by Scaleway that a function declares, named like `php84`, with its PHP version and loaded extensions.
_Avoid_: Runtime (the Symfony component and this package), target, platform

**Function Runtime Catalogue**:
The record, shipped with the package, of the PHP version and extensions measured on each supported Function Runtime, with the date of the measurement.
_Avoid_: Runtime catalogue, inventory

**Platform Requirement**:
A `php` or `ext-*` constraint that the application or one of its production packages declares, as Composer defines it.
_Avoid_: Dependency, system requirement
