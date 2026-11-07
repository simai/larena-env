# Changelog

## Unreleased

- A site's backups are said one way in the sites list, on the site's «Резервные копии» and on «Резервные копии» of the
  server (critics of 0.4.1): «Копий ещё нет», «Последняя копия не удалась 07.11 03:00 UTC», «Последняя копия 05.11
  03:00 UTC — старше двух суток» or «Последняя копия 07.11 03:00 UTC», red or green, a failure (from the server's
  alerts) first; the daily backup is said apart, and the site's tab shows one line instead of three warnings.
- Critics of 0.4.1: the overview and «Безопасность» count the security checks in one way (`SecurityFindings`: the same
  check on several sites is one finding, HTTPS of local names is one quiet line, the panel's own address stands under
  «Кто может открыть панель») and the overview says which of its items are the setup steps and updates; the overview
  has a line on the sites (how many work, have no fresh copy, go without HTTPS); the setup moved to
  `/admin/server/overview/setup`, so the menu marks «Обзор», and its breadcrumbs end with «Настройка сервера».
- Fixes after the independent critique of 0.4.0 (seven critics, 2026-11-07):
  - The wizard's «Оповещения» step no longer fails with an error 500 (Blade could not read `@json` with nested
    brackets); a test now compiles every view of the panel and draws every wizard step.
  - A server that does not answer is a red line in the alert bar («Сервер не отвечает панели…»), not an empty bar; for
    30 s after such a failure the pages do not wait for it again. After an action the alert bar and the kept answers
    are read anew, and while a job may still be changing the server the slow reads are kept 30 s, not 10 minutes.
  - `/admin` and the sign-in lead to the overview (`/admin/server/home` decides after the admin's sign-in check).
  - A domain the server has no site on says «Сайт не найден»; an unknown section or wizard step goes to the right address.
  - The server's checks panel_access, panel_domain, vulnerability_scan, site_components, wordpress_core, certificate,
    php_eol, backup_schedule and backup_offsite have the panel's words; a serious security alert is «Риск безопасности:
    <check> · <site>» and leads to «Безопасность», never the server's «security: …».
  - One scale on the overview and «Безопасность»: «Срочно / Советуем / Сделано», counted alike (the check's status);
    alerts nobody receives are urgent; the same finding on several sites is one line; no HTTPS advice for .test or
    .localhost names; the load tiles come first and at most five advised items are in view, the rest folds.
  - Words: «Показать, что будет сделано» on every plan button; «SFTP и SSH»; «Кто может открыть панель»; «Поставщик»;
    the roles «Администратор сервера» and «Владелец сайтов» instead of levels and spaces; «Копировать базу данных».
  - The site card says «Без PHP», «MySQL», «настройки, база данных, файлы», «Свой аккаунт»; a redirect has only its
    overview, domains and log; the open section is in the page's heading and the tab's title; repeated buttons
    («Исправить») name their item for screen readers; a firewall turned off is a red mark.
  - The storage form on «Копии» uses the Framework's inputs, as the wizard does; the wizard is under «Обзор» in the
    menu and the breadcrumbs (its routes are `larena.env.admin.overview.setup*`).
  - The processor tile says it is the last minute's load, the chart's mark «последний замер».
  - The sites' list colours the certificate (red without HTTPS on a public name, «Локальный адрес» grey) and the last
    backup (red when none or older than two days), the words kept; «Копии» starts with every site's last backup.
  - A site's log opened again within 30 s is not searched again (it is never asked in the background); the software
    filter says «Новые версии программ».

- The panel opens faster (ENV 2026-11-07: each ask starts simai-env, 0.7-1 s; the overview took about five seconds).
  Every minute `larena:env-warm` (the application's scheduler) asks the server again what the pages asked within the
  last twenty minutes, once an answer is 40 s old, and keeps it for 150 s, so a page opens from kept answers; nobody
  looking, nothing is asked. With simai-env 1.52.0 the reads go together in one `batch` call, and the overview asks its
  reads (load, services, alerts, history, update plans) in one call too; an older server is asked one by one.
- An update waiting shows in the admin's alert bar and among the overview's recommended actions, simai-env first, then
  the panel; «Обновить» opens the server's plan of that update at once, to confirm (/admin/server/update?target=env).
- Signing in leads to the server's overview again, also when the panel was opened signed out: the sign-in goes on to
  the panel's address, which decides (the overview, or the sites for an owner of sites), not to the sites' screen. «Обзор»
  is the first row of the panel's menu (the test server's owner, 2026-11-07).
- After signing in, whoever may read the server lands on its overview, the panel's dashboard; an owner of sites on the
  sites (owner 2026-11-06). The overview starts with «Рекомендуемые действия»: one list of what to do now (the server's
  high and critical security problems), what is advised (the other problems, a waiting update, the setup's steps not
  done) and what is done (folded), each with one click — a fix planned and confirmed, or the screen where it is done.
- «Настройка сервера» (/admin/server/setup) is made of the Framework's wizard (sf-wizard 1.0, owner 2026-11-06) and opens
  once at the administrator's first sign-in. Every step is done inside it: the panel's domain with Let's Encrypt, where
  alerts go and a test message, the protection level with «Усиленный» advised, an S3 storage for backups with its keys
  checked, simai-env's update; each shows the server's plan, runs it on «Применить», says how it went and offers
  «Повторить» after an error. The sign-in's own security opens in a new tab beside the wizard. Any step can be put off; a
  step the server already shows done (a domain with a certificate, a channel, the enhanced or strict level, a storage)
  passes by itself, and what is left stays among the overview's recommended actions, which open the wizard's step.
- «Доступ к панели» starts with «Адрес панели» (simai-env 1.51.0, panel:address/domain/port/ip-access): the address to
  open the panel at; the address by IP with its self-signed certificate's SHA-256 fingerprint; a domain attached with
  the hint which A record to add first (Let's Encrypt, nothing changes when no certificate comes) or detached; the port
  moved; the address by IP turned off once the domain has its certificate. A new port or address opens a page that
  leads to the new address, where the change is kept within 5 minutes or the server puts the old one back.
- «Сервер › Хранилища копий» (simai-env 1.44.0, backup:storage-*): S3 storages for the sites' backups away from the
  server, each with its bucket and folder, the days copies stay, whether new backups go to it, its key's hint and its
  last test, with «Проверить» and «Отключить»; the copies in one with «Забрать на сервер»; a storage to connect with the
  service chosen from a list (Yandex, Selectel, VK Cloud, AWS, R2, B2, MinIO or another S3) filling its address and
  region. The keys go only to the server: between the plan and its confirmation they wait encrypted in the session,
  never in the page. A site's backups say where its last copy went away from the server, or why it failed; the
  server's alert backup_offsite_failed has the panel's words and leads to the site's backups.
- «Безопасность» has «Доступ для ИИ-ассистентов» (simai-env 1.49.0, mcp:*): on or off, the keys with whom each works for
  (the whole server or one owner's sites) and their fingerprints, removing a key, adding a pasted public key for an
  owner or the administrator, and the ready lines to connect Claude Code, Codex or any client by JSON; every change is
  planned and confirmed.
- The overview's charts read simai-env 1.50.0: the processor's busy share in percent, «Сеть» in and out in Mbit/s or
  kbit/s with the interface and the link (its speed the whole where the server knows it), «Диск: чтение и запись» in MB/s
  or KB/s with the disk's kind and device; two lines with a legend and both values on hover. An hour reads the server's
  1-minute points and six hours its 5-minute ones; an older server, or one with no hour yet, gives its day's last points.
- The server overview's load history is one chart per measure (owner 2026-11-06, after a hosting provider's
  dashboard): «Статистика за» an hour, 6 hours, a day, a week or a month, and a scale from zero to the whole or by the
  values; the processor in percent with its cores, the memory and each disk in gigabytes with the whole, a scale in units
  on the left, the times (UTC) along the bottom, each point's time and value on hover. The page reads only the chosen
  period (and the day for the tiles), not three.
- «Безопасность» has «Страны» (simai-env 1.48.0): off, block the chosen countries or allow only them, on the sites'
  ports or all of them, with every country to tick (flag and name), the person's own address and up to 20 others never
  dropped, the dropped count, updating the country database and «Откуда этот адрес?»; allowing only a list or all ports
  is undone by the server unless kept on the job's page. The DB-IP licence line is shown under the block.
- «Безопасность» shows in the server's menu (its command was not among those the menu reads, so the row stayed hidden
  and the page took «Сайты» for its place), and its reads are kept like the other screens' until an action.
- «Отметить, что видели» resets the alert list the panel keeps for a minute, so the acknowledged alert leaves the bar
  on the next page instead of staying up to a minute (seen on the stand 2026-11-06).

- «Сервер › Безопасность» (simai-env 1.45.0–1.47.0): the server's protection score and grade, what to fix first in the
  order the server recommends with «Исправить» where the server can put it right itself (the fix is taken from the
  server's answer by the check's id on both steps, never from the form) and «Как исправить» otherwise, the checks that
  are fine folded, the protection level as three cards (Базовый / Усиленный, recommended / Строгий), the sites'
  vulnerable packages, and the sites' files with suspicious ones moved into quarantine and put back. Every check is said
  in the panel's words by its kind. The recorded answers are simai-env's set 25.
- «Отметить, что видели» in the alert bar is one click: the bar posts it, the panel acknowledges the alert at once and
  waits for the server to record it, and the page comes back without the item (before, it opened a plan page and the
  alert stayed until a second confirmation, owner 2026-11-06).
- The bar offers a new version of simai-env or of the panel by itself, to the one who may change the server, with
  «Обновить» (the server's kept self:update-plan; named by its version, so a newer one shows again after closing).
- The programs' search field has a visible border (it was almost invisible on the panel's background).
- The release build carries the panel's own words (assembly/lang/vendor): `tar --exclude=./vendor` dropped lang/vendor
  too, so «Пользователи панели», «Страница входа» and the sign-in tab title never reached the stand. The build names
  the assembly's top-level entries instead, and stops if the words are missing.

- Speed (the panel's UX audit, 0.5 s a click): the reads that take seconds on the server and change only through an
  action — software:list, php:list, php:extensions, self:update-plan, self:profiles, self:commands — are kept for 10
  minutes (`slow_cache_seconds`, LARENA_ENV_GATEWAY_SLOW_CACHE_SECONDS) instead of 30 seconds; an action or a job still
  makes them stale at once. On the stand they take 2.2–2.6 s each, so «Программы» opened after a pause took 6 s.
- The panel's sign-in page is «Вход — Larena Panel» in the tab, the one format of every page.

- «Программы» reads simai-env 1.43.0's catalogue (recorded answers set 20, simai-env 1.43.1): 17 programs in their
  groups (web servers, security, databases, cache, languages, tools), each with what it is for in the panel's words,
  «Нужна simai-env» on the ones the server keeps, and a row menu: «Установить» (Node.js and PostgreSQL with a major
  version), «Обновить до …» for one program alone (software:install on an installed one), «Удалить» (software:remove,
  1.43.0; the plan says what stays on the server), or why not (the sites that use it, the certificates). Filters
  (all, installed, can install, updates, each with its count) and a search by name, kept in the address. A server
  before the catalogue still shows its fixed list.

- The panel assembly takes Root a3c85ee1's pins: admin 27f33cb7 (tab title, menu options) and ui 35617b56 (sf-admin-menu 1.7.1).

- «Программы и обновления» is one table of the server's programs, grouped by what they do (web servers, databases,
  cache, languages, certificates): each with what it is for, its state, its version and a menu of what may be done to
  it; PHP is a row that opens its screen; versions are said short («1.24.0», the full one on hover). The updates of the
  panel, simai-env and the system are one line of badges, their details folded under it (the panel's UX audit).
- The panel's menu is plain lists without group headings; the sign-in diagnostics row is gone (its address still
  opens), the sign-in backgrounds open from «Страница входа» (the general settings, renamed), the panel's users are
  «Пользователи панели» (the admin's product menu options `headings`, `hidden_rows`, `folded_rows`).

- The panel assembly takes the revisions Root 1abd799d pins (admin b30fb81a, ui 57e4af35 with the Framework pair
  pro-ce48954dd94b over ui-4cd23cd8b490, access, audit, property, setting), so the panel draws the alert bar and a
  site's menu in the second column.
- At most two menus (the panel's UX audit, NAV-01): a site's sections are its menu in the menu's second column
  (`SiteObjectMenu`, the admin's navigation port 1.1.0 `context`): the site's name, what it runs and whether it works,
  «← Все сайты», its sections with a mark where something needs attention, the open one marked. The site's page has no
  tabs of its own. What needs attention is in the admin's alert bar on every page (owner decision 2026-11-05): the
  overview, the sites and a site's page draw no «Требует внимания» block, and the screens no longer ask the server for
  it; a failed backup is still said on the site's backups.
- A plan speaks the panel's words (the panel's UX audit, COPY-02): «Что будет сделано» names the site and each value
  as the form named it, yes and no in words; the server's description, command, effects and lock are under «Подробнее».
- A site's actions are buttons (the panel's UX audit, LAY-03): «Действия» is a row of them, and each opens its fields
  in a side panel (the Framework's `sf-modal` at the inline end) with «Показать, что будет сделано»; the tab is no
  longer a column of long forms. The load tiles show the name whole and the day's line across the tile, spread over
  the measures the server has (VIS-01).
- The sites' list fits a laptop (the panel's UX audit, LAY-04): five columns, «Домен, Состояние, Вид сайта, HTTPS,
  Копии»; whose the site is and its disk are on the site's own screen, the kinds are short («Переадресация») and a
  backup is its time («05.11 03:30 UTC»). A site's breadcrumbs reach the site and its section,
  «Сайты › demo.test › Файлы», each step a link back (HEAD-02).
- «Программы» and «Обновление» are one place, «Программы и обновления» (the panel's UX audit): simai-env's and the
  panel's updates head it, then the system's updates and the server's software. `/admin/server/update` opens it with
  the last notice kept; a plan's «cancel» and the overview's hint lead there; the menu has no separate «Обновление».
  A panel built here is never offered an older published version as its update (`UpdatePlan::beside`), and the older
  version is not shown as «Доступна» beside it.
- One heading scale on every panel screen (the panel's UX audit): a section's heading is the Framework's `text-2`
  (1.25rem), smaller than the page's own; before, each section read larger than the page title.
- A site's yes-or-no choices are ticks (the panel's UX audit): «Как решит сервер / Да / Нет» is gone; each box starts
  as the server would do it (the database ticked where the site has one, files and the https redirect ticked) and an
  unticked box sends no. The paragraph repeated above every tab's actions is gone: the button says what happens.
- Explanations in one line (the panel's UX audit, COPY-01): the long paragraphs about how the server works (files,
  nginx directives, SFTP uploads, cron, services, PHP versions, site owners, DNS, alerts) show their first sentence,
  the rest under «Подробнее» (`Admin\Explanation`, `partials/explanation`). A page's only block no longer repeats the
  page title («Службы сервера», «Версии PHP»: for screen readers only). PHP extension names wrap instead of being cut,
  in two columns.
- simai-env 1.42.1: an update's plan names its backup and restarts as codes (`backup_item`, `restart_items`); the panel
  says them in its own words («Данные панели (…)», «PHP сайта panel.localhost, Фоновые задачи …») and keeps the server's
  text for older versions or an unknown code.

- A site's sections in plain words (the panel's UX audit, 2026-11-05): DNS joins «Домены и HTTPS» and the nginx
  directives join «PHP и веб-сервер» (old `?tab=dns` and `?tab=nginx` links open there); «Выпуски» shows only for a
  site that has releases. The site's checks, their areas, the HTTPS row and the services carry Russian names instead
  of the server's codes; dates follow the admin's format and timezone; «Список проверен … назад» counts minutes, hours
  or days; the overview reads the same service list as «Службы»; a panel built locally says «своя сборка» instead of
  «не установлена». The site's sections are one list with PHP next to the domains (no group of one); the badge says
  «PHP 8.3» once; empty «База данных» and «Выпуск» rows are left out; sizes read «2,5 КБ» in Russian; the backups tab
  says the failed or missing backup the warning speaks of.
  The menu says what each place is: «Владельцы сайтов» (was «Пространства»), «DNS-зоны» and «Подключение DNS» (both
  were «DNS»), «Куда слать оповещения» (was «Оповещения», easy to take for the notifications).

- Screens answer without waiting for the server again (owner decision 2026-11-05, after the panel's UX audit: a click
  answers within half a second). `CachingPanelGateway` keeps a read of the server's state for
  `larena-env.gateway.cache_seconds` (30, `LARENA_ENV_GATEWAY_CACHE_SECONDS`; 0 turns it off) per person, the site's checks (`site:doctor`) included, asks the
  same read once per page, and drops every kept answer after a confirmed action or a look at a job. Logs, files'
  contents, job runs and refusals are never kept.

- The panel's warnings join the admin's alert bar on every admin page (owner decision 2026-11-05) through
  `EnvAdminAlertSource`: the server's open alerts (or the panel's own reading) for the person's level, errors as
  danger, each linking to where it is dealt with; an acknowledged alert stays out. The server is asked at most once
  a minute per person. «Отметить, что видели» opens the existing plan by a link (`GET /admin/server/alerts/ack/plan`).

- simai-env 1.42.0 (set 18): a PHP site's tab «PHP» shows its web server (nginx, or Apache behind nginx for
  `.htaccess`) and offers the switch through `site:apache`, planned first and run as a job; the server installs Apache
  the first time and puts everything back if the site stops answering. Administrator only; «Программы» lists Apache.

- simai-env 1.41.0 (set 17): a site's own nginx directives go through `site:nginx-custom-show` and `site:nginx-custom`,
  which replace the panel's earlier overlay draft. The tab shows the server's allowlist and whether the file is included;
  a save is planned first (the plan names the size only), applied as a timed change and kept with «Оставить» before its
  time runs out, or rolled back by the server. While a change waits, the text is read-only. «Очистить» removes the
  directives. Administrator only.

- simai-env 1.39.0–1.40.0 (recorded answers set 16): a site's files go through the server's `file:*` commands, which
  replace the panel's earlier draft (`fs:*`). A folder lists folders first with size, time, permissions and a link's
  target; a text opens in the editor and is saved with the sha256 it was read with, so a change made meanwhile is refused
  `changed` and the newer text is shown; a text over 60 KB is read-only (it travels in the request line). Each entry has
  Download, Rename, Pack (zip or tar.gz), Unpack for an archive (into a folder, replacing files only when ticked) and
  Remove; the folder has New folder, New file and Upload (up to 1 GB, never replacing a file unasked). There is no trash:
  a removal says it is final. A download is the answer line of the `download` kind and exactly its size in bytes.

- simai-env 1.38.0 (set 14): «Программы» shows the server's components (version, update, service state, Install for
  Node.js, Redis or PostgreSQL) and the system's updates (counts, security packages, reboot pending, index age) with
  Refresh, Install security updates and Install all, each by plan with the package list. Administrator only.

- simai-env 1.37.0 (set 13): «Программы › PHP» lists the server's PHP versions with their sites and pending fixes, and
  installs, updates, removes a free version and turns extensions on or off, each by plan. Administrator only.

- The new-site screen names the Framework's new tags (rename R3).

- simai-env 1.36.0 (recorded answers set 12): certificates through DNS with Cloudflare. «DNS › Подключение» says whether
  the server keeps a Cloudflare token of its own (`ssl:dns-status`, `simai-ssl-dns/1`) and lets the administrator give it
  the panel's connected token or take it back (`ssl:dns-credentials`), planned and confirmed; the token is read from the
  panel's connection at each step, never travels through the page and shows in the plan as «(stored)». Both the server's
  and the DNS right are asked. Once the server keeps a token, getting a certificate offers the check through DNS and a
  wildcard (`ssl:letsencrypt challenge, wildcard`); before that the form stays as it was.

- Facts are one column of rows on every screen: the admin's two-column rule for a panel's list put two rows side by side,
  so on a phone a value such as «mysql» broke in the middle.

- The panel assembly takes admin 6a13ba89 (a long site name in the heading stays whole on a phone, its marks wrap) and
  property 2c4c9193, which that admin needs, the revisions Root f1c978ab pins.

- simai-env 1.35.0 (recorded answers set 11): the firewall and who reaches the panel (Panel plan 5.2, 5.3), the
  administrator's alone. «Сервер › Файрвол» reads `firewall:status` (`simai-firewall/1`): the rules with SSH, 80 and 443
  allowed for everyone locked, a blocked address with the blocks and its time, one port, TCP or UDP, per new rule. A rule
  is added and removed, an address blocked for an hour, a day, a week or until unblocked and let in again, each by plan;
  closing SSH, 80 or 443 for everyone, removing their rules and a second change while one of its kind waits are said
  before the server is asked. Every change is timed: the job's result names it, and the page that loaded over the new
  rules keeps it with `change:confirm`, else the server undoes it. «Доступ к панели» reads the panel's allow list from the
  same status and offers no change where the server knows no panel. The stand-in for these commands is gone
  (`LARENA_ENV_RECORDED_TOOLS`); the files' stand-in passes the server's own timed changes on.

- The rest of the panel's screens in the approved layout (owner's prototype 2026-11-02): state first as a coloured mark in a
  section's head (services «all 4 running» or how many stopped, a stopped one first and its restart primary; the alert
  channel, «the last one failed» in red; each update target current, available, blocked or unreadable); facts as rows on
  every screen; lists as rows with marks (people and their spaces, a blocked space; firewall rules, a rule that cannot be
  closed; a site's checks, PHP extensions, databases, backups, SFTP accounts, scheduled jobs with their last run in colour);
  a site's actions as rows with their names at the block's size; sub-headings at the block's size; the site card's
  sections wrap on a phone instead of scrolling, so the open one is always seen. Data attributes and texts are unchanged.

- The new-site wizard's fields are the Framework property view (`sf-property-view`, create mode) over the new site's own
  port (`NewSitePropertyViewPort`, `POST /admin/server/sites/new/port`, `env.sites.operate`): collection
  `env.new_site.<kind>`, the fields the kind takes in groups «Адрес», «PHP и база данных», «Приложение», «1С-Битрикс» and
  «Резервная копия», with the server's choices and defaults. record.create plans site:add exactly as the form does, keeps
  the values in the person's session as a draft and leads to its plan screen (`/admin/server/sites/new/plan/<draft>`); a
  value the gateway would refuse, or a choice it does not offer, is said under its field before the server is asked. The
  plain form stays as the view's fallback. An owner gets no owner field and cannot name one.

- The Larena Panel 0.3.0 assembly: build.json takes Root 28b4be24 for the Framework assets, and the lock takes access
  050716f, admin d6c66967, audit b7041203 and notification 70e9ac62, the revisions Root pins.

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

- simai-env 1.34.0 (recorded answers set 10): a site's own scheduled jobs. A site's «Планировщик» tab shows where the
  server lists `cron:list`, at either level for the person's own sites: each job with its schedule in words, on or off,
  when it was added and its last run (`simai-cron-jobs/1`), its last runs with their output (`cron:job-runs`, 20 of the
  50 the server keeps), and a hint that jobs run as the site's user in the site's folder (current/ for a site with
  releases) and that a run still going makes the next one skip. A job is added from a preset or five cron fields,
  checked in the panel as the server checks them (numbers, ranges and steps within each field's range: "61 * * * *",
  `@daily` or names are refused before any plan), with one printable line of up to 1000 characters not starting with
  «-»; a site with 50 jobs says so instead of offering one more. Turning a job off and on and removing it go by plan.
  A job the server does not have (`unknown_cron_job`) and another owner's site (`owner_scope`) are said in words. The
  tests' stand-in now answers only the firewall and who reaches the panel (1.35.0).

- simai-env 1.33.0 (recorded answers set 9): the server's services and the kinds of site it makes. «Сервер › Службы»
  lists only the services the server has (`service:list`: running or not, since when, whether it starts with the
  server), restarts each by plan, rereads the settings only where the server says it can (nginx and PHP-FPM), and
  shows a service's journal (`service:log`). The server checks nginx's and PHP-FPM's settings first; when the check
  fails, the job's screen says in plain words that the settings have an error and the service keeps running as it was.
  The new-site wizard takes the kinds from `self:profiles` (either level), the simplest first: «Приложение на порту»
  asks for the local port only (127.0.0.1, never 80 or 443), the path and WebSockets, and has no daily backup; a
  1C-Bitrix site also asks for its installer (none, bitrixsetup.php or restore.php, `bitrix-files`). The menu rows of
  «Службы», «Файрвол» and «Доступ к панели» show only where the server lists their commands, read from its
  description and kept for five minutes in the application's cache; the scheduler, the firewall and who reaches the
  panel (1.34.0, 1.35.0) stay hidden, answered only by the tests' stand-in.

- Stage 5 on the panel's side, the server's tools, as agreed with simai-env (1.33.0 to 1.35.0): no release has the
  commands yet, so they show only where a server lists them in `gate.commands`; elsewhere the screens say a newer
  simai-env is needed, with no buttons. «Сервер › Службы» (system level): each service's state, «Перезапустить» and,
  for nginx and PHP-FPM, «Перечитать настройки», by plan and a job (`service:restart|reload`), and its journal
  (`service:log`). A site's «Планировщик» tab, at either level for the person's own sites, where the server lists
  `cron:list`: the jobs with their schedule in words, on or off, the last run and its output; add one by a preset or
  five cron fields and a one-line command, turn it off and on, remove it, all by plan. «Сервер › Файрвол» (system
  level): the rules, SSH and the panel's port locked and never offered for removal, open a port, block an address for
  an hour, a day, a week or for good, unblock; every change is applied for a while and comes back by itself unless
  «Оставить» is pressed in time, with the time left on the job's page and on the screen (the shared `change:confirm`).
  «Настройки панели › Доступ к панели»: anyone or a list of addresses and networks, kept the same way, and a warning
  before anything changes when your own address is not in the list. The new-site wizard takes the kinds from
  `self:profiles` where the server lists it, with 1C-Bitrix and «Приложение на порту», which asks for the local port
  only (and the path, and websockets). No new rights: `env.server.read`/`operate` and `env.sites.operate`. A stand-in on
  recorded answers (`LARENA_ENV_RECORDED_TOOLS`) lets the tests exercise them.

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
