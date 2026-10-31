# Changelog

## Unreleased

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
- The recorded answers are the set of simai-env 1.25.0, plans, jobs and logs included.
