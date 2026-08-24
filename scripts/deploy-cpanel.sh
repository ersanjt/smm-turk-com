#!/bin/bash
# Safe deploy for smm-turk.com (private repo ersanjt/smm-turk-com)
# Copy to /home/smmturk/deploy-smm.sh if you use SSH/cron.
#
# SAFETY defaults:
# - No rsync --delete (protects uploads/, orphan files, child panels)
# - Never overwrites config.php / deploy-secret.txt
# - Does NOT auto-run migrate-db.php (set RUN_MIGRATE=1 explicitly after backup)
set -euo pipefail

CPANEL_USER="${CPANEL_USER:-smmturk}"
REPO_DIR="${REPO_DIR:-/home/${CPANEL_USER}/repositories/smm-turk-com}"
WEB_DIR="${WEB_DIR:-/home/${CPANEL_USER}/public_html}"
GITHUB_REPO="https://github.com/ersanjt/smm-turk-com"
# Opt-in only — never wipe live files by default
RSYNC_DELETE="${RSYNC_DELETE:-0}"
RUN_MIGRATE="${RUN_MIGRATE:-0}"

RSYNC_EXCLUDES=(
  --exclude='.git'
  --exclude='.git/'
  --exclude='.cpanel.yml'
  --exclude='.github/'
  --exclude='config.php'
  --exclude='deploy-secret.txt'
  --exclude='deploy-smm.sh'
  --exclude='deploy-cron.sh'
  --exclude='*.LOCAL_ONLY*'
  --exclude='*.bak'
  --exclude='*.zip'
  --exclude='tmp/'
  --exclude='uploads/'
  --exclude='storage/'
  --exclude='docs/'
  --exclude='SAFE-DEPLOY.md'
  --exclude='install.sql'
  --exclude='install-cpanel.sql'
  --exclude='install-db.php'
  --exclude='node_modules'
)

rsync_to_web() {
  local src="$1"
  local child_excludes=()
  local delete_flag=()

  if [ "$RSYNC_DELETE" = "1" ]; then
    echo "WARN: RSYNC_DELETE=1 — live files not in repo may be removed"
    delete_flag=(--delete)
  fi

  # Protect child panel document roots under public_html/DOMAIN/
  if [ -d "$WEB_DIR" ]; then
    for d in "$WEB_DIR"/*/; do
      [ -d "$d" ] || continue
      base=$(basename "$d")
      case "$base" in
        admin|api|app|assets|lang|layouts|migrations|partials|scripts|storage|uploads|docs|tmp) continue ;;
      esac
      if [ -f "${d}config.php" ]; then
        child_excludes+=(--exclude="${base}/")
        echo "Protecting child panel: $base"
      fi
    done
  fi

  rsync -av "${delete_flag[@]}" "${RSYNC_EXCLUDES[@]}" "${child_excludes[@]}" --chmod=D755,F644 "$src" "$WEB_DIR/"
}

if [ ! -d "$REPO_DIR/.git" ]; then
  echo "Error: git repo not found at $REPO_DIR"
  echo "  In cPanel → Git Version Control, clone:"
  echo "  $GITHUB_REPO.git  →  $REPO_DIR"
  echo "  (private: use GitHub username + Personal Access Token)"
  exit 1
fi

if [ ! -f "$WEB_DIR/config.php" ]; then
  echo "Error: $WEB_DIR/config.php missing — refuse to deploy (would break the site)."
  echo "  Restore config.php from backup first, then re-run."
  exit 1
fi

cd "$REPO_DIR"
git config --global --add safe.directory "$REPO_DIR" 2>/dev/null || true

if ! GIT_TERMINAL_PROMPT=0 git fetch origin main; then
  echo "Error: git fetch failed. For a private repo, configure credentials in cPanel Git or a deploy key."
  exit 1
fi

git reset --hard origin/main
echo "Git updated: $(git log -1 --oneline)"
rsync_to_web "$REPO_DIR/"

echo "Deploy done: $(date -Iseconds)"

if command -v php >/dev/null 2>&1; then
  php -r "if (function_exists('opcache_reset')) { opcache_reset(); echo 'OPcache cleared\n'; }" 2>/dev/null || true
fi

if [ "$RUN_MIGRATE" = "1" ]; then
  if [ -f "$WEB_DIR/migrate-db.php" ]; then
    echo "Running DB migration (RUN_MIGRATE=1)..."
    php "$WEB_DIR/migrate-db.php" || echo "WARN: migrate-db.php failed"
  fi
else
  echo "Skipped migrate-db.php (set RUN_MIGRATE=1 after a DB backup if needed)."
fi
