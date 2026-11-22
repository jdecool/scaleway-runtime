# Logs

Scaleway sends what the function writes to stdout and stderr to [Cockpit](https://www.scaleway.com/en/docs/cockpit/), in the project's `Scaleway Logs` data source, about 2 seconds after it is written.
The runtime logs its own failures to stderr, as Monolog does with a `php://stderr` handler.
Without Monolog, Symfony's fallback logger writes to stderr too, but from the `error` level only: lower it as the [example](../example/config/services.yaml) does.
Each line is one log entry: a stack trace spans one entry per line, and a line over 16 KiB is split into 16 KiB entries and cut after 64 KiB.
Every entry has the `debug` level, stderr ones included, except the continuations of a split line, whose level is `unknown`: filter on the `stream` field instead.
An entry looks like this, with the `resource_id` label holding the function's UUID:

```json
{"message":"DEBUG - Invocation failed: RuntimeException: ... in /home/app/function/src/Kernel.php:12 source=user stream=stderr","stream":"stderr","resource_instance":"<namespace>-<function>-00001-deployment-<id>"}
```

In the console, open Cockpit's Grafana (`scw cockpit grafana get` prints its URL) and the `Serverless Functions Logs` dashboard, or Explore with the `Scaleway Logs` data source.

From the command line, query Loki's HTTP API with a token that can only read logs:

```sh
LOKI_URL=$(scw cockpit data-source list origin=scaleway types.0=logs -o json | jq -r '.[0].url')
FUNCTION_ID=$(scw function function list name=<function name> -o json | jq -r '.[0].id')
# The secret key is only shown at creation: keep it in memory rather than in a file that could get committed.
TOKEN=$(scw cockpit token create name=read-logs token-scopes.0=read_only_logs -o json)
LOKI_TOKEN=$(jq -r .secret_key <<< "$TOKEN")

curl -sS -G "$LOKI_URL/loki/api/v1/query_range" -H "Authorization: Bearer $LOKI_TOKEN" \
  --data-urlencode "query={resource_id=\"$FUNCTION_ID\"} | json | stream=\"stderr\" | line_format \"{{.message}}\"" \
  --data-urlencode since=1h --data-urlencode limit=1000 \
  | jq -r '[.data.result[].values[]] | sort_by(.[0]) | .[][1]'

scw cockpit token delete token-id=$(jq -r .id <<< "$TOKEN")
```

Token names are unique within a project: if a `read-logs` token was left behind, `token create` fails with `resource already exists`, so delete it first.
Without the `stream="stderr"` filter, the query also returns stdout and the platform's own lines (`Function Triggered: /`, `Function is now ready to serve requests!`).
