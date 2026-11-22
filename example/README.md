# Example application

A minimal Symfony application running on Scaleway Serverless Functions through `jdecool/scaleway-runtime`, which it requires from Packagist.

| Route | What it shows |
| --- | --- |
| `GET /hello?name=...` | A JSON response |
| `ANY /echo` | The request as the application sees it: method, query, headers, client IP, cookies, body, form fields, uploaded files |
| `GET /cookies` | Several `Set-Cookie` headers |
| `GET /binary` | A binary response (bytes 0 to 255) |
| `GET /stream` | A streamed response, buffered into one Function Response |
| `GET /counter` | A per-process invocation counter: it keeps growing while a Warm Process serves the Invocations |
| `GET /urls` | The absolute URLs the application builds: from the router, `getUri()` and `getSchemeAndHttpHost()` |
| `GET /redirect` | A redirect to an absolute route URL |
| `POST /` | Where CRON Invocations land, with the trigger's arguments as JSON body |

The files of `public/` are [Static Files](../README.md#static-files), served by the runtime without building the Application:

| File | What it shows |
| --- | --- |
| `/css/app.css` | A stylesheet, revalidated with its `ETag`; with `Accept-Encoding: gzip`, its precompressed `app.css.gz` |
| `/js/greeting.js` | A JavaScript module, as `text/javascript` |
| `/images/logo.png` | A binary file, base64-encoded in the Function Response |
| `/assets/app-Ex4mPl3.css` | A digested file as AssetMapper writes one, listed in `assets/manifest.json`, so cached for good |

Its `post-install-cmd` warms the prod cache when Scaleway installs the dependencies at deploy time, as the warmup needs no secret; without it, the first Invocation of each Warm Process builds the cache.

It logs from the `info` level to stderr, which reaches Cockpit, through Symfony's fallback logger lowered in `config/services.yaml`, without Monolog.

The `app:greet` console command (`src/Command/GreetCommand.php`) runs as a Command Invocation: a `POST /` whose JSON body is `{"command": "app:greet", "args": {"name": "...", "--yell": true}, "secret": "..."}`, the secret matching the function's `SCALEWAY_CRON_SECRET`.
Its `--fail` option shows how a failed command answers: a 500 with the command output.
Over HTTP, even with the secret, the same body is a plain HTTP body and lands on `POST /`.
`bin/console` works as a Front Controller too, for a function that only runs commands: `APP_RUNTIME_OPTIONS='{"front_controller":"bin/console"}'`.

`public/hello.php` is a second, function-like Front Controller: a closure taking the `Request` and returning a `Response`, called for every Invocation without any Kernel.
Select it with the `front_controller` runtime option, for instance `APP_RUNTIME_OPTIONS='{"front_controller":"public/hello.php"}' vendor/bin/scaleway-serve`, then `curl 'http://127.0.0.1:8000/?name=Scaleway'`.

A Front Controller may also return a PSR-15 `RequestHandlerInterface`, built once and reused like the Kernel, once the application requires `symfony/psr-http-message-bridge` and `nyholm/psr7`.
Its PSR-7 request sees the client, scheme and host the runtime resolved, in `getUri()` as in the `REMOTE_ADDR`, `HTTPS` and `HTTP_HOST` server params.

A Front Controller whose closure returns another `Closure` talks to Scaleway without any translation: that raw callable, built once and reused like the Kernel, receives every Event and context as they are, CRON Invocations included, and returns the Function Response itself.

```php
return static fn (): Closure => static fn (array $event, mixed $context): array => [
    'statusCode' => 200,
    'headers' => ['Content-Type' => 'text/plain'],
    'body' => 'Hello from '.($event['path'] ?? '/'),
];
```

## Run it locally

The example requires the runtime from Packagist; `composer link-runtime` installs the runtime of this checkout instead, from the parent directory, through a `path` repository it adds to `composer.json`.
Never commit that repository nor the lock it writes: `scaleway-package` refuses them, as Scaleway cannot resolve them.

```sh
cd example
composer install                          # or composer link-runtime, for the runtime of this checkout
export SCALEWAY_CRON_SECRET=local-secret  # lets the Emulator and the scenarios run Command Invocations
vendor/bin/scaleway-serve                 # the Emulator, on http://127.0.0.1:8000
e2e/run.sh http://127.0.0.1:8000          # in another terminal, with the same SCALEWAY_CRON_SECRET
```

A CRON trigger is simulated with a `POST` on the Emulator's `/_scaleway/cron`, whose body is the trigger's arguments:

```sh
curl -X POST http://127.0.0.1:8000/_scaleway/cron --data '{"command": "app:greet", "args": {"name": "CRON"}, "secret": "local-secret"}'
```

To run what Scaleway deploys, `e2e/deploy-locally.sh` packages a copy of the application, installs the zip with `composer install --no-dev` and without the function's variables, as Scaleway does, and serves it in prod, all in a temporary directory:

```sh
APP_SECRET=local SCALEWAY_CRON_SECRET=local-secret e2e/deploy-locally.sh 127.0.0.1:8001
e2e/run.sh http://127.0.0.1:8001
```

It publishes the runtime of this checkout to that install in place of Packagist, through a Composer repository configured outside `composer.json`.

## Deploy it

Set `host` to the function's domain (or a custom domain) for absolute URLs to be right; it cannot default to the function's own `domain_name`, so apply once, then again with `-var host=...`.

```sh
cd example
vendor/bin/scaleway-package
export SCW_ACCESS_KEY=... SCW_SECRET_KEY=... SCW_DEFAULT_PROJECT_ID=...
terraform -chdir=terraform init
export TF_VAR_app_secret=$(openssl rand -hex 16) SCALEWAY_CRON_SECRET=$(openssl rand -hex 32)
export TF_VAR_cron_secret=$SCALEWAY_CRON_SECRET
terraform -chdir=terraform apply
endpoint=$(terraform -chdir=terraform output -raw endpoint)
terraform -chdir=terraform apply -var host="${endpoint#https://}"
SCALEWAY_HOST=${endpoint#https://} e2e/run.sh "$endpoint"
terraform -chdir=terraform destroy
```

Rerun `scaleway-package` and `terraform apply` (with the same `host`) to deploy a new version.
`e2e/run.sh` checks that absolute URLs carry `SCALEWAY_HOST`, `localhost` when it is unset, as for a deployment without `host`.
Against a deployment, `e2e/run.sh` skips the CRON scenarios, as only the platform triggers CRON Invocations.
Check them in the [logs](../README.md#logs) instead, after the triggers ran (at minutes 0 and 30): each one leaves its line, the second showing that `app:greet` ran as a Command Invocation.

```
DEBUG - [info] CRON Invocation received with the arguments ["job"] source=user stream=stderr
DEBUG - [info] Command app:greet said: Hello CRON! source=user stream=stderr
```
