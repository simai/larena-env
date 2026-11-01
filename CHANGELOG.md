# Changelog

## Unreleased

- simai-env 1.32.0 (recorded answers set 8): a safe update of simai-env and of the panel from the panel (Panel plan
  4.7). «Сервер › Обновление» (system level, `env.server.read`) shows, for «Окружение» and «Панель» each, the version
  now and the one available, the release notes between them with a link to all changes, the copy taken first, what
  restarts, which sites are asked before and after, and what comes back if they fail: for simai-env the previous
  release, with host migrations not undone; for the panel the previous build, with its database not rolled back. A
  reason the server cannot update now is said in words (the panel not installed by simai-env, no panel build for this
  release yet, the remote release not readable) and offers nothing. «Обновить…» plans `self:update {target}` with the
  same facts (`env.server.operate`) and follows its job; the job cannot be stopped, and while the gate and the panel
  are replaced its screen says the panel is restarting and to reload it, instead of failing. «Обзор» says in a line
  when an update is available. The user level is refused by its routes and by the server.

- simai-env 1.31.0 (recorded answers set 7): the server's own alerts and load history, kept every 5 minutes also
  while the panel is closed or down. Where the server lists `self:alerts`, «Требует внимания» on «Сайты», a site's card
  and «Сервер › Обзор» shows the server's alerts: open and acknowledged ones, critical as errors, the panel's own being
  down and a disk as the whole server's; an open one is acknowledged by plan (`self:alerts-ack`, either level, an
  owner's own sites only), an acknowledged one says by whom and when. Older servers keep the panel's own warnings.
  `larena:env-check` then copies the server's alerts into people's notifications, once when one opens and once when it
  is resolved, and samples nothing where the server keeps `self:metrics-history`; «Обзор» draws the server's day, week
  and month. «Настройки панели › Оповещения» (system level) shows where the server sends alerts, its last sending and
  error, and changes it by plan: a webhook (https only), a Telegram chat with the bot's token, which waits encrypted in
  the person's session between plan and confirmation and is never shown, or nowhere; «Отправить проверку». The stage 6
  shapes simai-env confirmed: `fs:read` answers `mtime` and `truncated` (a cut text is shown read-only), trash items
  their `name`, the nginx directives' pending change `{change_id, expires_at}`, kept by the shared `change:confirm
  {change_id}` (with `change:status`) instead of a command of its own, and `fs:archive` takes its paths as a list of
  up to 100, the one list argument.

- Stage 6 on the panel's side, files and nginx directives, as agreed with simai-env (after 1.35.0: 1.37.0 the files and
  the download, 1.38.0 archives and the directives). No release has the commands yet, so they show only where a server
  lists them in `gate.commands`; elsewhere the new «Файлы» tab says the server cannot yet, with no buttons. A site's
  «Файлы» tab, at either level for the person's own sites: the folder with a breadcrumb, folders first, size, change
  time and permissions; a text file up to 1 MB opens in an editor and is saved by plan and confirmation (5 MB at most,
  the previous version to the trash); upload (512 MB), new folder, rename, copy, move to the trash, permissions within
  the gateway's limits only (files 0400–0664, folders 0500–0775), pack and unpack; the trash, restored by plan
  (`exists` when the place is taken); a download checked against the size and sha256 the server named before a byte
  reaches the browser, served as an attachment. `path_outside`, `exists` and the other refusals are said in words. A
  saved text or upload waits on the panel's disk between plan and confirmation, named in the person's session by its
  sha256, and is removed once sent. The system level's «nginx» tab keeps a site's own directives: what is allowed in
  one line, applied by plan, then kept by a confirmation in time or undone by the server itself. The gateway drivers
  send an attachment after the request line as one stream (`PanelAttachment`), read a download into a file and check
  it (`PanelDownload`); `FakeFilesGateway` stands in for the commands on recorded answers (`LARENA_ENV_RECORDED_FILES`,
  local and testing only). The install check covers both: the recorded server without them, then the stand-in.

- Stage 7, DNS through the provider's API (owner decision 2026-10-31: no DNS server of our own). «Настройки панели ›
  DNS» connects a Cloudflare API token, checked by the provider and kept encrypted with the application's key; it is
  never shown again, only its last four characters, the zones it sees and what it may do in each. The server's own
  addresses are given there too. «Сервер › DNS» lists the zones and a zone's A, AAAA, CNAME, MX, TXT, CAA and SRV
  records; a record is added, changed or deleted by a plan in words and a confirmation, checked before the provider is
  asked and refused if it changed meanwhile. Each change keeps the record as it was in the panel's log and the audit
  log (`env.dns.record_changed`), and the zone's last change can be put back. A site's «DNS» tab, at either level for
  the person's own sites, shows the domain's and its www's records, says whether the domain points to this server, and
  offers «Направить на этот сервер»: the domain's address records to this server, www as its alias, by plan. New rights
  `env.dns.read` and `env.dns.operate` are the system level's. `DnsProvider` lets another provider come later.
- simai-env 1.30.0, recorded answers set 6. SFTP on a site's «Доступ» tab, by key only: a new access, a key, turn off,
  turn on, remove, each planned and confirmed; there is no password field anywhere. The list says where an access
  lands and when it was made, and a note says that files the site must rewrite need group-writable uploads (0664).
  `unknown_access` is said in words. A daily backup checks each new copy (`verify`, on by default, asked only of a
  server that takes it); the backups tab says the server's last check and whether a restore test ran; a failed check
  and a failed certificate renewal are errors among the warnings. A new site's daily backup goes with `site:add
  {backup}` where the server takes it, so nothing follows the site; an older server still gets `backup:data-schedule`
  after it. A redirect gets no daily backup. The host's backup directory is no longer kept from the answer.
- The panel from a phone (Panel plan, promise 6), checked at 375 px on both levels. A site's tabs are a row of chips
  that wraps, the open one marked; its facts keep their grid inside a panel; the log and the spaces read as cards; a
  person's space is a full-size choice; a checkbox sits beside its words; a job's output scrolls sideways instead of
  being cut. Times the server writes read as `2026-01-01 00:00 UTC` in warnings, backups, a restore, a job and the
  overview; the load history's legend sits on its lines.
- Stage 5 groundwork: «Создать сайт» first asks what the site runs, each kind with what the server sets up for it, and
  then only what that kind takes: PHP and a database for PHP, Laravel and WordPress (a database on by default for the
  last two), the target for a redirect. A database is asked of no other kind.
- Stage 4 of the Panel plan, on what simai-env 1.28.0 gives. A new site gets its daily backup by default: the wizard's
  «Ежедневная резервная копия» is ticked, its plan says so, and once `site:add` has made the site the panel starts
  `backup:data-schedule {enabled: yes}` (only where the server lists that command). A restore says before it is
  confirmed what it replaces: the site's database by the backup of that date and size, after the server saves the
  current one, or nothing for files unpacked for review.
- What needs attention, said first on «Сайты», on a site's card and on «Сервер › Обзор» (`PanelWarnings`): a disk at
  90% and over (system level only), a certificate ending within 14 days, expired or missing where https is expected, a
  site answering 5xx or nothing, a site without backups and without a daily one, a newest backup older than 48 hours,
  backups only by hand. The user level is warned about its own sites.
- `larena:env-check`, every 5 minutes by the application's scheduler: it keeps `self:metrics` in
  `larena_env_metric_samples` for 30 days and tells people what needs attention through larena/notification: the
  administrators about everything, a space's people about its sites; once when it appears, again once a day while it
  lasts, at once when it becomes an error (`larena_env_warnings`). The overview draws the last day and week of load.
- The assembly takes larena/notification: the notification centre is in the panel.
- Stage 2 of the Panel plan. The sites list says whose a site is, what it runs, its certificate (ending soon in words)
  and whether it has a daily backup; «Создать сайт» plans `site:add` for static files, PHP, Laravel, WordPress, Node.js
  or a redirect, and a person's own site lands in their space. A site's card has tabs: overview with simai-env's check
  (`site:doctor`) and backups, domains and certificate, PHP and services (`site:set-php`), releases, log, access.
- People and their spaces (/admin/server/people): a person at the user level (the editor role) is tied to an owner of
  the server (`larena_env_person_owners`) and asks the gateway only as that owner; a new owner is planned with
  `owner:create`. The system level can look at the panel as one of them: the gateway gets `impersonated_by`, every
  screen says so, and the audit log keeps `env.person.owner_assigned`, `env.acting_as.started` and `.stopped`.
- A notice after an action reaches the next page (the redirect carries the session).
- The recorded answers are the set of simai-env 1.26.0.
- The panel's two levels (owner decision 2026-10-31): `env.sites.read` and `env.sites.operate` reach a person's sites,
  `env.server.read` stays the whole server's. Every screen asks the gateway through `ResolvePanelActor`: the system level as
  the whole server, anyone else only as an owner, refused (`owner_unmapped`) until people are tied to owners. The server's
  status and services move to their own screen, /admin/server/overview; /admin/server is the sites.
- The assembly is a product (config/larena-product.php): no optional admin feature, and the menu Сайты, Журнал,
  Пользователи, Сервер (Обзор, sign-in diagnostics) and Настройки панели (general settings, backgrounds).
- The assembly has no larena/layout: the sign-in page draws from admin's prebuilt document. The first run speaks of the
  panel and asks no site name; the panel leaves its own site out of the list; /admin/ui is gone from production.
- Skeleton of the package: the panel gateway contract with recorded answers and SSH drivers (simai-panel-request/1), the
  typed server overview with reason codes, the `organization:server` scope and the server screen in the admin.
- The local sudo gateway driver, now the default. The Larena Panel edition: the assembly, the first-run link, the release
  archive (`scripts/build-panel-archive.sh`).
- The site's own screen: its certificate, its backups and the last lines of its nginx log, reached from a row of the
  server's list.
- The actions of the certificates, backups, releases and services (gateway stage 2): `ssl:letsencrypt`, `ssl:renew`,
  `backup:data`, `site:deploy-rollback`, `site:service-restart` and `queue:restart`. Each one is shown as a plan first and
  runs only when a person confirms it; the gateway starts it as a job, which the panel follows with `job:status` and
  `job:log` and can stop with `job:cancel`. Changing the server is its own right, `env.server.operate`.
- The recorded answers were the set of simai-env 1.25.0, plans, jobs and logs included.
