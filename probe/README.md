# Platform probe

A dependency-free PHP function that reports what Scaleway Serverless Functions pass to, and accept from, a PHP handler.
Its answers settle the platform facts the runtime relies on.

It was run on 2026-11-14 on `php85` and `php84`: the results are in [docs/probe-results.md](../docs/probe-results.md), and the runtime follows them.
Rerun it to check a platform change, and update that file.

## Deploy

Requirements: Terraform 1.5+, `curl`, and Scaleway API credentials.

```sh
export SCW_ACCESS_KEY=... SCW_SECRET_KEY=... SCW_DEFAULT_PROJECT_ID=...
terraform -chdir=probe/terraform init
terraform -chdir=probe/terraform apply
export PROBE_URL=$(terraform -chdir=probe/terraform output -raw endpoint)
```

To probe the PHP 8.4 runtime too, run `terraform -chdir=probe/terraform apply -var runtime=php84` afterwards: it replaces the function, and its CRON trigger with it, so export `PROBE_URL` again and repeat the checks in a separate results directory.

## Run the checks

Save every output to a file and send them back.
The probe redacts the values of variables whose name contains SECRET, TOKEN, PASSWORD or KEY, but review the files before sharing them.

```sh
mkdir -p probe-results && cd probe-results

# 1. Event, Context, $_SERVER, argv, environment, ini, extensions, filesystem facts.
curl -sS "$PROBE_URL/some/path?single=1&repeated=a&repeated=b&list[]=x&list[]=y&encoded=caf%C3%A9&plus=a+b" \
  -H 'X-Custom: hello' -H 'Cookie: a=1; b=2' > 01-facts.json

# 2. Warm Process reuse: invocationsInThisProcess and pid across calls a few seconds apart.
for i in 1 2 3; do curl -sS "$PROBE_URL/" | grep -E '"(pid|invocationsInThisProcess|firstInvocationAt)"'; sleep 2; done > 02-warm.txt

# 3. X-Forwarded-* sent by the client: passed through, appended to or overwritten?
curl -sS "$PROBE_URL/" -H 'X-Forwarded-For: 203.0.113.66' -H 'X-Forwarded-Proto: http' \
  -H 'X-Forwarded-Host: spoofed.example' -H 'X-Forwarded-Port: 1234' -H 'Forwarded: for=203.0.113.66' > 03-forwarded.json

# 4. Multi-value response headers: are the list values accepted and sent as repeated headers?
curl -sS -i "$PROBE_URL/?mode=headers" > 04-headers.txt

# 5. Binary responses and the minimal documented response.
curl -sS "$PROBE_URL/?mode=binary" | od -An -tx1 | head -3 > 05-binary.txt
curl -sS -i "$PROBE_URL/?mode=no-headers" > 05-no-headers.txt
curl -sS -i "$PROBE_URL/?mode=status&code=418" > 05-status.txt

# 6. Request bodies: text, binary, form, multipart, and the size limit (6 MiB documented).
curl -sS "$PROBE_URL/?mode=body" -H 'Content-Type: application/json' --data '{"a":"é"}' > 06-body-text.json
head -c 4096 /dev/urandom > random.bin
curl -sS "$PROBE_URL/?mode=body" -H 'Content-Type: application/octet-stream' --data-binary @random.bin > 06-body-binary.json
openssl dgst -sha256 random.bin > 06-body-binary.sha256
curl -sS "$PROBE_URL/?mode=facts" -F field=value -F file=@random.bin > 06-body-multipart.json
for size in 5 6 7; do
  head -c $((size * 1024 * 1024)) /dev/zero | tr '\0' a > body-$size.txt
  curl -sS -o 06-body-${size}MiB.txt -w "${size} MiB: HTTP %{http_code}\n" "$PROBE_URL/?mode=body" --data-binary @body-$size.txt
done > 06-body-sizes.txt

# 7. Output and failures: where does stdout go, what does an exception produce?
curl -sS -i "$PROBE_URL/?mode=stdout" > 07-stdout.txt
curl -sS -i "$PROBE_URL/?mode=exception" > 07-exception.txt
# A multi-line exception and a long line (bytes, 65536 by default, at most 1 MiB) written to stderr: how are they split in the logs?
curl -sS -i "$PROBE_URL/?mode=stderr&bytes=65536" > 07-stderr.txt

# 8. Other methods and HEAD.
curl -sS -X PUT "$PROBE_URL/" --data 'put body' > 08-put.json
curl -sS -I "$PROBE_URL/" > 08-head.txt

# 9. CRON: wait for a run (every 5 minutes), then read the Events this instance recorded.
#    If the CRON ran on another instance, the history misses it: use the logs (step 10) instead.
sleep 330; curl -sS "$PROBE_URL/?mode=history" > 09-history.ndjson
```

To update the [Function Runtime Catalogue](../docs/probe-results.md#function-runtime-catalogue), save the PHP version and the extensions of each Function Runtime, dated, in the fixtures it is generated from:

```sh
curl -sS "$PROBE_URL/?mode=runtime" > ../tests/Fixtures/FunctionRuntimes/php85.json
```

10. Export the function's logs of the last hour, CRON lines included, from Cockpit's Grafana or with the Loki query of the [README](../README.md#logs).

11. Compare a CRON Event (step 9 or 10) with the HTTP one of `01-facts.json`, `event` and `context` alike, and list what only the CRON one has or lacks: headers (a trigger name or id, a token, `User-Agent`, `X-Forwarded-For`), method, path, context fields.
    Then send each such header from a plain HTTP call, and check whether it reaches the Event as sent, is overwritten, or is removed: only a marker the platform sets itself can tell CRON Invocations from HTTP ones.

```sh
# Replace the example header with the CRON-only headers found, one -H per header.
curl -sS "$PROBE_URL/" -H 'X-Example-Cron-Header: forged' > 11-forged-cron-marker.json
```

12. Static Files: the largest Function Response, and what the platform does with response encodings.

```sh
# Text and base64 bodies of 5 to 12 MiB (decoded size): which sizes reach the client whole?
for size in 5 6 7 8 10 12; do
  for encoding in text base64; do
    curl -sS -o /dev/null -w "${size} MiB ${encoding}: HTTP %{http_code}, %{size_download} bytes\n" \
      "$PROBE_URL/?mode=size&encoding=${encoding}&bytes=$((size * 1024 * 1024))"
  done
done > 12-response-sizes.txt
# Does the platform compress responses itself? Look for Content-Encoding in the headers.
curl -sS -o /dev/null -D 12-platform-compression.txt -H 'Accept-Encoding: gzip, br' "$PROBE_URL/?mode=size&bytes=102400"
# Does a Content-Encoding set by the function reach the client, with a body that decodes to X-Probe-Sha256?
for encoding in gzip zstd br; do
  curl -sS -D 12-encoded-$encoding.headers -o 12-encoded-$encoding.body -H "Accept-Encoding: $encoding" "$PROBE_URL/?mode=encoded&encoding=$encoding"
done
php -r 'echo hash("sha256", gzdecode(file_get_contents("12-encoded-gzip.body"))), PHP_EOL;' > 12-encoded-gzip.sha256
```

## Report back

- All files of `probe-results/` (except `random.bin` and `body-*.txt`) and the exported logs.
- The runtime(s) probed (`php85`, `php84`), and anything surprising during `terraform apply` (for example whether Scaleway ran Composer over the shipped `vendor/`).

The answers the files settle:

| Question | Where to look |
| --- | --- |
| HTTP Event shape (keys, path encoding, repeated query keys, header name case) | `01-facts.json` `event` |
| CRON Event shape (method, path, body, headers) | `09-history.ndjson`, logs |
| CRON marker: what only CRON Events carry, and whether HTTP clients can forge it ([ADR 0005](../docs/adr/0005-authenticate-command-invocations-with-a-shared-secret.md)) | `09-history.ndjson`, logs, `11-forged-cron-marker.json` |
| Context type and content | `01-facts.json` `contextType`, `context` |
| Warm Process reuse | `02-warm.txt` |
| X-Forwarded-* spoofing | `03-forwarded.json` `event.headers` |
| Multi-value response headers | `04-headers.txt` |
| Default Content-Type, binary responses | `05-*.txt` |
| Body encoding (base64 or not), size limit | `06-*` |
| `$_SERVER`, `argv`, `SCRIPT_FILENAME`, superglobals | `01-facts.json` `globals` |
| Read-only code dir, writable `/tmp`, free space | `01-facts.json` `filesystem` |
| Shipped `vendor/` kept, Composer run at deploy (scripts, `--no-dev`) | `01-facts.json` `vendorMarkerKept`, `composerLockPresent`, `composerRun`, `vendorListing` |
| stdout, uncaught exceptions | `07-*.txt`, logs |
| Largest Function Response, platform compression, `Content-Encoding` pass-through, `brotli` extension | `12-*`, `01-facts.json` `extensions` |

## Clean up

The probe is public and echoes request headers, destroy it once done:

```sh
terraform -chdir=probe/terraform destroy
```
