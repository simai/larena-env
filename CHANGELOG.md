# Changelog

## Unreleased

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
