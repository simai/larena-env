# Changelog

## Unreleased

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
