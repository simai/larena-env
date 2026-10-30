#!/usr/bin/env bash
# Builds the Larena Panel release: larena-panel-<version>.tar.gz and its .sha256, for
# `simai-admin.sh panel install --archive <file> --sha256 <hash>`. The application is at the archive's root, with vendor/
# and the built Framework assets inside; the server needs neither composer nor Node.
#
#   scripts/build-panel-archive.sh [--version X] [--output DIR] [--allow-dirty]
#
# Build machine: php and composer on PATH, a checkout of simai/larena (LARENA_ROOT_CHECKOUT) for the asset scripts at the
# revision named in assembly/build.json. --allow-dirty takes larena/env from the working tree, for trying a change out;
# what it builds is marked in panel-build.json and is not a release.
set -Eeuo pipefail

ENV_REPO=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
ROOT_CHECKOUT="${LARENA_ROOT_CHECKOUT:-$(cd "${ENV_REPO}/../../.." && pwd)/larena}"
VERSION=""; OUTPUT="${ENV_REPO}/dist"; ALLOW_DIRTY=no
while [[ $# -gt 0 ]]; do
  case "$1" in
    --version) VERSION="$2"; shift 2 ;;
    --output) OUTPUT="$2"; shift 2 ;;
    --allow-dirty) ALLOW_DIRTY=yes; shift ;;
    *) echo "Unknown argument: $1" >&2; exit 2 ;;
  esac
done
field() { php -r 'echo json_decode(file_get_contents($argv[1]), true, 8, JSON_THROW_ON_ERROR)[$argv[2]];' "${ENV_REPO}/assembly/build.json" "$1"; }
[[ -n "$VERSION" ]] || VERSION=$(field version)
[[ "$VERSION" =~ ^[0-9A-Za-z.+-]+$ ]] || { echo "Bad version: ${VERSION}" >&2; exit 2; }
ROOT_REVISION=$(field root_revision)
ENV_COMMIT=$(git -C "$ENV_REPO" rev-parse HEAD)
DIRTY=no
if [[ -n "$(git -C "$ENV_REPO" status --porcelain)" ]]; then
  [[ "$ALLOW_DIRTY" == yes ]] || { echo "larena/env has uncommitted changes; commit them or give --allow-dirty" >&2; exit 1; }
  DIRTY=yes
fi

WORK=$(mktemp -d "${TMPDIR:-/tmp}/larena-panel-build.XXXXXX")
trap 'rm -rf "$WORK"' EXIT
APP="${WORK}/app"; ENV_SOURCE="${WORK}/env-source"; TOOLS="${WORK}/tools"
mkdir -p "$APP" "$ENV_SOURCE" "$TOOLS" "$OUTPUT"

# The application, and larena/env as composer's path repository (the assembly names it ../env-source).
ENV_PATHS=(src config resources routes composer.json module.yaml access.yaml audit.yaml LICENSE NOTICE)
(cd "${ENV_REPO}/assembly" && tar --exclude=./vendor --exclude=./.env -cf - .) | tar -xf - -C "$APP"
if [[ "$DIRTY" == yes ]]; then
  (cd "$ENV_REPO" && tar -cf - "${ENV_PATHS[@]}") | tar -xf - -C "$ENV_SOURCE"
else
  git -C "$ENV_REPO" archive HEAD -- "${ENV_PATHS[@]}" | tar -xf - -C "$ENV_SOURCE"
fi

echo "[panel] composer install (exact revisions from the lock)"
(cd "$APP" && composer install --no-dev --prefer-dist --optimize-autoloader --no-scripts --no-interaction --no-progress 2>&1 | grep -v '^Deprecation Notice' | tail -n 3)
if (cd "$APP" && composer validate --no-check-all --no-check-publish --no-interaction 2>&1) | grep -q "not up to date"; then echo "composer.lock does not match composer.json" >&2; exit 1; fi

# Nothing beyond the agreed list, and no pinned package asks for one that is not there.
php -r '
  $build = json_decode(file_get_contents($argv[1]), true, 8, JSON_THROW_ON_ERROR);
  $installed = json_decode(file_get_contents($argv[2]."/vendor/composer/installed.json"), true, 64, JSON_THROW_ON_ERROR)["packages"];
  $expected = array_map(static fn (string $name): string => "larena/".$name, $build["packages"]);
  $names = array_column($installed, "name");
  $larena = array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, "larena/")));
  sort($larena);
  if ($larena !== $expected) { fwrite(STDERR, "Packages differ from assembly/build.json: ".implode(" ", array_diff($larena, $expected) + array_diff($expected, $larena))."\n"); exit(1); }
  foreach ($larena as $name) {
      $own = json_decode(file_get_contents($argv[2]."/vendor/".$name."/composer.json"), true, 16, JSON_THROW_ON_ERROR);
      foreach (array_keys($own["require"] ?? []) as $required) {
          if (str_starts_with($required, "larena/") && !in_array($required, $larena, true)) { fwrite(STDERR, "{$name} at its pinned revision requires {$required}\n"); exit(1); }
      }
  }
  echo "[panel] ".count($larena)." larena packages, ".count($names)." packages in all\n";
' "${ENV_REPO}/assembly/build.json" "$APP"

echo "[panel] Framework assets (Root's scripts at ${ROOT_REVISION:0:12})"
git -C "$ROOT_CHECKOUT" archive "$ROOT_REVISION" -- scripts release/developer-alpha-manifest.json | tar -xf - -C "$TOOLS"
ln -s "${APP}/vendor" "${TOOLS}/vendor"
php "${TOOLS}/scripts/sync-runtime-assets.php" --entry="$APP" --release-manifest=release/developer-alpha-manifest.json --json >"${WORK}/assets.json"
BUNDLE=$(php -r 'echo json_decode(file_get_contents($argv[1]), true, 16, JSON_THROW_ON_ERROR)["runtime"]["bundle_id"];' "${WORK}/assets.json")

# storage/ is replaced by the shared one on the server, so the records that name the bundle travel in resources/.
mkdir -p "${APP}/resources/panel-release"
mv "${APP}/storage/app/larena/core-assets/sf-active.json" "${APP}/storage/app/larena/core-assets/sf-route-receipt.json" "${APP}/resources/panel-release/"

# The state of this build goes; the empty folders stay.
rm -rf "${APP}/storage/app/larena" "${APP}/.env"
find "${APP}/bootstrap/cache" "${APP}/storage/logs" "${APP}/storage/framework" -type f ! -name .gitignore -delete
rm -f "${APP}/build.json"

# What the release does not run: the packages' tests and project documents.
for package in "${APP}"/vendor/larena/*; do
  rm -rf "${package}/tests" "${package}/docs" "${package}/.github" "${package}/.githooks" "${package}/.larena" "${package}/tools"
done

php -r '
  echo json_encode([
      "schema" => "larena-panel-build/1",
      "version" => $argv[1],
      "env_commit" => $argv[2],
      "working_tree" => $argv[3] === "yes",
      "root_revision" => $argv[4],
      "frontend_bundle" => $argv[5],
      "built_at" => gmdate("Y-m-d\TH:i:s\Z"),
      "php_build" => PHP_VERSION,
  ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
' "$VERSION" "$ENV_COMMIT" "$DIRTY" "$ROOT_REVISION" "$BUNDLE" >"${APP}/panel-build.json"

# simai-env refuses special files and links that leave the release.
if [[ -n "$(find "$APP" -type l)" ]]; then
  echo "Symbolic links in the release:" >&2; find "$APP" -type l >&2; exit 1
fi
chmod -R u+rwX,go+rX "$APP"
chmod 0755 "${APP}/artisan"

ARCHIVE="${OUTPUT}/larena-panel-${VERSION}.tar.gz"
COPYFILE_DISABLE=1 tar --no-xattrs --no-mac-metadata --format=gnutar --uid 0 --gid 0 --uname root --gname root -czf "$ARCHIVE" -C "$APP" . 2>/dev/null \
  || COPYFILE_DISABLE=1 tar --format=gnutar --owner=0 --group=0 -czf "$ARCHIVE" -C "$APP" .
if command -v sha256sum >/dev/null; then HASH=$(sha256sum "$ARCHIVE" | cut -d' ' -f1); else HASH=$(shasum -a 256 "$ARCHIVE" | cut -d' ' -f1); fi
printf '%s  %s\n' "$HASH" "$(basename "$ARCHIVE")" >"${ARCHIVE}.sha256"
echo "[panel] ${ARCHIVE}"
echo "[panel] $(du -h "$ARCHIVE" | cut -f1) sha256 ${HASH}"
