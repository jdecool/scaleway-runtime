# The Request trusts only the headers the platform overwrites

The probe showed that Scaleway overwrites `X-Real-Ip` and `X-Forwarded-Proto` on every HTTP call, but passes `X-Forwarded-Host`, `X-Forwarded-Port` and `Forwarded` through from the client, prepends the client's `X-Forwarded-For` to its own, and drops the `Host` header.
Trusting a proxy for the usual `X-Forwarded-*` set, as the runtime did, let any client choose `getClientIp()` and the host of absolute URLs.
We therefore trust no proxy: the Request gets `REMOTE_ADDR` from `X-Real-Ip` (`127.0.0.1` when absent, as for CRON Invocations), `HTTPS` and the default port from `X-Forwarded-Proto`, and its host from the `host` runtime option (`SCALEWAY_HOST`, `localhost` by default), whatever Host the Event carries.
The forgeable headers still reach the Application as plain headers, and trusted proxies an application configures itself keep applying, as Symfony does.

## Considered Options

- Trusting `127.0.0.1` for `X-Forwarded-Proto` only: rejected, `REMOTE_ADDR` must hold the client for `getClientIp()` to need no trusted header at all, and the proxy would then never match it.
- Taking the client from `X-Envoy-External-Address`: equivalent, `X-Real-Ip` is the conventional name.
- Taking the host from the function's own domain: impossible, nothing in the Event or the environment carries it.

## Consequences

- Absolute URLs (router, redirects to absolute URLs, signed URLs) are only correct once `host` is set; Terraform cannot pass a function its own `domain_name` (the reference would be a cycle), so set it from a custom domain or after a first deploy.
- `X-Real-Ip` and `X-Forwarded-Proto` are undocumented platform behaviour, measured once; a change of the platform would need a new probe.
- An application configuring `framework.trusted_proxies` against `REMOTE_ADDR` or private ranges trusts forgeable headers again.
