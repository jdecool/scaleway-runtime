# Requests and responses

- **Superglobals**: `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES` and the request entries of `$_SERVER` (`REQUEST_URI`, `HTTP_*`...) are not populated per Invocation.
  Read the `Request` object instead; `Request::createFromGlobals()` does not describe the Invocation.
- **Output**: only the returned `Response` reaches the client.
  `echo` outside the Response, `header()` and `http_response_code()` do not: Scaleway does not put stdout in the response body.
- **Query strings**: the Event carries `queryStringParameters` as a map, so a repeated key such as `a=1&a=2`, or `tags[]=a&tags[]=b`, reaches the Application with its last value only, and the keys arrive sorted, not in the order the client sent them.
  Keys are then parsed like PHP does, so `a.b` becomes `a_b` and `a[b]` nests.
- **Form bodies**: `application/x-www-form-urlencoded` bodies are parsed for `POST`, `PUT`, `DELETE`, `PATCH` and `QUERY`, `multipart/form-data` only for `POST`, as PHP does.
  Uploaded files are `UploadedFile` objects in test mode: `move()` works, `is_uploaded_file()` does not.
- **Cookies**: request cookie names are taken verbatim: unlike PHP's `$_COOKIE`, dots and spaces are not turned into underscores and `a[b]` does not nest.
- **Streaming**: `StreamedResponse` and `BinaryFileResponse` are buffered into one Function Response; Server-Sent Events and long downloads are not possible.
  Bodies that are not valid UTF-8 are sent base64-encoded, and decoded by the platform.
- **PSR-15 responses** go through Symfony's `Response`, which adds `Cache-Control: no-cache, private` and `Date` when they are missing.
- **HEAD requests**: only FrameworkBundle's `ResponseListener` strips the body of a `HEAD` response, but the platform sends none anyway.
- **Request size**: Scaleway rejects request bodies over 6 MiB with a `413`, before they reach the function.
- **CORS**: Scaleway adds `Access-Control-Allow-Origin: *` and `Access-Control-Allow-Headers: Content-Type` to every response, whatever the Application sends.
  Any website can therefore read the responses of a public function from a browser, though without the user's cookies: do not rely on the same-origin policy to protect them.
- **Uncaught failures**: the runtime turns exceptions into a 500 itself, but a fatal error or `exit()` ends the Warm Process, and Scaleway answers a failed Invocation with a `500` whose body is `Error while executing function`.

## Client IP and trusted proxies

The runtime trusts no proxy, as clients can forge most forwarding headers on Scaleway ([ADR 0007](adr/0007-trust-only-headers-the-platform-overwrites.md)):

| Request | From | Why |
| --- | --- | --- |
| `getClientIp()` | `X-Real-Ip`, `127.0.0.1` without it (CRON Invocations) | Set by the platform, whatever the client sends |
| `isSecure()`, `getScheme()`, port | `X-Forwarded-Proto`, the scheme's default port | Set by the platform (`https`, `http` for CRON Invocations) |
| `getHost()` | The [`host` option](configuration.md#host) | No `Host` header reaches the function |

`X-Forwarded-For` (the client's value is kept in front), `X-Forwarded-Host`, `X-Forwarded-Port` and `Forwarded` are passed through from the client: they reach the Application as plain headers, and nothing trusts them.
Headers whose name holds an underscore (`X_Real_Ip`) are dropped, as nginx and Apache do, since PHP would read them as the platform's ones.

> [!CAUTION]
> Do not configure `framework.trusted_proxies`: the client is already resolved, and trusting `REMOTE_ADDR` or private ranges would make Symfony trust those forgeable headers again.
> Trusted proxies your application configures still apply, as usual.
