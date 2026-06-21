# 03 — Architecture

## Code structure

```
rd-backup.php                      Plugin header + bootstrap (requires + hooks)
inc/                               All classes live flat here (one class per file)
  class-rdbk-plugin.php            Orchestrator — boots the runner, storage, admin, updater
  class-rdbk-updater.php           GitHub self-updater (see Development)
  class-rdbk-job.php               Resumable job state (a JSON file in the store)
  class-rdbk-runner.php            admin-ajax endpoints: start / step / cancel / upload / preview
  class-rdbk-storage.php           The store: directory, hardening, naming, download handler
  class-rdbk-db-dump.php           PHP / $wpdb database dump
  class-rdbk-archiver.php          ZipArchive append (uploads + sql)
  class-rdbk-manifest.php          manifest.json builder
  class-rdbk-backup.php            Backup orchestrator (db -> uploads -> finalize)
  class-rdbk-db-import.php         Statement-by-statement SQL import
  class-rdbk-search-replace.php    Serialized-safe URL replace (cross-domain)
  class-rdbk-uploads-extract.php   Streams uploads/ out of the zip
  class-rdbk-restore.php           Restore orchestrator (import -> replace -> uploads)
  class-rdbk-admin.php             Tools -> ReloadeD Backup screen (tabs, AJAX wiring)
  class-rdbk-healthcheck.php       Preflight checks
assets/
  css/rdbk-admin.css               Admin UI (native wp-admin palette)
  js/rdbk-admin.js                 Admin behavior (unobtrusive, CSP-safe)
  img/                             Logo
```

All classes live flat in `inc/` (one class per file, like the ReloadeD theme), are prefixed `RDBK_`, the text-domain and slug are `rd-backup`, and globals use the `rdbk` / `RDBK` prefix.

## The resumable job engine

One job runs at a time. Its state lives in a **file** — `wp-content/rd-backup/.job.json` — **not** in the database (a restore overwrites the database, which would wipe the very job driving it). The browser drives it: `start` → `step` → `step` … → `done`, polling `admin-ajax`. Each step is time-boxed and saves a cursor (byte offset / row index), so a big run is chunked and survives the tab being closed.

Because a restore swaps the whole database — including `siteurl` (which changes `COOKIEHASH`) and the session tokens — the admin can be logged out mid-run. Each job carries a random **secret** (issued to the browser by the authenticated start); the step loop authorizes with that secret, so it keeps running through the logged-out window.

## The store

Backups live in `wp-content/rd-backup/` — **outside** the plugin (survives plugin updates) and **outside** uploads (never inside its own backups). Protection is universal:

- **Random-token filenames** — `rd-backup-<host>-<date>-<token>.zip` (safety snapshots are tagged `rd-backup-safe-…`).
- An **authenticated, PHP-only download** handler (`admin-post` + nonce) — a direct URL never serves a backup.
- An automatic **`.htaccess` deny** (Apache). An optional nginx server rule is offered in the UI for nginx-in-front setups (e.g. HestiaCP), where the `.htaccess` can be bypassed.

## Backup pipeline

`RDBK_Backup` drives three phases inside a hidden work dir, then publishes the finished `.zip` to the store:

1. **db** — `RDBK_DB_Dump` writes `database.sql` (`SHOW CREATE TABLE` + batched `INSERT`s; values escaped with `mysqli_real_escape_string`; transients skipped).
2. **uploads** — `RDBK_Archiver` appends `wp-content/uploads` into the zip (stored, not recompressed — they're already compressed).
3. **finalize** — adds the `.sql` (deflated) and `manifest.json`, moves the zip into the store, then **self-verifies** it: the published archive is re-opened and its `database.sql` re-hashed against the manifest. A mismatch deletes the bad zip and fails the job — a corrupt write is caught at creation, not on the day you need the backup.

## Restore pipeline

`RDBK_Restore` validates the archive (manifest + SHA-256). The hash is **re-checked server-side when the restore starts**, so a failed integrity check aborts before anything is written. Then it applies in phases:

1. **import** — `RDBK_DB_Import` extracts `database.sql` and executes it statement by statement (resumable by byte offset; best-effort, so one bad statement doesn't abort the run).
2. **replace** — `RDBK_Search_Replace` swaps the origin URL for this site's URL across every table, **serialized-safe** (unserialize → replace → re-serialize, so length prefixes stay valid). Runs only when the domain differs.
3. **uploads** — `RDBK_Uploads_Extract` **mirrors** `uploads/`: it streams the archived files back (overwriting on collision), then prunes any file under `wp-content/uploads` not in the backup, so the folder matches the backup exactly (scoped to the uploads dir, resumable).

A full **safety snapshot** is taken before any restore, with a retention of the last 2 — it's the undo for everything a restore overwrites or prunes.
