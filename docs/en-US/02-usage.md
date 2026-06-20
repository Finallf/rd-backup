# 02 — Using the plugin

Everything lives under **Tools → ReloadeD Backup**, split into tabs.

## Backup

- **Create a backup** — builds a complete `.zip` (database + uploads) into the store. A progress bar shows each phase (database → uploads → finalize). The run is resumable and survives the tab being closed.
- **Backup store** — lists the archives, with **Download** and **Delete**. The store is `wp-content/rd-backup/` (see [the store](03-architecture.md#the-store)). An optional nginx deny-rule snippet is shown for nginx-in-front setups.
- **Maintenance** — a **Reset job state** button to clear a stuck job if a run was interrupted. Your backups are not touched.

## Restore

- **Upload a backup** — bring a `.zip` from another site straight from the admin (up to the server's upload limit). For larger archives, drop the file into the store via **SFTP** and it shows up in the list.
- **Restore from a backup** — pick an archive and click **Preview**: it validates the manifest, checks the integrity hash, and shows compatibility warnings (origin domain, PHP/WP version, Redis drop-in). Nothing is written.
  - To apply it, type `RESTORE` to confirm. The plugin first takes a **safety snapshot** of the current site, then imports the database, runs a domain-safe search-replace (only when the origin domain differs), and extracts the uploads.

> ⚠️ A restore overwrites the current database — it replaces the users table, so **you are signed out when it finishes**. Just log back in. The safety snapshot is your undo.

- **Safety snapshots** — full backups taken automatically right before each restore (the last 2 are kept). Restore one to undo your last restore.

## Health

- **Updates** — the *Release status* card: current/latest version, a **Check for updates** button, the **Beta channel** switch, and **Update now** when an update exists. See [the self-updater](04-development.md#self-updater).
- **Environment** — a preflight table: ZipArchive, writable `wp-content/`, free disk space, the relevant PHP limits (`max_execution_time`, `memory_limit`, upload sizes), the web server, and the store's protection status.
