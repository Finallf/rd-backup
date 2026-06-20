# 04 — Development & CI

## Git workflow

Two branches, like the ReloadeD theme:

- **`beta`** — development; pushes cut `1.0.0-beta.X` **prereleases**.
- **`master`** — stable; merging `beta → master` cuts the stable release.

Versioning is automatic via **semantic-release** with Conventional Commits: `feat` → minor, `fix` → patch, `chore` / `ci` → no release. The version in `rd-backup.php` is bumped by the release commit.

## GitHub Actions

- **Smoke Test** (`smoke-test.yml`) — on every push / PR: `php -l` on all PHP files + **PHPCS** (WordPress Coding Standards + PHPCompatibility).
- **Release & Package** (`master.yml`) — on push to `master` / `beta`: updates the contributors list, runs semantic-release, builds `rd-backup.zip` (internal folder `rd-backup/…`), and attaches it to the GitHub Release.

A local **pre-push hook** (`tools/git-hooks/pre-push`) mirrors the CI (`php -l` + full PHPCS) so a red push is caught before it leaves the machine. Enable it once per clone:

```bash
git config core.hooksPath tools/git-hooks
```

## Coding standards

PHPCS with the `WordPress` ruleset (Core + Extra + Docs) plus `PHPCompatibilityWP`, configured in `phpcs.xml.dist`. Run it locally with `composer phpcs`.

Principles followed throughout: separation of concerns, all styling in CSS (no inline `style`), all behavior in a separate JS file attached via `addEventListener` (no inline scripts or handlers) — i.e. **CSP-safe**.

## Self-updater

`RDBK_Updater` connects the plugin to its GitHub Releases so WordPress detects new versions and offers a **1-click update**, like any wp.org plugin — with no external dependencies.

- **Hooks:** `pre_set_site_transient_update_plugins` (inject the update into WP's transient), `plugins_api` (the "View details" modal, with a plain-text changelog), and `upgrader_source_selection` (force the extracted folder to `rd-backup`).
- **Channels:** *stable* follows `/releases/latest` (GitHub excludes prereleases); the opt-in **beta** channel follows the newest release **including** prereleases, and auto-promotes back to stable when a newer stable ships. The result is cached in a 24h transient, tagged by channel.
- **UI:** the Updates card on the **Health** tab — version status, the beta-channel switch, **Check for updates**, and **Update now**.

> The release order returned by GitHub's `/releases` endpoint is not guaranteed to be newest-first, so the beta channel picks the **highest semver** tag rather than the first entry.
