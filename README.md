# RD Backup

Complete, portable WordPress backup & restore — a **full database dump plus the `wp-content/uploads` folder** packaged into a single `.zip`, restorable on **any** host.

Built for the worst case: if the server is gone, spin up WordPress anywhere, install this plugin, drop in the backup, and the site comes back exactly as it was.

## Status

🚧 **Early development — scaffold stage.** The backup/restore engine is being designed before implementation.

## Highlights (planned)

- **Full site data** — the entire database (minus regenerable transients) plus uploads, in one `.zip`.
- **Background engine** — resumable, chunked steps that won't time out on large sites.
- **Restore anywhere** — domain-safe (serialized-aware search-replace), SFTP-friendly for large archives.
- **Environment health-check** — a preflight that flags `php.ini`/extension issues before you start.
- **Standalone** — works with any theme. Pairs with the [ReloadeD theme](https://github.com/Finallf/theme-reloaded) for a one-click entry point.

## Requirements

- WordPress 6.0+
- PHP 8.0+

## Contributors

<!-- readme: collaborators,contributors -start -->
<!-- readme: collaborators,contributors -end -->

## License

[GPL-2.0-or-later](LICENSE).
