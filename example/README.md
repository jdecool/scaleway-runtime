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

## Run it locally

```sh
cd example
composer install
vendor/bin/scaleway-serve            # the Emulator, on http://127.0.0.1:8000
e2e/run.sh http://127.0.0.1:8000     # in another terminal
```

To run exactly what gets deployed, serve the packaged zip from a read-only copy:

```sh
vendor/bin/scaleway-package
unzip -q function.zip -d /tmp/function && chmod -R a-w /tmp/function
APP_ENV=prod APP_SECRET=local php /tmp/function/vendor/bin/scaleway-serve
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
terraform -chdir=terraform apply -var app_secret="$(openssl rand -hex 16)"
e2e/run.sh "$(terraform -chdir=terraform output -raw endpoint)"
terraform -chdir=terraform destroy
```

Rerun `scaleway-package` and `terraform apply` to deploy a new version.
