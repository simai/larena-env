# Larena Env

The main package of the **Larena Panel** edition: the server area of a hosting panel. simai-env stays the only component
that changes the host; this package asks it through the panel gateway and shows the answers. Stage 0 only reads.

## Parts

- `Contracts\PanelGateway`: ask one question, get an answer with a status and, unless it is ok, a reason code. Drivers
  never throw for a refusal or a failure.
- `Gateway\RecordedAnswersGateway`: replays answers recorded by simai-env (`resources/recorded-answers`, a sample of
  `testing/fixtures/panel/` with its manifest). Allowed only in `local` and `testing`; the screen says the data is recorded.
- `Gateway\SshGateway`: ssh to the gateway over a key with a forced command, through `ProcessExecutor` and `GatewayRunner`
  of larena/core. The request goes on stdin as one JSON line; argv holds only the configured target. The credential is a
  path to a key; ssh's stderr is never kept, shown or logged.
- `Server\ServerOverviewReader`: `self:describe` as typed data, without paths and database names.
- `Area\ServerArea`: the main scope `organization:server`. `php artisan larena:env:area` registers it; a panel assembly
  sets `larena-core.first_run` to `ServerArea::firstRunDeclaration()`.
- `/admin/server`: one screen, composed only where larena/admin is installed. Right: `env.server.read`.

## Settings

`LARENA_ENV_GATEWAY` is `recorded` (default) or `ssh`; for ssh also `LARENA_ENV_SSH_HOST`, `_USER` (`simai-panel`),
`_PORT`, `_KEY` (a path), `_KNOWN_HOSTS` (a path; the host's key must be there), `_BINARY`, `_TIMEOUT`. An incomplete
target, or recorded answers outside `local` and `testing`, leaves the screen saying `gateway_not_configured`.

## Checks

`composer run quality:gate`. Re-record the sample with simai-env after a change of the answers' shape, never edit it by hand:
a file that differs from its manifest is refused (`recording_tampered`).
