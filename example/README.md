# Example application

A minimal Symfony application running on Scaleway Serverless Functions through `jdecool/scaleway-runtime`, which it installs from the parent directory with a Composer `path` repository.

| Route | What it shows |
| --- | --- |
| `GET /hello?name=...` | A JSON response |
| `ANY /echo` | The request as the application sees it: method, query, headers, client IP, cookies, body, form fields, uploaded files |
| `GET /cookies` | Several `Set-Cookie` headers |
| `GET /binary` | A binary response (bytes 0 to 255) |
| `GET /stream` | A streamed response, buffered into one Function Response |
| `GET /counter` | A per-process invocation counter: it keeps growing while a Warm Process serves the Invocations |
| `POST /` | Where CRON Invocations land, with the trigger's arguments as JSON body |

The `app:greet` console command (`src/Command/GreetCommand.php`) runs as a Command Invocation: a `POST /` whose JSON body is `{"command": "app:greet", "args": {"name": "...", "--yell": true}, "secret": "..."}`, the secret matching the function's `SCALEWAY_CRON_SECRET`.
Its `--fail` option shows how a failed command answers: a 500 with the command output.
Without the secret, the same body is a plain HTTP body and lands on `POST /`.
`bin/console` works as a Front Controller too, for a function that only runs commands: `APP_RUNTIME_OPTIONS='{"front_controller":"bin/console"}'`.

`public/hello.php` is a second, function-like Front Controller: a closure taking the `Request` and returning a `Response`, called for every Invocation without any Kernel.
Select it with the `front_controller` runtime option, for instance `APP_RUNTIME_OPTIONS='{"front_controller":"public/hello.php"}' vendor/bin/scaleway-serve`, then `curl 'http://127.0.0.1:8000/?name=Scaleway'`.

A Front Controller may also return a PSR-15 `RequestHandlerInterface`, built once and reused like the Kernel, once the application requires `symfony/psr-http-message-bridge` and `nyholm/psr7`.
Its PSR-7 request sees the client, scheme and host resolved from the platform's `X-Forwarded-*` headers, in `getUri()` as in the `REMOTE_ADDR`, `HTTPS` and `HTTP_HOST` server params.

## Run it locally

```sh
cd example
composer install
export SCALEWAY_CRON_SECRET=local-secret  # lets the Emulator and the scenarios run Command Invocations
vendor/bin/scaleway-serve                 # the Emulator, on http://127.0.0.1:8000
e2e/run.sh http://127.0.0.1:8000          # in another terminal, with the same SCALEWAY_CRON_SECRET
```

To run exactly what gets deployed, serve the packaged zip from a read-only copy:

```sh
vendor/bin/scaleway-package
unzip -q function.zip -d /tmp/function && chmod -R a-w /tmp/function
APP_ENV=prod APP_SECRET=local SCALEWAY_CRON_SECRET=local-secret php /tmp/function/vendor/bin/scaleway-serve
```

## Deploy it

The Terraform code is unvalidated: it was written against the `scaleway/scaleway` provider v2.92 documentation and never applied.
Scaleway documents that it installs the dependencies of a shipped `composer.json` at deploy time.
If it does so over the shipped `vendor/`, deploying this example likely fails: its `composer.lock` installs the runtime from `..`, which is not in the zip.
The [probe](../probe) settles whether and how Composer runs.

```sh
cd example
vendor/bin/scaleway-package
export SCW_ACCESS_KEY=... SCW_SECRET_KEY=... SCW_DEFAULT_PROJECT_ID=...
terraform -chdir=terraform init
export SCALEWAY_CRON_SECRET=$(openssl rand -hex 32)
terraform -chdir=terraform apply -var app_secret="$(openssl rand -hex 16)" -var cron_secret="$SCALEWAY_CRON_SECRET"
e2e/run.sh "$(terraform -chdir=terraform output -raw endpoint)"
terraform -chdir=terraform destroy
```

Rerun `scaleway-package` and `terraform apply` to deploy a new version.
