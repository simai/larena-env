#!/usr/bin/env bash
# Release gate: Larena Panel installs as its product declares it and its menu has no dead links.
#
# Copies the assembly (with its product file, config/larena-product.php) and larena/env as they stand in this
# checkout, and every other package of the assembly from the workspace at its HEAD (the gates run before the pins
# move), as path repositories. Then, on SQLite and recorded gateway answers:
#   1. the installed larena packages are exactly assembly/build.json's;
#   2. migrations run, twice;
#   3. no route of a feature the product left off exists; the panel's screens do;
#   4. the first administrator is made through ?setup=, signs in, and the menu is the product's: its roots, and every
#      row's address opens;
#   5. peace of mind (stage 4): a new site gets its daily backup, each copy is checked, a restore says what it replaces, the check measures
#      and tells the administrator, the sites, card and overview say what needs attention and draw the load;
#   6. files (stage 6): the recorded server, which has no file commands, says so; then a stand-in for them lists a site's
#      folder, saves, deletes, restores and hands a file over, each change by plan, and keeps a site's nginx directives;
#   7. the two levels (Panel plan, stage 2): the administrator gives a person of the user level a space (an owner of
#      the server), looks at the panel as them (marked in the audit log), plans a new site and opens a site's tabs; the
#      person signs in and sees only their own owner's sites, and none of the system level's screens.
#
# Usage: scripts/check-panel-install.sh
# Keeps the log and the temporary copy only on failure.
set -Eeuo pipefail

ENV_REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKSPACE="${LARENA_WORKSPACE_PACKAGES:-$(dirname "$ENV_REPO")}"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/larena-panel-install.XXXXXX")"
LOG="$WORK/install.log"
APP="$WORK/app"
PORT="$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1])')"
SERVER_PID=""

failed() {
  [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null || true
  echo "panel install FAILED ($1); log and copy kept in $WORK" >&2
  grep -v '^[[:space:]]*$' "$LOG" | tail -15 >&2
  exit 1
}

mkdir -p "$APP" "$WORK/packages/env"
(cd "$ENV_REPO/assembly" && git ls-files -co --exclude-standard . | tar -cf - -T -) | tar -xf - -C "$APP"
# The same parts of larena/env the release build takes (scripts/build-panel-archive.sh, ENV_PATHS), so a part the
# release would leave out fails here too.
eval "$(grep '^ENV_PATHS=' "$ENV_REPO/scripts/build-panel-archive.sh")"
(cd "$ENV_REPO" && tar -cf - "${ENV_PATHS[@]}") | tar -xf - -C "$WORK/packages/env"
git -C "$WORK/packages/env" init -q -b main && git -C "$WORK/packages/env" add -A && git -C "$WORK/packages/env" -c user.name=check -c user.email=check@example.test commit -qm check

# The other packages at their workspace HEAD, on a branch main, so that dev-main names them.
PACKAGES=$(python3 -c 'import json,sys; print(" ".join(json.load(open(sys.argv[1]))["packages"]))' "$ENV_REPO/assembly/build.json")
for package in $PACKAGES; do
  [ "$package" = env ] && continue
  git clone -q --no-hardlinks "$WORKSPACE/$package" "$WORK/packages/$package"
  git -C "$WORK/packages/$package" checkout -q -B main HEAD
done
python3 - "$APP/composer.json" "$WORK/packages" $PACKAGES <<'PY'
import json, sys
path, packages = sys.argv[1], sys.argv[2]
composer = json.load(open(path))
composer["repositories"] = [{"type": "path", "url": packages + "/" + name, "options": {"symlink": False}} for name in sys.argv[3:]]
for name in sys.argv[3:]:
    composer["require"]["larena/" + name] = "dev-main"
json.dump(composer, open(path, "w"), indent=4)
PY

echo "== composer" >>"$LOG"
(cd "$APP" && rm -f composer.lock && composer update --no-dev --no-interaction --no-progress --no-scripts) >>"$LOG" 2>&1 || failed "composer"
python3 - "$ENV_REPO/assembly/build.json" "$APP/vendor/composer/installed.json" <<'PY' || failed "package list"
import json, sys
expected = sorted("larena/" + p for p in json.load(open(sys.argv[1]))["packages"])
installed = json.load(open(sys.argv[2]))
names = sorted(p["name"] for p in (installed["packages"] if isinstance(installed, dict) else installed) if p["name"].startswith("larena/"))
if names != expected:
    print("installed", names, "expected", expected, file=sys.stderr)
    sys.exit(1)
PY

TOKEN="check-$(python3 -c 'import secrets; print(secrets.token_hex(12))')"
touch "$APP/database/database.sqlite"
cat >"$APP/.env" <<ENV
APP_ENV=local
APP_DEBUG=false
APP_URL=http://127.0.0.1:${PORT}
DB_CONNECTION=sqlite
DB_DATABASE=${APP}/database/database.sqlite
LARENA_ENV_GATEWAY=recorded
SIMAI_PANEL_SETUP_TOKEN=${TOKEN}
ENV
echo "== migrate" >>"$LOG"
(cd "$APP" && php artisan package:discover && php artisan migrate --force && php artisan migrate --force && php artisan larena:env:area) >>"$LOG" 2>&1 || failed "migrate"

echo "== routes" >>"$LOG"
(cd "$APP" && php artisan route:list --json) >"$WORK/routes.json" 2>>"$LOG" || failed "route list"
python3 - "$WORK/routes.json" <<'PY' || failed "routes"
import json, sys
uris = {r["uri"] for r in json.load(open(sys.argv[1]))}
off = [u for u in uris if u.startswith(("admin/groups", "admin/settings/sites", "admin/settings/catalog", "admin/settings/changes",
    "admin/settings/history", "admin/settings/sitepack", "admin/roles/port", "admin/site/pages", "admin/ui"))]
missing = [u for u in ("admin/server", "admin/server/overview", "admin/settings/general", "admin/settings/backgrounds", "admin/users", "admin/audit") if u not in uris]
if off or missing:
    print("routes of features left off:", sorted(off), "missing screens:", missing, file=sys.stderr)
    sys.exit(1)
PY

echo "== sign-in and menu" >>"$LOG"
(cd "$APP/public" && exec php -S "127.0.0.1:${PORT}" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php) >>"$LOG" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 40); do curl -sf -o /dev/null "http://127.0.0.1:${PORT}/up" && break; sleep 0.25; done
STAFF_PASSWORD="Staff-$(python3 -c 'import secrets; print(secrets.token_hex(8))')!"
cat >"$WORK/make-staff.php" <<'PHP'
<?php
// A person at the panel's user level: an identity and the editor role, as the users' screen gives them.
chdir(getenv('PANEL_APP'));
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$id = $app->make('db')->table('larena_admin_identities')->insertGetId(['email' => 'staff@example.test', 'display_name' => 'Staff',
    'password_hash' => Illuminate\Support\Facades\Hash::make($argv[1]), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
$app->make(Larena\Access\Runtime\RoleAssignmentService::class)->assign('user:admin_identity:'.$id, 'editor', 'user:admin_identity:1');
PHP
export PANEL_APP="$APP"
python3 - "http://127.0.0.1:${PORT}" "$TOKEN" "$WORK" "$STAFF_PASSWORD" "$APP" <<'PY' >>"$LOG" 2>&1 || failed "sign-in and menu"
import http.cookiejar, json, re, secrets, sys, urllib.error, urllib.parse, urllib.request
base, token = sys.argv[1], sys.argv[2]
jar = http.cookiejar.CookieJar()
web = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
def get(path):
    with web.open(base + path) as answer:
        return answer.status, answer.read().decode()
def form_token(html):
    return re.search(r'name="_token" value="([^"]+)"', html).group(1)
def post(path, fields, headers=None):
    request = urllib.request.Request(base + path, data=urllib.parse.urlencode(fields).encode(), headers=headers or {})
    with web.open(request) as answer:
        return answer.status, answer.read().decode()
_, setup = get("/?setup=" + token)
email, password = "check@example.test", "Check-" + secrets.token_hex(8) + "!"
post("/admin/setup", {"_token": form_token(setup), "display_name": "Check", "email": email, "password": password,
    "password_confirmation": password, "site_locale": "en", "site_timezone": "UTC"})
_, login = get("/admin/login")
_, home = post("/admin/login", {"_token": form_token(login), "email": email, "password": password})
csrf = re.search(r'<meta name="csrf-token" content="([^"]+)"', home).group(1)
request = urllib.request.Request(base + "/admin/navigation/menu", method="POST",
    data=json.dumps({"intent": "navigation.load", "payload": {"reason": "connected", "site": None}, "page": "/admin/server"}).encode(),
    headers={"Content-Type": "application/json", "Accept": "application/json", "X-CSRF-TOKEN": csrf})
with web.open(request) as answer:
    menu = json.loads(answer.read().decode())
items = menu.get("navigation", menu).get("items", [])
roots = [item["key"] for item in items]
print("menu roots:", roots)
assert roots == ["sites", "journal", "users", "server", "settings"], roots
children = {item["key"]: [row["key"] for row in item.get("children", []) if row.get("kind") == "page"] for item in items}
assert sorted(children["server"]) == ["auth.diagnostics", "env.dns", "env.server.overview", "env.update"], children
assert sorted(children["users"]) == ["auth.users", "env.people"], children
assert sorted(children["settings"]) == ["admin.settings_general", "admin.site_backgrounds", "env.alerts.channel", "env.dns.connection"], children
assert "site-switch" not in json.dumps(menu.get("capabilities", [])), "the panel has no site switcher"
hrefs = [item["href"] for item in items if item.get("href")] + [row["href"] for item in items for row in item.get("children", []) if row.get("href")]
for href in hrefs:
    status, _ = get(href)
    assert status == 200, (href, status)
print("menu rows open:", hrefs)

# The breadcrumbs name the page's own place: Users › Spaces and Server › Overview, never the package's «Sites».
def crumbs(html):
    tag = re.search(r'<sf-breadcrumbs\b[^>]*>', html)
    assert tag, "breadcrumbs are drawn"
    found = re.search(r'\sitems="([^"]*)"', tag.group(0))
    assert found, "breadcrumbs carry their items"
    return [item.get("label") for item in json.loads(html_lib.unescape(found.group(1)))]
import html as html_lib
for path, expected in (("/admin/server/people", ["Users", "Spaces"]), ("/admin/server/overview", ["Server", "Overview"])):
    _, page = get(path)
    labels = crumbs(page)
    assert labels[1:] == expected, (path, labels)
print("breadcrumbs:", "ok")

# Peace of mind (Panel plan, stage 4): the check tells the administrator; the sites say what needs attention, the
# overview draws the load, and the notification centre has them. simai-env 1.31.0 keeps its own alerts and load
# history: the check copies the alerts and measures nothing itself. The recording holds one open alert, demo-owned.test's.
import subprocess
check = subprocess.run(["php", "artisan", "larena:env-check"], cwd=sys.argv[5], check=True, capture_output=True, text=True).stdout
print("check:", check.strip())
assert "from the server: yes" in check and "measured: no" in check and "notifications: 0" not in check, check
_, listed = get("/admin/server")
assert "data-env-warnings-from-server" in listed and 'data-env-warning="backup_failed"' in listed and 'data-env-warning-site="demo-owned.test"' in listed, "the sites say the server's alerts"
assert 'data-env-alert-ack="9147a2749fff"' in listed and 'data-env-alert-state="open"' in listed, "an open alert can be acknowledged"
_, acknowledge = post("/admin/server/alerts/ack/plan", {"_token": form_token(listed), "id": "9147a2749fff", "back": "/admin/server"})
assert 'data-env-plan="self:alerts-ack"' in acknowledge and 'name="id" value="9147a2749fff"' in acknowledge, "acknowledging an alert is planned"
_, overview = get("/admin/server/overview")
assert "data-env-history-from-server" in overview and 'data-env-history-chart="day"' in overview and "data-env-warnings=" in overview, "the overview draws the server's own history"
_, card = get("/admin/server/sites/demo-owned.test")
assert 'data-env-alert="9147a2749fff"' in card, "the card says its own"
_, card = get("/admin/server/sites/demo-php.test")
assert "data-env-tabs" in card and "data-env-alert=" not in card, "another site's card has no alert of it"
request = urllib.request.Request(base + "/admin/navigation/menu", method="POST",
    data=json.dumps({"intent": "navigation.load", "payload": {"reason": "connected", "site": None}, "page": "/admin/server"}).encode(),
    headers={"Content-Type": "application/json", "Accept": "application/json", "X-CSRF-TOKEN": csrf})
with web.open(request) as answer:
    told = json.loads(answer.read().decode())
unread = (told.get("navigation", told).get("notifications") or told.get("notifications") or {}).get("unread", 0)
assert unread >= 1, ("the administrator's notifications", unread)
status, _ = get("/admin/notifications")
assert status == 200, ("the notification centre opens", status)
_, backups = get("/admin/server/sites/demo-php.test?tab=backups")
form = re.search(r'data-env-operation="backup-restore-database".*?</form>', backups, re.S).group(0)
name = re.search(r'<option value="([^"]+)"', form).group(1)
_, restore = post("/admin/server/sites/demo-php.test/plan", {"_token": form_token(backups), "operation": "backup-restore-database", "backup": name})
assert "data-env-plan-replaces" in restore, "a restore says what it replaces"
# simai-env 1.30.0: each scheduled copy is checked, and the server's last check is said.
assert "data-env-verify-field" in backups and 'data-env-last-verify="ok"' in backups, "the schedule checks each copy, the last check is said"
assert "2026-01-01 00:00 UTC" in backups, "the card reads its times as a person does"
# Where the server sends its alerts (simai-env 1.31.0): the channel now, a change by plan, a test.
status, channel = get("/admin/server/alerts/channel")
assert status == 200 and 'data-env-alerts-channel="off"' in channel and "data-env-alerts-form" in channel and "data-env-alerts-test" in channel, "the alerts' channel"
_, hooked = post("/admin/server/alerts/channel/plan", {"_token": form_token(channel), "kind": "webhook", "target": "https://hooks.example.com/simai"})
assert 'data-env-plan="self:alerts-channel"' in hooked and 'name="target" value="https://hooks.example.com/simai"' in hooked, "a webhook is planned"
_, tested = post("/admin/server/alerts/test/plan", {"_token": form_token(channel)})
assert 'data-env-plan="self:alerts-test"' in tested, "a test is planned"
# A safe update (simai-env 1.32.0): simai-env has one (pinned to 9.9.0 in the set), the panel on the stand was not
# installed by simai-env; the overview hints at it; the update is planned with what it changes.
status, update = get("/admin/server/update")
assert status == 200 and re.search(r'data-env-update="env"\s+data-env-update-state="available"', update) and "9.9.0" in update, "simai-env's update"
assert re.search(r'data-env-update="panel"\s+data-env-update-state="not_installed"', update) and "was not installed through simai-env" in update, "the panel says why not"
assert 'data-env-update-plan="env"' in update and 'data-env-update-plan="panel"' not in update, "only simai-env is offered"
_, overview = get("/admin/server/overview")
assert 'data-env-update-hint-target="env"' in overview, "the overview hints at the update"
_, updating = post("/admin/server/update/plan", {"_token": form_token(update), "target": "env"})
assert 'data-env-plan="self:update"' in updating and 'data-env-update-facts="env"' in updating and 'name="target" value="env"' in updating, "the update is planned"
print("stage 4:", "ok", unread, "unread")

# The second level: a person at the user level (the editor role), made the way an administrator's tooling would.
import subprocess
subprocess.run(["php", sys.argv[3] + "/make-staff.php", sys.argv[4]], check=True)
def hidden(html, name):
    return re.search(r'name="' + re.escape(name) + r'" value="([^"]+)"', html).group(1)
status, people = get("/admin/server/people")
assert status == 200 and 'data-env-person="user:admin_identity:2"' in people, "the person of the user level is listed"
staff = "user:admin_identity:2"
post("/admin/server/people/assign", {"_token": form_token(people), "subject": staff, "owner": "demo"})
_, people = get("/admin/server/people")
assert re.search(r'<option value="demo"\s+selected', people), "the person's space is saved"
post("/admin/server/people/act", {"_token": form_token(people), "subject": staff})
_, acting = get("/admin/server")
assert 'data-env-acting="demo"' in acting and "demo-owned.test" in acting and "demo-php.test" not in acting, "looking as the person: only their space"
post("/admin/server/people/stop", {"_token": form_token(acting)})
_, whole = get("/admin/server")
assert 'data-env-acting' not in whole and "demo-php.test" in whole, "back to the whole server"
status, wizard = get("/admin/server/sites/new")
assert status == 200 and "data-env-new-owner" in wizard, "the wizard, with whose the site is"
assert re.search(r'name="daily-backup" value="yes" data-env-new-daily-backup\s+checked', wizard), "a new site gets its daily backup by default"
for kind in ("static", "generic", "laravel", "wordpress", "node", "redirect"):
    assert 'data-env-profile-hint="' + kind + '"' in wizard, ("each kind says what it is", kind)
assert 'data-env-new-profile="static"' in wizard and 'data-env-field=' not in wizard, "a static site asks for no PHP, database or target"
_, laravel = get("/admin/server/sites/new?profile=laravel")
assert 'data-env-new-profile="laravel"' in laravel and all('data-env-field="' + field + '"' in laravel for field in ("php", "database", "engine")) and 'data-env-field="redirect"' not in laravel, "Laravel asks for PHP and a database"
_, redirect = get("/admin/server/sites/new?profile=redirect")
assert 'data-env-field="redirect"' in redirect and 'data-env-field="php"' not in redirect, "a redirect asks only where to"
_, planned = post("/admin/server/sites/new/plan", {"_token": form_token(wizard), "domain": "new.test", "profile": "static", "daily-backup": "yes"})
# simai-env 1.30.0 makes the daily backup with the site: no backup:data-schedule after it.
assert 'data-env-plan="site:add"' in planned and 'name="backup" value="yes"' in planned and "data-env-plan-then" not in planned, "a new site is planned with its daily backup"
_, made = post("/admin/server/sites/new/run", {"_token": form_token(planned), "domain": "new.test", "profile": "static", "daily-backup": "yes", "backup": "yes"})
assert "daily backup is being turned on" not in made, "the daily backup came with the site, nothing is turned on after"
for tab in ("overview", "domains", "dns", "php", "database", "backups", "files", "releases", "log", "access", "nginx"):
    status, card = get("/admin/server/sites/demo-php.test?tab=" + tab)
    assert status == 200 and 'data-env-tab="' + tab + '"' in card, ("tab", tab)
    # SFTP is by key only (simai-env 1.30.0): no password field on any tab.
    assert 'type="password"' not in card and 'name="password"' not in card, ("no password", tab)
    # The recorded server has no file commands (no simai-env release has them yet): said, with no dead buttons.
    if tab == "files":
        assert 'data-env-files="unsupported"' in card and "data-env-files-op" not in card, "no file commands: said, nothing offered"
    if tab == "nginx":
        assert 'data-env-nginx="unsupported"' in card and "data-env-nginx-op" not in card, "no nginx directives: said, nothing offered"
    if tab == "access":
        assert 'data-env-operation="sftp-create"' in card and "data-env-sftp-uploads" in card, "a new SFTP access by key, and the note on uploads"
        assert 'data-env-operation="sftp-disable"' not in card, "a site without accounts has none to turn off"
    else:
        assert 'data-env-operation="sftp-create"' not in card, ("SFTP on its own tab", tab)
    # Its own tab offers what 1.28.0 does: restoring the database, unpacking files, php.ini, a new database password.
    for shownAction, onTab in (("backup-restore-database", "backups"), ("backup-extract-files", "backups"), ("php-setting", "php"), ("database-password", "database")):
        if tab == onTab:
            assert 'data-env-operation="' + shownAction + '"' in card, ("offered", shownAction)
# DNS through the provider (stage 7): with no token the screens say how to connect; nothing calls out.
_, zones = get("/admin/server/dns")
assert 'data-env-dns-zones="not_connected"' in zones and "/admin/server/dns/connection" in zones, "the DNS screen says how to connect"
_, connection = get("/admin/server/dns/connection")
assert 'data-env-dns-connection="none"' in connection and 'name="token"' in connection, "the connection asks for a token"
_, dns_tab = get("/admin/server/sites/demo-php.test?tab=dns")
assert 'data-env-dns="not_connected"' in dns_tab and "/admin/server/dns/connection" in dns_tab, "the site's DNS tab says how to connect"
print("dns:", "ok")
# Files (Panel plan, stage 6) on a stand-in for the file commands, which no recording has yet: the server lists them now.
import os
fake = sys.argv[3] + "/fake-files"
with open(sys.argv[5] + "/.env", "a") as dotenv:
    dotenv.write("LARENA_ENV_RECORDED_FILES=" + fake + "\n")
def confirm_fields(html):
    form = re.search(r'<form method="post" action="([^"]+)" data-env-confirm="[^"]*">(.*?)</form>', html, re.S)
    assert form, "a plan with its confirmation"
    fields = {html_lib.unescape(n): html_lib.unescape(v) for n, v in re.findall(r'<input type="hidden" name="([^"]+)" value="([^"]*)">', form.group(2))}
    return html_lib.unescape(form.group(1)), fields
def plan_and_run(path, fields):
    _, planned = post(path, fields)
    action, hidden = confirm_fields(planned)
    hidden["_token"] = form_token(planned)
    _, done = post(action, hidden)
    return planned, done
_, files = get("/admin/server/sites/demo-php.test?tab=files")
assert 'data-env-files="on"' in files and 'data-env-file="public"' in files and 'data-env-file="notes.txt"' in files, "a site's folder"
assert 'data-env-files-op="upload"' in files and 'data-env-files-op="mkdir"' in files and "data-env-files-trash-link" in files, "its changes and the trash"
_, opened = get("/admin/server/sites/demo-php.test?tab=files&view=notes.txt")
assert "data-env-files-editor" in opened and "Notes of demo-php.test" in opened, "a text file opens in the editor"
planned, done = plan_and_run("/admin/server/sites/demo-php.test/files/plan", {"_token": form_token(opened), "op": "write", "path": "notes.txt", "content": "Saved from the panel\r\n"})
assert 'data-env-plan="fs:write"' in planned and "data-env-plan-replaces" in planned, "a save is planned and says what it replaces"
assert 'data-env-job-state="succeeded"' in done, "the save runs as a job"
_, reopened = get("/admin/server/sites/demo-php.test?tab=files&view=notes.txt")
assert "Saved from the panel\n</textarea>" in reopened, "the file holds the saved text, with the server's line ends"
_, trash = get("/admin/server/sites/demo-php.test?tab=files&trash=1")
assert re.search(r'data-env-trash-item="[^"]+"', trash) and "notes.txt.bak-" in trash, "the previous version is in the trash"
planned, done = plan_and_run("/admin/server/sites/demo-php.test/files/plan", {"_token": form_token(files), "op": "delete", "path": "notes.txt", "folder": "."})
assert 'data-env-plan="fs:delete"' in planned and 'data-env-job-state="succeeded"' in done, "deleting goes to the trash by plan"
_, after = get("/admin/server/sites/demo-php.test?tab=files")
assert 'data-env-file="notes.txt"' not in after, "deleted from the folder"
_, trash = get("/admin/server/sites/demo-php.test?tab=files&trash=1")
item = re.search(r'<li data-env-trash-item="([^"]+)"[^>]*>\s*<span>notes\.txt[ ·<]', trash)
assert item, "the deleted file is in the trash"
planned, done = plan_and_run("/admin/server/sites/demo-php.test/files/plan", {"_token": form_token(trash), "op": "restore", "id": item.group(1)})
assert 'data-env-plan="fs:restore"' in planned and 'data-env-job-state="succeeded"' in done, "restored by plan"
_, back = get("/admin/server/sites/demo-php.test?tab=files")
assert 'data-env-file="notes.txt"' in back, "back where it was"
with web.open(base + "/admin/server/sites/demo-php.test/files/download?path=notes.txt") as answer:
    assert answer.read() == b"Saved from the panel\n" and "attachment" in answer.headers.get("Content-Disposition", ""), "a download, checked and handed over"
os.symlink("/etc", fake + "/sites/demo-php.test/escape")
_, outside = get("/admin/server/sites/demo-php.test?tab=files&path=escape")
assert "data-env-files-problem" in outside and "outside the site" in outside, "a link out of the site is refused in words"
_, nginx = get("/admin/server/sites/demo-php.test?tab=nginx")
assert 'data-env-nginx="on"' in nginx and "data-env-nginx-allowlist" in nginx, "the nginx directives, with what is allowed"
planned, done = plan_and_run("/admin/server/sites/demo-php.test/nginx/plan", {"_token": form_token(nginx), "op": "overlay-set", "content": "add_header X-Check 1;\n"})
assert 'data-env-plan="site:nginx-overlay-set"' in planned and "data-env-plan-replaces" in planned and 'data-env-job-state="succeeded"' in done, "directives applied by plan"
_, pending = get("/admin/server/sites/demo-php.test?tab=nginx")
change = re.search(r'data-env-nginx-pending="([^"]+)"', pending)
assert change and 'name="op" value="overlay-confirm"' in pending, "applied, waiting to be kept"
planned, done = plan_and_run("/admin/server/sites/demo-php.test/nginx/plan", {"_token": form_token(pending), "op": "overlay-confirm", "change": change.group(1)})
assert 'data-env-job-state="succeeded"' in done, "kept once confirmed"
_, kept = get("/admin/server/sites/demo-php.test?tab=nginx")
assert "data-env-nginx-pending" not in kept and "add_header X-Check 1;" in kept, "the change is kept"
print("files:", "ok")
_, sftp = get("/admin/server/sites/demo-owned.test?tab=access")
assert 'data-env-sftp="demo-staff"' in sftp and 'data-env-sftp-path="/site"' in sftp, "the site's own SFTP access, where it lands"
for shownAction in ("sftp-add-key", "sftp-disable", "sftp-remove"):
    assert 'data-env-operation="' + shownAction + '"' in sftp, ("offered", shownAction)
assert 'data-env-operation="sftp-enable"' not in sftp, "an access that is on is not turned on"
_, off = post("/admin/server/sites/demo-owned.test/plan", {"_token": form_token(sftp), "operation": "sftp-disable", "login": "demo-staff"})
assert 'data-env-plan="access:disable"' in off, "turning an access off is planned"
print("sftp:", "ok")
_, logged = get("/admin/server/sites/demo-static.test?tab=log&q=curl&group=2xx&lines=500")
assert "data-env-log-rows" in logged and 'data-env-log-row="200"' in logged, "the log narrows"
assert "larena-table-stack" in logged and 'data-label="' in logged, "the log reads as cards on a phone"
_, overview = get("/admin/server")
assert "HTTP 200" in overview, "a site that answers says so"
_, spaces = get("/admin/server/people")
assert 'data-env-owner="demo"' in spaces and 'name="action" value="block"' in spaces, "a space can be blocked on a server that has it (1.27.0)"

# The person signs in: their space only, no system screen.
jar.clear()
_, login = get("/admin/login")
_, home = post("/admin/login", {"_token": form_token(login), "email": "staff@example.test", "password": sys.argv[4]})
status, sites = get("/admin/server")
assert status == 200 and "demo-owned.test" in sites and "demo-php.test" not in sites, "the person sees their own owner's sites"
status, other = get("/admin/server/sites/demo-php.test")
assert "another owner" in other, "another owner's site is refused by the server"
for path in ("/admin/server/people", "/admin/server/overview", "/admin/server/dns", "/admin/server/dns/connection", "/admin/server/alerts/channel",
        "/admin/server/update"):
    try:
        status, _ = get(path)
    except urllib.error.HTTPError as error:
        status = error.code
    assert status == 403, (path, status)
csrf = re.search(r'<meta name="csrf-token" content="([^"]+)"', sites).group(1)
request = urllib.request.Request(base + "/admin/navigation/menu", method="POST",
    data=json.dumps({"intent": "navigation.load", "payload": {"reason": "connected", "site": None}, "page": "/admin/server"}).encode(),
    headers={"Content-Type": "application/json", "Accept": "application/json", "X-CSRF-TOKEN": csrf})
with web.open(request) as answer:
    own_menu = json.loads(answer.read().decode())
own_roots = [item["key"] for item in own_menu.get("navigation", own_menu).get("items", [])]
print("user level menu roots:", own_roots)
assert "sites" in own_roots and "users" not in own_roots and "server" not in own_roots and "journal" not in own_roots, own_roots
status, _ = get("/admin/notifications")
assert status == 200, ("the person's notification centre opens", status)
assert 'data-env-warning-site="demo-php.test"' not in sites and 'data-env-warning="disk_full"' not in sites, "the person is warned about their own sites only"
assert 'data-env-alert="9147a2749fff"' in sites and len(re.findall(r'data-env-alert="', sites)) == 1, "the person sees their own site's alert, and only that"
_, acknowledge = post("/admin/server/alerts/ack/plan", {"_token": form_token(sites), "id": "9147a2749fff", "back": "/admin/server"})
assert 'data-env-plan="self:alerts-ack"' in acknowledge, "the person acknowledges their own site's alert"
try:
    status, _ = post("/admin/server/alerts/channel/plan", {"_token": form_token(sites), "kind": "off"})
except urllib.error.HTTPError as error:
    status = error.code
assert status == 403, ("the channel is the system level's", status)
try:
    status, _ = post("/admin/server/update/plan", {"_token": form_token(sites), "target": "env"})
except urllib.error.HTTPError as error:
    status = error.code
assert status == 403, ("an update is the system level's", status)
status, own_sftp = get("/admin/server/sites/demo-owned.test?tab=access")
assert status == 200 and 'data-env-sftp="demo-staff"' in own_sftp and 'data-env-operation="sftp-add-key"' in own_sftp, "the person manages their own site's SFTP"
status, own_files = get("/admin/server/sites/demo-owned.test?tab=files")
assert status == 200 and 'data-env-files="on"' in own_files and 'data-env-file="public"' in own_files, "the person's own site's files"
_, others = get("/admin/server/sites/demo-php.test?tab=files")
assert 'data-env-files="on"' not in others, "another owner's files are not shown"
_, own_nginx = get("/admin/server/sites/demo-owned.test?tab=nginx")
assert 'data-env-tab="nginx"' not in own_nginx and "data-env-nginx" not in own_nginx, "the nginx directives are the system level's"
try:
    status, _ = post("/admin/server/sites/demo-owned.test/nginx/plan", {"_token": form_token(own_files), "op": "overlay-clear"})
except urllib.error.HTTPError as error:
    status = error.code
assert status == 403, ("the person cannot plan nginx directives", status)
status, own_dns = get("/admin/server/sites/demo-owned.test?tab=dns")
assert status == 200 and 'data-env-dns="not_connected"' in own_dns and "/admin/server/dns/connection" not in own_dns, "the person sees their site's DNS tab, not the connection"
print("both levels checked")
PY
python3 - "$APP/database/database.sqlite" <<'PY' >>"$LOG" 2>&1 || failed "audit"
import sqlite3, sys
types = {row[0] for row in sqlite3.connect(sys.argv[1]).execute("select event_type from larena_audit_events where event_type like 'env.%'")}
assert {"env.person.owner_assigned", "env.acting_as.started", "env.acting_as.stopped"} <= types, types
print("audit:", sorted(types))
PY
kill "$SERVER_PID" 2>/dev/null || true
wait "$SERVER_PID" 2>/dev/null || true
SERVER_PID=""

echo "panel install passed: $(git -C "$ENV_REPO" rev-parse --short=12 HEAD)"
rm -rf "$WORK"
