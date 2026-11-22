# Supported Applications

The Front Controller returns a closure, as with any Symfony Runtime.
The type of what that closure returns, the Application, decides how Invocations are served.

## HttpKernel

The usual Symfony Front Controller:

```php
<?php // public/index.php

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
```

The Kernel is booted once per Warm Process and serves every Invocation, see [Warm Processes](warm-processes.md).
Any `HttpKernelInterface` works; a `KernelInterface` also runs [Command Invocations](cron-and-commands.md#command-invocations).

## Response

A closure returning a `Response` is called again for every Invocation, without any Kernel.
A `Request` argument receives the Invocation's Request:

```php
<?php // public/hello.php

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (Request $request): JsonResponse {
    return new JsonResponse(['message' => sprintf('Hello %s!', $request->query->get('name', 'World'))]);
};
```

Keep the closure cheap, as it runs on every Invocation, and make it return a `Response` every time.
See [ADR 0004](adr/0004-call-response-front-controllers-per-invocation.md).

## PSR-15 request handler

A closure returning a `Psr\Http\Server\RequestHandlerInterface` builds it once per Warm Process.
It needs two more packages:

```sh
composer require symfony/psr-http-message-bridge nyholm/psr7
```

```php
<?php // public/index.php

use App\ApiHandler;
use Psr\Http\Server\RequestHandlerInterface;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static fn (): RequestHandlerInterface => new ApiHandler();
```

The PSR-7 request carries the client address, scheme, host and port the runtime resolved (see [Client IP and trusted proxies](requests-and-responses.md#client-ip-and-trusted-proxies)), in its URI and in the `REMOTE_ADDR`, `HTTPS`, `HTTP_HOST` and `SERVER_PORT` server params.
A handler implementing `Symfony\Contracts\Service\ResetInterface` is reset before every Invocation but the first.
A malformed host is answered with a 400 before reaching the handler.

## Console Application or Command

A Front Controller returning a Console `Application` or a `Command` only serves [Command Invocations](cron-and-commands.md#command-invocations), and answers every other Invocation with a 403.
Symfony's `bin/console` is such a Front Controller:

```sh
APP_RUNTIME_OPTIONS='{"front_controller":"bin/console"}'
```

A single `Command` runs whatever `command` the Command Invocation names:

```php
<?php // bin/cleanup.php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static fn (): Command => (new Command('app:cleanup'))
    ->setCode(static function (InputInterface $input, OutputInterface $output): int {
        $output->writeln('Cleaned up');

        return Command::SUCCESS;
    });
```

This needs `symfony/console`, and `symfony/framework-bundle` for a `Symfony\Bundle\FrameworkBundle\Console\Application`.

## Raw callable

A closure returning another `Closure` talks to Scaleway without any translation.
The inner closure is built once per Warm Process and receives every Event and context as they are, CRON Invocations included:

```php
<?php // public/raw.php

return static fn (): Closure => static fn (array $event, mixed $context): array => [
    'statusCode' => 200,
    'headers' => ['Content-Type' => 'text/plain'],
    'body' => 'Hello from '.($event['path'] ?? '/'),
];
```

It must return a Function Response: an int `statusCode`, optional `headers` mapping names to a string or a list of strings, a string `body` and an optional bool `isBase64Encoded`.
Other keys are dropped, and any other return value fails the Invocation with a 500.
Command Invocations are not routed: the raw callable gets their Event like any other.
An invokable object is not taken for a raw callable; return `$object(...)` to make it one.
