# Safe deploy rules for smm-turk.com

Goal: develop and update code **without** losing database data or breaking production.

**Canonical repo (private):** https://github.com/ersanjt/smm-turk-com  
**Live site:** https://smm-turk.com  
**cPanel user:** `smmturk` · home `/home/smmturk` · web `/home/smmturk/public_html`

## Never do on the live server

- Do **not** drop/truncate MySQL tables or re-run `install.sql` / `install-cpanel.sql` on production.
- Do **not** overwrite `config.php` on the server from git (it is gitignored).
- Do **not** use `rsync --delete` (wipes `uploads/`, orphan files, etc.).
- Do **not** leave `github.zip` (or any full source zip) inside `public_html`.

## WHM Terminal (recommended if you have root/WHM shell)

Clone lives **outside** `public_html`. Sync is **rsync without `--delete`**.  
`config.php`, `uploads/`, `storage/`, `tmp/` are never overwritten.

### One-time setup (run as root in WHM Terminal)

Replace `YOUR_GITHUB_TOKEN` with a GitHub PAT that has `repo` scope.

```bash
# 0) safety checks
test -f /home/smmturk/public_html/config.php || { echo "STOP: config.php missing"; exit 1; }
mkdir -p /home/smmturk/repositories /home/smmturk/backups

# 1) backup config + quick DB dump name hint (run real mysqldump in cPanel if preferred)
cp -a /home/smmturk/public_html/config.php /home/smmturk/backups/config.php.$(date +%Y%m%d%H%M%S)
rm -f /home/smmturk/public_html/github.zip

# 2) clone private repo (once)
if [ ! -d /home/smmturk/repositories/smm-turk-com/.git ]; then
  git clone https://YOUR_GITHUB_USER:YOUR_GITHUB_TOKEN@github.com/ersanjt/smm-turk-com.git \
    /home/smmturk/repositories/smm-turk-com
  chown -R smmturk:smmturk /home/smmturk/repositories/smm-turk-com
fi

# 3) first safe sync (no --delete, keeps config.php)
bash /home/smmturk/repositories/smm-turk-com/scripts/deploy-cpanel.sh
```

Store the token in a root-only file instead of pasting it every time (optional):

```bash
# /root/.smm-turk-git  (chmod 600)
# export GIT_ASKPASS or use:
git -C /home/smmturk/repositories/smm-turk-com remote set-url origin \
  https://YOUR_GITHUB_USER:YOUR_GITHUB_TOKEN@github.com/ersanjt/smm-turk-com.git
```

### Every update after we push to GitHub

```bash
bash /home/smmturk/repositories/smm-turk-com/scripts/deploy-cpanel.sh
```

That script: `git fetch` + `git reset --hard origin/main` in the clone folder, then rsync **into** `public_html` **without** deleting live files and **without** touching `config.php`.

### Verify after deploy

```bash
curl -sI https://smm-turk.com/ | head -5
curl -s https://smm-turk.com/health.php
test -f /home/smmturk/public_html/config.php && echo "config.php OK"
```

## One-time: connect via cPanel Git UI (alternative)

1. **Delete** `public_html/github.zip` if it still exists.
2. In cPanel → **Git™ Version Control** → **Create**:
   - Clone URL: `https://github.com/ersanjt/smm-turk-com.git`
   - Repository Path: `/home/smmturk/repositories/smm-turk-com` (not inside `public_html`)
   - Repository Name: `smm-turk-com`
3. Private repo: GitHub username + Personal Access Token (`repo` scope).
4. Prefer WHM Terminal `deploy-cpanel.sh` above instead of pointing clone at `public_html`.

## Daily workflow (safe)

```
Local edit → git commit → git push origin main
                 ↓
WHM Terminal: bash /home/smmturk/repositories/smm-turk-com/scripts/deploy-cpanel.sh
                 ↓
Site updates code only; DB + config.php + uploads untouched
```

## Backup before any server update

1. cPanel → Backup / phpMyAdmin export of the live database.
2. Keep a private copy of `public_html/config.php` (never commit it).
3. After deploy, check: homepage, `/login`, `/health`.

## Local development

```bash
# once — use your private backup, never commit
copy config.php.LOCAL_ONLY.bak config.php   # Windows
# or: cp config.example.php config.php and fill values
```

## Relation to other folders

- `ersanjt/smm` = old BoostPanel experiment — not production.
- `ersanjt/smm-turk-panel` = older public copy — stop using for deploy.
- `ersanjt/smm-turk-com` = **source of truth** for smm-turk.com.
