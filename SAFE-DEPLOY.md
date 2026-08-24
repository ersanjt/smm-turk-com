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

## One-time: connect cPanel to this private repo

1. **Delete** `public_html/github.zip` if it still exists.
2. In cPanel → **Git™ Version Control** → **Create**:
   - Clone URL: `https://github.com/ersanjt/smm-turk-com.git`
   - Repository Path: `/home/smmturk/repositories/smm-turk-com` (not inside `public_html`)
   - Repository Name: `smm-turk-com`
3. Private repo: create a GitHub **Personal Access Token** (classic: `repo` scope) or deploy key, and use it when cPanel asks for password (username = your GitHub user, password = token).
4. After clone succeeds, open the repo → **Manage** → **Pull or Deploy**:
   - Ensure `.cpanel.yml` is present (this repo has a safe one without `--delete`).
   - First deploy: only after you confirm live `config.php` already exists in `public_html`.
5. Do **not** point the Git clone path directly at `public_html` (that risks wiping the live tree on reset).

## Daily workflow (safe)

```
Local edit → git commit → git push origin main
                 ↓
cPanel → Git Version Control → Pull or Deploy
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
