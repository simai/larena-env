# Larena Env

The main package of the **Larena Panel** edition: the server area of a hosting panel. simai-env stays the only component
that changes the host; this package asks it through the panel gateway and shows the answers. An action is never done by
the panel: it is planned, confirmed by a person, and run by the server as a job the panel follows.

## Parts

- `Contracts\PanelGateway`: ask one question, get an answer with a status and, unless it is ok, a reason code. Drivers
  never throw for a refusal or a failure.
- `Gateway\RecordedAnswersGateway`: replays answers recorded by simai-env (`resources/recorded-answers`, a sample of
  `testing/fixtures/panel/` with its manifest). Allowed only in `local` and `testing`; the screen says the data is recorded.
- `Gateway\SshGateway`: ssh to the gateway over a key with a forced command, through `ProcessExecutor` and `GatewayRunner`
  of larena/core. The request goes on stdin as one JSON line; argv holds only the configured target. The credential is a
  path to a key; ssh's stderr is never kept, shown or logged.
- `Gateway\SudoGateway`: the same protocol to the gateway on this server, `sudo -n /usr/local/sbin/simai-panel-gate` with no
  arguments and one JSON line on stdin; the default, the way a panel installed by `panel install` reaches it.
- `Server\ServerOverviewReader`: `self:describe` as typed data, without paths and database names.
- `Server\SiteDetailsReader`: one site (`site:describe`) and its nginx log (`logs:nginx`), typed the same way: a backup is
  named by its own name, never by its path on the host.
- `Operations\PanelOperations`: the one way an action happens — `plan()` says what it would do, `start()` runs it only
  after a person confirmed that plan, `view()` follows the job it started and `cancel()` stops one that still runs.
- `Operations\SiteOperation`: the actions a site's screen offers, and which of them suit it: a site without a certificate
  has nothing to renew, without a previous release nothing to roll back to, without workers no queue to restart.
- `Area\ServerArea`: the main scope `organization:server`. `php artisan larena:env:area` registers it; a panel assembly
  sets `larena-core.first_run` to `ServerArea::firstRunDeclaration()`.
- `People\PersonOwners`: which owner of the server a person at the user level works as; `People\PanelAudit`: the
  panel's own audit events. `/admin/server/people` gives people their spaces and lets the system level look as them.
- `/admin/server`, `/admin/server/sites/<domain>` and `/admin/server/jobs/<id>`: the screens, composed only where
  larena/admin is installed. Reading them is `env.server.read`; planning, confirming and stopping is `env.server.operate`.

## Settings

`LARENA_ENV_GATEWAY` is `sudo` (default; `LARENA_ENV_SUDO_BINARY`, `_GATE`, `_TIMEOUT`), `recorded` or `ssh`; for ssh also `LARENA_ENV_SSH_HOST`, `_USER` (`simai-panel`),
`_PORT`, `_KEY` (a path), `_KNOWN_HOSTS` (a path; the host's key must be there), `_BINARY`, `_TIMEOUT`. An incomplete
target, or recorded answers outside `local` and `testing`, leaves the screen saying `gateway_not_configured`.

## The panel's release

`assembly/` is the application of the Larena Panel edition: exact package revisions in `composer.lock`, the list in
`build.json`, the requirements for simai-env in `.simai/app.json`. `scripts/build-panel-archive.sh` makes
`dist/larena-panel-<version>.tar.gz` and its `.sha256`, with `vendor/` and the Framework assets inside, for
`simai-admin.sh panel install --archive <file> --sha256 <hash> --domain <name> --confirm yes`.

`Providers\PanelEditionServiceProvider`, listed only by the assembly:

- the release works with a `.env` that holds only the database: `Panel\PanelEnvironment` gives the defaults and writes
  `APP_KEY` once;
- `/?setup=<SIMAI_PANEL_SETUP_TOKEN>` opens the first-run screen for one browser session, and without it the screen answers
  403; when the first administrator exists, the line is removed from `.env`;
- `artisan migrate` also synchronizes the access presets and registers the server's scope; safe to run again;
- `/up` answers without sign-in; `/` and `/admin` lead to the server screen;
- state is only in `.env` and `storage/`; the records naming the Framework bundle are put into `storage/` from the release.

## Checks

`composer run quality:gate`. Re-record the sample with simai-env after a change of the answers' shape, never edit it by hand:
a file that differs from its manifest is refused (`recording_tampered`).
