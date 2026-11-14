#!/usr/bin/env bash
#
# End-to-end scenarios for the example application.
# Usage: [SCALEWAY_CRON_SECRET=...] example/e2e/run.sh <base-url>
#   against the Emulator:   example/e2e/run.sh http://127.0.0.1:8000
#   against the deployment: example/e2e/run.sh "$(terraform -chdir=example/terraform output -raw endpoint)"
# Requires bash, curl and php (used to read JSON and hash files).

set -euo pipefail

base_url=${1:?Usage: $0 <base-url>}
base_url=${base_url%/}
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
failures=0

expect() {
    if [[ "$2" == "$3" ]]; then
        echo "ok   $1"
    else
        echo "FAIL $1"
        echo "       expected: $3"
        echo "       actual:   $2"
        failures=$((failures + 1))
    fi
}

# request <name> <curl args...>: stores headers and body under $tmp/<name>, prints the status code.
request() {
    local name=$1
    shift
    curl -sS -o "$tmp/$name.body" -D "$tmp/$name.headers" -w '%{http_code}' "$@"
}

# field <name> <dotted.path>: prints a field of the JSON body, arrays and objects as JSON.
field() {
    php -r '
        $value = json_decode(file_get_contents($argv[1]), true);
        foreach ($argv[2] === "" ? [] : explode(".", $argv[2]) as $key) {
            $value = is_array($value) ? $value[$key] ?? null : null;
        }
        echo is_string($value) || is_int($value) ? $value : json_encode($value);
    ' "$tmp/$1.body" "$2"
}

# header <name> <header>: prints every value of a response header, one per line.
header() {
    grep -i "^$2:" "$tmp/$1.headers" | cut -d: -f2- | sed 's/^ *//' | tr -d '\r' || true
}

sha256() {
    php -r 'echo hash_file("sha256", $argv[1]);' "$1"
}

echo "Running against $base_url"

status=$(request hello "$base_url/hello?name=Scaleway")
expect "GET /hello status" "$status" 200
expect "GET /hello JSON" "$(field hello message)" "Hello Scaleway!"
expect "GET /hello content type" "$(header hello content-type)" "application/json"

status=$(request query "$base_url/echo?foo=bar&n=42&list%5Bkey%5D=v")
expect "GET /echo status" "$status" 200
expect "GET /echo method" "$(field query method)" GET
expect "GET /echo path" "$(field query path)" /echo
expect "GET /echo query" "$(field query query)" '{"foo":"bar","n":"42","list":{"key":"v"}}'

status=$(request headers -H 'X-Custom: hello' -H 'Accept-Language: fr' --cookie 'flavor=chocolate; size=large' "$base_url/echo")
expect "custom request header" "$(field headers headers.x-custom)" hello
expect "cookies" "$(field headers cookies)" '{"flavor":"chocolate","size":"large"}'
expect "client IP is set" "$(field headers clientIp | grep -cE '^[0-9a-f.:]+$')" 1
expect "HTTPS, as the platform terminates TLS" "$(field headers secure)" true

# Clients can forge these, so the Application must not trust them.
status=$(request forwarded -H 'X-Forwarded-For: 203.0.113.66' -H 'X-Forwarded-Host: spoofed.example' -H 'X-Real-Ip: 203.0.113.66' "$base_url/echo")
expect "forged X-Forwarded-Host ignored" "$(field forwarded uri | grep -c spoofed.example)" 0
expect "forged client IP ignored" "$(field forwarded clientIp | grep -c 203.0.113.66)" 0

status=$(request json -X POST -H 'Content-Type: application/json' --data '{"answer":42}' "$base_url/echo")
expect "POST JSON body" "$(field json body)" '{"answer":42}'

status=$(request form --data-urlencode 'title=Hello world' --data-urlencode 'tags[]=a' --data-urlencode 'tags[]=b' "$base_url/echo")
expect "POST form fields" "$(field form form)" '{"title":"Hello world","tags":["a","b"]}'

php -r 'echo random_bytes(4096);' > "$tmp/upload.bin"
status=$(request upload -F title=Report -F "doc=@$tmp/upload.bin;filename=report.bin;type=application/octet-stream" "$base_url/echo")
expect "multipart field" "$(field upload form.title)" Report
expect "multipart file name" "$(field upload files.doc.name)" report.bin
expect "multipart file size" "$(field upload files.doc.size)" 4096
expect "multipart file content" "$(field upload files.doc.sha256)" "$(sha256 "$tmp/upload.bin")"

status=$(request rawbody -X PUT -H 'Content-Type: application/octet-stream' --data-binary "@$tmp/upload.bin" "$base_url/echo")
expect "binary request body" "$(field rawbody bodySha256)" "$(sha256 "$tmp/upload.bin")"
expect "PUT method" "$(field rawbody method)" PUT

status=$(request cookies "$base_url/cookies")
expect "GET /cookies status" "$status" 200
expect "three Set-Cookie headers" "$(header cookies set-cookie | wc -l | tr -d ' ')" 3
expect "encoded cookie value" "$(header cookies set-cookie | grep -c '^second=two%20words;')" 1

status=$(request binary "$base_url/binary")
php -r 'echo implode("", array_map("chr", range(0, 255)));' > "$tmp/expected.bin"
expect "GET /binary status" "$status" 200
expect "binary response body" "$(sha256 "$tmp/binary.body")" "$(sha256 "$tmp/expected.bin")"
expect "binary content type" "$(header binary content-type)" application/octet-stream

status=$(request stream "$base_url/stream")
expect "GET /stream body" "$(cat "$tmp/stream.body")" "$(printf 'chunk %s\n' 1 2 3 4 5)"

request counter1 "$base_url/counter" > /dev/null
request counter2 "$base_url/counter" > /dev/null
if [[ "$(field counter1 pid)" == "$(field counter2 pid)" ]]; then
    expect "Warm Process reuse" "$(field counter2 invocations)" "$(($(field counter1 invocations) + 1))"
else
    echo "note the two /counter Invocations ran in different processes (pids $(field counter1 pid) and $(field counter2 pid))"
fi

status=$(request missing "$base_url/missing")
expect "unknown route" "$status" 404

status=$(request postroot -X POST -H 'Content-Type: application/json' --data '{"job":"cleanup"}' "$base_url/")
expect "POST / JSON body" "$(field postroot args.job)" cleanup

# A body naming a command runs nothing over HTTP: it is a plain HTTP body.
status=$(request forged -X POST -H 'Content-Type: application/json' \
    --data '{"command":"app:greet","args":{"name":"Mallory"},"secret":"guess"}' "$base_url/")
expect "command without the CRON secret stays HTTP" "$status $(field forged args.command)" "200 app:greet"

# command_body <name> <args JSON>: the arguments a CRON trigger running a command carries.
# The secret goes through the environment and stdin, as command lines are visible to other local users.
command_body() {
    php -r 'echo json_encode(["command" => $argv[1], "args" => json_decode($argv[2]), "secret" => getenv("SCALEWAY_CRON_SECRET")]);' "$1" "$2"
}

if [[ -n "${SCALEWAY_CRON_SECRET:-}" ]]; then
    status=$(command_body app:greet '{"name":"Mallory"}' | request leaked -X POST -H 'Content-Type: application/json' --data-binary @- "$base_url/")
    expect "command with the CRON secret stays HTTP over HTTP" "$status $(field leaked args.command)" "200 app:greet"
fi

# Only the Emulator can trigger a CRON Invocation on demand, on its reserved path.
status=$(request cron -X POST --data '{"job":"cleanup"}' "$base_url/_scaleway/cron")
if [[ "$status" == 404 ]]; then
    echo "skip CRON Invocations: only the Emulator triggers them on demand"
else
    expect "CRON Invocation lands on POST /" "$status $(field cron args.job)" "200 cleanup"

    if [[ -n "${SCALEWAY_CRON_SECRET:-}" ]]; then
        status=$(command_body app:greet '{"name":"Scaleway","--yell":true}' | request command -X POST --data-binary @- "$base_url/_scaleway/cron")
        expect "Command Invocation status" "$status" 200
        expect "Command Invocation output" "$(cat "$tmp/command.body")" "HELLO SCALEWAY!"

        status=$(command_body app:greet '{"--fail":true}' | request failed -X POST --data-binary @- "$base_url/_scaleway/cron")
        expect "failed command status" "$status" 500
        expect "failed command output" "$(grep -c 'Failing on purpose.' "$tmp/failed.body")" 1

        request after "$base_url/hello?name=again" > /dev/null
        expect "HTTP Invocation after commands" "$(field after message)" "Hello again!"
    else
        echo "skip Command Invocations: set SCALEWAY_CRON_SECRET to the function's"
    fi
fi

if ((failures > 0)); then
    echo "$failures check(s) failed"
    exit 1
fi
echo "All checks passed"
