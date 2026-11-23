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

## Audit and who is told

- **larena/audit is required** (composer `require`, auditor msg-3d61bd36 point 2). The panel profile ships it in every
  case: Root's `composer.distribution.json` and the panel's `assembly/composer.json` both require it, so making it a
  hard dependency costs nothing and says what the code needs. A change of the server is still refused explicitly where
  the audit sink is not bound (an application that installed the package without registering larena/audit's provider):
  `PanelOperations::startCommand()` answers `audit_unavailable` before the server is asked, while plans and reads go on.
- **The chain of a change.** `PlanResult` carries the plan's request id (the plan id) and the server's `operation_id`;
  `PlanReceipts` keeps them in the plan's receipt (`Operations\PlanTrail`, one per request). The confirmed receipt hands
  them to the start: `JobStartResult` is the job's receipt — who (and for whom, while acting as someone), the start's
  `request_id`, `plan_id`, `job_id` and `operation_id` — and the same set is written as `env.server.change_started`,
  correlated by the plan id. `JobViewResult` carries the job's starting request and its operation id; the job's screen
  shows the operation id.
- **Who hears about the server.** `People\PanelRecipients` is the one list for the bell and the mail: active
  administrators who hold `env.server.read`, and for a site the active people of its server account who hold
  `env.sites.read`. Access and Auth do not signal blocking, a revoked role or a removal, so the list is worked out when
  it is used; `larena:env-check` drops the link «person — server account» of a removed person or of one who lost
  `env.sites.read` on each run, each drop in the audit log (`env.person.owner_released`, reason `removed` or
  `right_revoked`). A blocked person keeps the link and is not told.

## Rights and audit events: one source

`Providers\EnvDeclarations` is the one source of the package's rights (`RIGHTS`: six, with label, target, grant, risk
and audit) and audit events (`AUDIT_EVENTS`, the 22 the panel writes; `VIEW_EVENTS`, the 17 its menu rows name). The
provider registers the rights from it and `PanelAudit` refuses to write a type it does not name. `access.yaml`,
`audit.yaml` and `module.yaml` are written by hand to match it, and `composer run declarations:check` (part of
`quality:gate`) fails on any difference: a file's list or field, the rights the provider registers, the menu's rights and
view events, the `access:` rights of routes and config, the rights the code authorizes, and the events it writes — an
undeclared one, or a declared one nothing writes. A new right or event goes into `EnvDeclarations` first, then into the
three files.

## Settings

`LARENA_ENV_GATEWAY` is `sudo` (default; `LARENA_ENV_SUDO_BINARY`, `_GATE`, `_TIMEOUT`), `recorded` or `ssh`; for ssh also `LARENA_ENV_SSH_HOST`, `_USER` (`simai-panel`),
`_PORT`, `_KEY` (a path), `_KNOWN_HOSTS` (a path; the host's key must be there), `_BINARY`, `_TIMEOUT`. An incomplete
target, or recorded answers outside `local` and `testing`, leaves the screen saying `gateway_not_configured`.

The panel's background jobs on the application's scheduler — `larena:env-check` every 5 minutes and `larena:env-warm` every
minute — run only where `larena-env.scheduler.enabled` is on (`LARENA_ENV_SCHEDULER_ENABLED`, off by default). The panel's
edition (`PanelEditionServiceProvider`) turns it on, and larena/notification's daily cleanup with it; any other application
that installs this package schedules nothing.

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
