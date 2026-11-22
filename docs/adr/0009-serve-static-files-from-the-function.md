# The function serves Static Files itself

A Symfony application ships its CSS, JavaScript, images and fonts under its web root, and nothing in front of a Scaleway function serves them: every request reaches the Handler.
We therefore let the runtime serve the files under the web root itself, before building the Application, so that a standard application works on Scaleway without extra infrastructure, and keep moving them to Object Storage as a later, optional step.

## Considered Options

- Uploading them to Object Storage, behind Edge Services or not, and pointing `asset()` to it: kept for later, as it needs a bucket, CORS settings and a deployment step before an application works at all.
- Serving nothing and documenting Object Storage only: rejected, an unchanged application would answer its own stylesheets with 404s.

## Consequences

- Each Static File costs an Invocation, can hit a cold start, and its binary body grows by about a third once base64-encoded.
- Its size is bounded by the largest Function Response the platform accepts.
- Files written at runtime, such as user uploads, are only served by the instance that wrote them, each instance having its own ephemeral disk: they belong in Object Storage.
- Only a file that a `manifest.json` above it maps from another name, as AssetMapper and Webpack Encore write them, is cached for good: a digest guessed from the name alone would also match plain names such as `font-awesome.css`, which would then stay stale in browsers for a year.
