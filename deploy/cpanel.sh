#!/usr/bin/env bash
set -euo pipefail

# cPanel calls this script from the checked-out repository. Only committed
# application files are published; live configuration and uploads stay in place.
ORACLE_REPOSITORY="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
ORACLE_MODE="${1:-deploy}"

case "$ORACLE_MODE" in
  deploy|--check) ;;
  *) printf 'Usage: bash deploy/cpanel.sh [--check]\n' >&2; exit 1 ;;
esac

if [[ -x /usr/local/cpanel/3rdparty/bin/git ]]; then
  ORACLE_GIT=/usr/local/cpanel/3rdparty/bin/git
else
  ORACLE_GIT="$(command -v git || true)"
fi
cd -- "$ORACLE_REPOSITORY"
if [[ -z "$ORACLE_GIT" ]]; then
  printf 'Git is required to prepare the deployment.\n' >&2
  exit 1
fi

ORACLE_PHP="${ORACLE_PHP_BIN:-php}"
if ! command -v "$ORACLE_PHP" >/dev/null 2>&1; then
  printf 'PHP CLI is required. Set ORACLE_PHP_BIN if the host uses a specific PHP binary.\n' >&2
  exit 1
fi

ORACLE_RUNTIME_PATHS=(
  administrator app assets auth coordinator director extension professor plugins
  index.php logout.php research_ai_search.php favicon.ico LICENSE.md
)
ORACLE_COMMIT="$("$ORACLE_GIT" rev-parse --short=12 HEAD)"
ORACLE_TEMP_ROOT="${TMPDIR:-/tmp}"
ORACLE_STAGE="$(mktemp -d "${ORACLE_TEMP_ROOT%/}/oracle-deploy.XXXXXXXX")"

oracle_cleanup() {
  # This is the temporary directory created above, never the website directory.
  case "$ORACLE_STAGE" in
    "${ORACLE_TEMP_ROOT%/}"/oracle-deploy.?*) rm -rf -- "$ORACLE_STAGE" ;;
  esac
}
trap oracle_cleanup EXIT

"$ORACLE_GIT" archive --format=tar HEAD -- "${ORACLE_RUNTIME_PATHS[@]}" \
  | tar -xf - -C "$ORACLE_STAGE"

for ORACLE_REQUIRED in index.php app/bootstrap.php assets/vendor/js/bootstrap.js; do
  if [[ ! -f "$ORACLE_STAGE/$ORACLE_REQUIRED" ]]; then
    printf 'The deployment is missing %s. Nothing was published.\n' "$ORACLE_REQUIRED" >&2
    exit 1
  fi
done

ORACLE_PHP_COUNT=0
while IFS= read -r -d '' ORACLE_FILE; do
  if ! ORACLE_LINT_OUTPUT="$("$ORACLE_PHP" -l "$ORACLE_FILE" 2>&1)"; then
    printf '%s\nNothing was published.\n' "$ORACLE_LINT_OUTPUT" >&2
    exit 1
  fi
  ORACLE_PHP_COUNT=$((ORACLE_PHP_COUNT + 1))
done < <(find "$ORACLE_STAGE" -type f -name '*.php' -print0)

printf 'Prepared commit %s; %s PHP files passed syntax checks.\n' "$ORACLE_COMMIT" "$ORACLE_PHP_COUNT"
if [[ "$ORACLE_MODE" == --check ]]; then
  printf 'Check complete. No live files were changed.\n'
  exit 0
fi

ORACLE_TARGET="${HOME:?The cPanel home directory is required}/public_html"
if [[ ! -d "$ORACLE_TARGET" || ! -f "$ORACLE_TARGET/.env" ]]; then
  printf 'The existing public_html directory and production .env must be present. Nothing was published.\n' >&2
  exit 1
fi
ORACLE_TARGET="$(cd -- "$ORACLE_TARGET" && pwd -P)"
case "$ORACLE_REPOSITORY/" in
  "$ORACLE_TARGET/"*)
    printf 'Keep the cPanel repository outside public_html. Nothing was published.\n' >&2
    exit 1 ;;
esac

if [[ -n "$("$ORACLE_GIT" status --porcelain)" ]]; then
  printf 'The cPanel repository has uncommitted changes. Nothing was published.\n' >&2
  exit 1
fi

# Preserve cPanel's PHP handler, redirects, and any other live .htaccess rules.
if [[ ! -e "$ORACLE_TARGET/.htaccess" ]]; then
  "$ORACLE_GIT" show HEAD:.htaccess > "$ORACLE_STAGE/.htaccess"
fi

ORACLE_BACKUP_ROOT="$HOME/oracle-deployment-backups"
mkdir -p -m 700 -- "$ORACLE_BACKUP_ROOT"
ORACLE_BACKUP="$(mktemp "$ORACLE_BACKUP_ROOT/$(date -u +%Y%m%dT%H%M%SZ)-$ORACLE_COMMIT.XXXXXX.tar.gz")"
ORACLE_BACKUP_PATHS=()
for ORACLE_PATH in "${ORACLE_RUNTIME_PATHS[@]}"; do
  if [[ -e "$ORACLE_TARGET/$ORACLE_PATH" ]]; then
    ORACLE_BACKUP_PATHS+=("$ORACLE_PATH")
  fi
done
if [[ ${#ORACLE_BACKUP_PATHS[@]} -gt 0 ]]; then
  tar -czf "$ORACLE_BACKUP" -C "$ORACLE_TARGET" -- "${ORACLE_BACKUP_PATHS[@]}"
fi

# The stage contains only the explicit paths from git archive, never .git,
# .env, uploads, SQL files, tests, or deployment tooling. No live files are deleted.
cp -R -- "$ORACLE_STAGE/." "$ORACLE_TARGET/"
mkdir -p -- "$ORACLE_TARGET/uploads/manuscript_abstracts"
printf 'Deployed %s to %s\nPrevious application files: %s\n' "$ORACLE_COMMIT" "$ORACLE_TARGET" "$ORACLE_BACKUP"
