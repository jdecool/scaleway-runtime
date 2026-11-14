# The Handler defaults APP_ENV to prod, whatever the .env files say

The zip ships `.env` as committed (ADR 0003, then ADR 0008), and the one Symfony generates says `APP_ENV=dev`.
With Symfony's usual precedence, a function deployed without an `APP_ENV` variable would therefore run dev with debug on, and show stack traces to anyone; on a read-only code directory, every Invocation even answers a 500 whose body is the full trace, as the dev container cannot be built next to the prod one warmed in the zip.
We therefore make `ScalewayRuntime`, which only the Handler uses, set the `env` option to the `APP_ENV` of the process environment (`$_SERVER`, `$_ENV`, then `getenv()`), or to `prod` when it is unset or empty, before Dotenv runs.
Dotenv never overrides a variable already set, so `.env` files can no longer choose the env, and `APP_DEBUG` follows `prod`, off unless set by the environment or a `.env` file.
The Emulator sets `APP_ENV=dev` unless it is started with one, so local development keeps debug pages.

## Considered Options

- Documenting that `APP_ENV=prod` must be set on the function: kept, but a forgotten variable must not be a leak.
- Forcing `APP_DEBUG=0` only: rejected, the application would still run its dev config, and fail on a read-only code directory.
- Rewriting `APP_ENV` in the shipped `.env`, or running `composer dump-env prod`: rejected, it alters committed files and needs Symfony Flex.

## Consequences

- An `APP_ENV` in `.env`, `.env.local` (never shipped anyway) or `.env.<env>` is ignored by the Handler and the Emulator alike: only the process environment, or the `env` runtime option, picks another env.
- With `dotenv_overload`, `.env` files still win, as the application explicitly asked.
