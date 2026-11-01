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
#   5. the two levels (Panel plan, stage 2): the administrator gives a person of the user level a space (an owner of
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
python3 - "http://127.0.0.1:${PORT}" "$TOKEN" "$WORK" "$STAFF_PASSWORD" <<'PY' >>"$LOG" 2>&1 || failed "sign-in and menu"
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
assert sorted(children["server"]) == ["auth.diagnostics", "env.server.overview"], children
assert sorted(children["users"]) == ["auth.users", "env.people"], children
assert children["settings"] == ["admin.settings_general", "admin.site_backgrounds"], children
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
_, planned = post("/admin/server/sites/new/plan", {"_token": form_token(wizard), "domain": "new.test", "profile": "static"})
assert 'data-env-plan="site:add"' in planned, "a new site is planned"
for tab in ("overview", "domains", "php", "database", "backups", "releases", "log", "access"):
    status, card = get("/admin/server/sites/demo-php.test?tab=" + tab)
    assert status == 200 and 'data-env-tab="' + tab + '"' in card, ("tab", tab)
    # The recorded server (simai-env 1.28.0) lists its commands: SFTP is not among them, so it is never offered.
    assert 'data-env-operation="sftp-create"' not in card, ("not offered", "sftp-create")
    # Its own tab offers what 1.28.0 does: restoring the database, unpacking files, php.ini, a new database password.
    for shownAction, onTab in (("backup-restore-database", "backups"), ("backup-extract-files", "backups"), ("php-setting", "php"), ("database-password", "database")):
        if tab == onTab:
            assert 'data-env-operation="' + shownAction + '"' in card, ("offered", shownAction)
_, logged = get("/admin/server/sites/demo-static.test?tab=log&q=curl&group=2xx&lines=500")
assert "data-env-log-rows" in logged and 'data-env-log-row="200"' in logged, "the log narrows"
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
for path in ("/admin/server/people", "/admin/server/overview"):
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
