# 01 — Overview

## What it is

ReloadeD Backup is a standalone WordPress plugin that creates a **portable** backup of your site — the **entire database** (minus regenerable transients) plus the **`wp-content/uploads`** folder — in a single `.zip`, and restores it on **any** host.

It is the "go-bag" for disaster recovery: if the server is gone, spin up a fresh WordPress anywhere, install the plugin, drop in the backup, and the site comes back exactly as it was.

## Philosophy

- **Portable** — the backup is a self-contained `.zip` with a manifest and an integrity hash; it does not depend on the original host.
- **PHP-pure** — the database is dumped and imported entirely through `$wpdb`/`mysqli`, with no dependency on `exec()`, `mysqldump` or shell access. It runs on locked-down hosts where those aren't available.
- **Lean** — native `ZipArchive`, no third-party libraries. All presentation lives in CSS, all behavior in a separate JavaScript file — CSP-safe (no inline scripts or styles).
- **Standalone** — works with any theme. It pairs with the ReloadeD theme but does not require it.

## What's in a backup

- `database.sql` — a mysqldump-style dump of every table (transients skipped).
- `uploads/` — the contents of `wp-content/uploads`.
- `manifest.json` — metadata: schema version, origin URL, table list, row counts, and the SQL's SHA-256 hash.

Plugin and theme **code is not included** — those are reinstalled from their own sources; their settings live in the database, which is in the dump.

## Requirements

- WordPress `6.0+`
- PHP `8.0+`
- The `ZipArchive` PHP extension (verified on the Health tab).

## Install & activate

1. Download `rd-backup.zip` from the [Releases](https://github.com/Finallf/rd-backup/releases) page.
2. **Plugins → Add New Plugin → Upload Plugin** → upload → **Install Now** → **Activate**.
3. Open **Tools → ReloadeD Backup**.

From there, updates are automatic — see [04 — Development & CI](04-development.md#self-updater).
