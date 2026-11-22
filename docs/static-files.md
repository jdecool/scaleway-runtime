# Static Files

The runtime serves the files of `public/` itself, before building the Application: stylesheets, JavaScript, images, fonts, `favicon.ico`, `robots.txt`, and what AssetMapper or Webpack Encore compile there.
Such an Invocation pays no Kernel boot, and is answered even when the Application fails to build ([ADR 0009](adr/0009-serve-static-files-from-the-function.md)).

A file is served when:

- the request is a `GET` or a `HEAD`;
- the path names a regular file under `<project_dir>/public`, a symbolic link leading out of it excluded;
- no segment of the path starts with a dot (`..`, `.env`, `.htaccess`), but for a leading `/.well-known/`;
- its extension is not `.php`.

Every other request goes to the Application, directories included: there is no index file nor directory listing.

> [!IMPORTANT]
> A file wins over a route of the same path.
> An application answering `/robots.txt` from a controller while shipping `public/robots.txt` gets the file: delete the file to keep the route.

## Responses

- The `Content-Type` comes from the extension, `application/octet-stream` for an unknown one, without charset.
- Each response carries `X-Content-Type-Options: nosniff`, and an `ETag` of the bytes it sends: a matching `If-None-Match` gets a `304` without body.
- `Cache-Control` is `public, max-age=0, must-revalidate`: browsers keep the file but check it on each use.
- It is `public, max-age=31536000, immutable` for a file whose name changes with its content: one that a `manifest.json` in its directory, or in one above, maps from another name.
  AssetMapper's `public/assets/manifest.json` and Webpack Encore's `public/build/manifest.json` (with versioning) are such manifests.
  Names alone are not trusted, as `font-awesome.css` would look as digested as `app-mH7q4W_.css`.
- `Range` requests get the whole file with a `200`.

No CDN stands in front of a function: every request for a Static File is an Invocation, billed and possibly paying a cold start.
Long-lived caching of digested files is what spares most of them.

## Compression

A file is sent compressed when the client accepts it, in this order:

1. a precompressed variant shipped next to it, `app.css.br`, then `app.css.zst`, then `app.css.gz`, written by any build step;
2. otherwise, when the `static_compression` runtime option is set, the file compressed by the runtime.

```json
{
    "extra": {
        "runtime": {
            "static_compression": "gzip"
        }
    }
}
```

`static_compression` is `false` by default, `"gzip"`, `"zstd"` or `"br"`, each needing its extension (`zlib`, `zstd`, `brotli`): any other value, or one whose extension is not loaded, answers every Invocation with a 500 until fixed.
The Scaleway `php85` runtime loads `zlib` and `zstd` but not `brotli`: use `"zstd"` or `"gzip"` there, and ship `.br` variants built beforehand for brotli.
The runtime only compresses textual files (`text/*`, JavaScript, JSON, XML, SVG, WebAssembly) of at least 1 KiB, and keeps the results in memory for the Warm Process, up to 32 MiB.

The order of preference is the runtime's: only a `q=0` in `Accept-Encoding` refuses an encoding.
Compressed responses carry `Content-Encoding`, an `ETag` of their own and `Vary: Accept-Encoding`.
A request for `app.css.gz` itself gets that file, as any other.

## Limits

- A Static File must fit in one Function Response, base64-encoded when it is binary, which takes a third more.
  Scaleway enforces no limit, but responses of 10 MiB and more intermittently fail with a `504` after 60 seconds ([measured](probe-results.md#size-and-encodings)).
  The runtime therefore refuses Static Files over 6 MiB in a Function Response: it answers a 500 and logs the file, and `scaleway-package` warns about such files.
- A file written at runtime under `public/`, such as an upload, is only served by the instance that wrote it, each instance having its own disk.
- Store uploads, large files and busy sites' assets on Object Storage, behind Edge Services or not, and point `framework.assets.base_urls` to it.
- `assets:install` boots the Kernel: at deploy time, run it from a `post-install-cmd` with an explicit env, `@php bin/console assets:install public --env=prod`, for the same reasons as [`cache:warmup`](configuration.md#cache-and-writable-directories).
