# ReloadeD Backup — Documentation

Documentation for the **ReloadeD Backup** plugin — a complete, portable WordPress backup & restore tool: a full database dump plus the `wp-content/uploads` folder in a single `.zip`, restorable on **any** host. Pure PHP, zero external dependencies.

## 📚 Index

| Chapter | Contents |
|---------|----------|
| [01 — Overview](01-overview.md) | What the plugin is, philosophy, what's in a backup, requirements, install |
| [02 — Using the plugin](02-usage.md) | The admin screen: Backup, Restore, Upload, Safety snapshots, Health, Updates |
| [03 — Architecture](03-architecture.md) | Code structure, the resumable job engine, the store, backup/restore pipelines |
| [04 — Development & CI](04-development.md) | Git workflow, semantic-release, GitHub Actions, coding standards, the self-updater |

## 🚀 Quick start

1. Download the latest `rd-backup.zip` from the [Releases](https://github.com/Finallf/rd-backup/releases) page.
2. In WordPress: **Plugins → Add New Plugin → Upload Plugin** → choose the `.zip` → **Install Now**.
3. **Activate**. The plugin lives under **Tools → ReloadeD Backup**.
4. From there, updates are automatic — a 1-click update on the Plugins screen, like any other plugin.

## 📦 Version

See [CHANGELOG.md](../../CHANGELOG.md) for the full history, or the [Releases page](https://github.com/Finallf/rd-backup/releases) on GitHub.

## 📜 License

GNU GPL v2 or later (same as WordPress).
