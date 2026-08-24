# Safe deploy rules for smm-turk.com

Goal: develop and update code **without** losing database data or breaking production.

## Never do on the live server

- Do **not** drop/truncate MySQL tables or re-run `install.sql` / `install-cpanel.sql` on production.
- Do **not** overwrite `config.php` on the server from git (it is gitignored).
- Do **not** use `rsync --delete` blindly unless you have verified excludes for `config.php`, `uploads/`, `storage/`, and `tmp/`.
- Do **not** leave `github.zip` (or any full source zip) inside `public_html`.

## Safe workflow

1. Work only in this local git repo.
2. Keep a local `config.php` (copy from `config.example.php` or from your secure backup) — never commit it.
3. Push code to the **private** GitHub remote.
4. On cPanel, pull/update **code files only**. Leave DB and `config.php` untouched.
5. Run migrations only when a change explicitly needs them, and only after a DB backup.

## Backup before any server update

1. cPanel → Backup / phpMyAdmin export of the live database.
2. Download a copy of `public_html/config.php` privately (not into git).
3. Confirm site still works after deploy (`/health` or homepage + login).

## Relation to other folders

- `ersanjt/smm` = old BoostPanel experiment — not production.
- `ersanjt/smm-turk-panel` = older public copy — do not push secrets there.
- This repo = source of truth for **smm-turk.com** development.
