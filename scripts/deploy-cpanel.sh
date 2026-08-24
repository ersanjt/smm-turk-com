#!/bin/bash
# Copy to /home/smmturk/deploy-smm.sh — see docs/AUTO-DEPLOY.md
set -e

CPANEL_USER="${CPANEL_USER:-smmturk}"
REPO_DIR="${REPO_DIR:-/home/${CPANEL_USER}/repositories/smm-turk-panel}"
WEB_DIR="${WEB_DIR:-/home/${CPANEL_USER}/public_html}"
GITHUB_REPO="https://github.com/ersanjt/smm-turk-panel"
GITHUB_ARCHIVE="${GITHUB_REPO}/archive/refs/heads/main.tar.gz"
GITHUB_ARCHIVE_ALT="https://codeload.github.com/ersanjt/smm-turk-panel/tar.gz/refs/heads/main"

RSYNC_EXCLUDES=(
  --exclude='.git'
  --exclude='.cpanel.yml'
  --exclude='config.php'
  --exclude='deploy-secret.txt'
  --exclude='deploy-smm.sh'
  --exclude='deploy-cron.sh'
  --exclude='tmp/'
  --exclude='uploads/'
  --exclude='storage/'
  --exclude='node_modules'
)

rsync_to_web() {
  local src="$1"
  local child_excludes=()

  # Protect child panel document roots from rsync --delete (they live under public_html/DOMAIN/)
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

  rsync -av --delete "${RSYNC_EXCLUDES[@]}" "${child_excludes[@]}" --chmod=D755,F644 "$src" "$WEB_DIR/"
}

git_updated=0
archive_ok=0

# Primary: GitHub archive (always matches public main — no git credentials)
tmp_dir=$(mktemp -d)
trap 'rm -rf "$tmp_dir"' EXIT

if curl -fsSL "$GITHUB_ARCHIVE" -o "$tmp_dir/main.tar.gz" 2>/dev/null \
   || curl -fsSL "$GITHUB_ARCHIVE_ALT" -o "$tmp_dir/main.tar.gz"; then
  tar -xzf "$tmp_dir/main.tar.gz" -C "$tmp_dir"
  extract_dir="$tmp_dir/smm-turk-panel-main"
  if [ ! -d "$extract_dir" ]; then
    extract_dir=$(find "$tmp_dir" -mindepth 1 -maxdepth 1 -type d | head -1)
  fi
  if [ -d "$extract_dir" ]; then
    rsync_to_web "$extract_dir/"
    echo "Deployed from GitHub archive (main branch)."
    archive_ok=1
    git_updated=1
  fi
fi

if [ "$archive_ok" -eq 0 ] && [ -d "$REPO_DIR/.git" ]; then
  cd "$REPO_DIR"
  git config --global --add safe.directory "$REPO_DIR" 2>/dev/null || true

  if git remote get-url origin 2>/dev/null | grep -q 'git@github.com'; then
    git remote set-url origin "${GITHUB_REPO}.git"
  fi

  # No credential prompt — works for public repos without login
  if GIT_TERMINAL_PROMPT=0 git -c credential.helper= fetch origin main 2>/dev/null; then
    git reset --hard origin/main
    echo "Git updated: $(git log -1 --oneline)"
    rsync_to_web "$REPO_DIR/"
    git_updated=1
  else
    echo "WARN: git fetch failed (repo private or cached bad credentials)."
  fi
elif [ "$archive_ok" -eq 0 ]; then
  echo "WARN: Git repo not found at $REPO_DIR"
fi

# Fallback: download public archive without git auth (if git also failed)
if [ "$git_updated" -eq 0 ]; then
  echo "WARN: GitHub archive and git fetch both failed."
  tmp_dir=$(mktemp -d)
  trap 'rm -rf "$tmp_dir"' EXIT

  if curl -fsSL "$GITHUB_ARCHIVE" -o "$tmp_dir/main.tar.gz" 2>/dev/null \
     || curl -fsSL "$GITHUB_ARCHIVE_ALT" -o "$tmp_dir/main.tar.gz"; then
    tar -xzf "$tmp_dir/main.tar.gz" -C "$tmp_dir"
    extract_dir="$tmp_dir/smm-turk-panel-main"
    if [ ! -d "$extract_dir" ]; then
      extract_dir=$(find "$tmp_dir" -mindepth 1 -maxdepth 1 -type d | head -1)
    fi
    if [ -d "$extract_dir" ]; then
      rsync_to_web "$extract_dir/"
      echo "Deployed from GitHub archive (main branch)."
    else
      echo "Error: archive extracted but folder not found."
      exit 1
    fi
  else
    echo "Error: could not download $GITHUB_ARCHIVE"
    echo "  - If repo is private: GitHub → Settings → General → Change visibility → Public"
    echo "  - Or: cPanel → Git Version Control → Pull or Deploy"
    echo "  - Or: create a GitHub PAT and run:"
    echo "      git -c credential.helper= fetch https://TOKEN@github.com/ersanjt/smm-turk-panel.git main"
    exit 1
  fi
fi

echo "Deploy done: $(date -Iseconds)"

if command -v php >/dev/null 2>&1; then
  php -r "if (function_exists('opcache_reset')) { opcache_reset(); echo 'OPcache cleared\n'; }" 2>/dev/null || true
fi

if [ -f "$WEB_DIR/migrate-db.php" ]; then
  echo "Running DB migration..."
  php "$WEB_DIR/migrate-db.php" || echo "WARN: migrate-db.php failed (run manually: php $WEB_DIR/migrate-db.php)"
fi
