=== WPicker ===
Contributors: wpicker
Tags: ai, cli, deployment, child-theme, rollback, developer-tools
Requires at least: 6.2
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A bridge between local AI agents and WordPress. Exposes AI-friendly site context, child-theme file sync, and a Deployment Vault with snapshot/rollback.

== Description ==

WPicker turns WordPress into an AI-native development environment.

* **The Eyes (this plugin)** — exposes site metadata, manages devices, and runs the Deployment Vault (atomic snapshots + rollback). Enforces an auto `php -l` lint gate on every push.
* **The Hands (companion CLI)** — a Go binary (`wpicker`) that authenticates via PIN + Application Passwords, then pulls/pushes child-theme files and rolls back.

See the repository README for the full PRD and CLI commands.

= Key features =

* Secure device pairing via 6-digit PIN + WordPress Application Passwords.
* Global Context API (`GET /wp-json/wpicker/v1/context`) returning WP/PHP/plugin/theme versions and theme_mods as JSON — to ground AI agents and reduce hallucination.
* Child-theme file sync confined by `realpath()` checks; no writes outside the child theme.
* **Deployment Vault**: every push snapshots the child theme first; restore any prior state in milliseconds.
* **Self-healing loop**: on a failed push, a structured error (`{ code, message, file, line, manifest_id }`) is returned so an AI agent can `history` → `rollback` → fix → push again.

= Guardrails =

* File writes are confined to the active **child theme** only.
* **No database writes** through the CLI. `theme_mods` is exposed read-only.
* Authentication is per-device via Application Passwords; the main login is never shared.

== Installation ==

1. Upload the `wpicker` folder to `/wp-content/plugins/`.
2. Activate **WPicker** via the Plugins screen.
3. Make sure Application Passwords are enabled (they are by default on SSL sites).
4. Go to **WPicker → Devices** to generate a pairing PIN, or pair from the CLI with `wpicker login`.

== Frequently Asked Questions ==

= Does WPicker write to the database from the CLI? =
No. The CLI never writes to the database. Theme configuration changes must be made via theme files, per WordPress best practice.

= Can it modify the parent theme or core files? =
No. All file operations are confined to the active child theme by realpath checks.

= Where are snapshots stored? =
In `wp-content/uploads/wpicker-vault/<manifest-id>/`, protected from web access by an `.htaccess` deny rule.

== Changelog ==

= 1.1.0 =
Update the plugin and the CLI together: pairing and file transfer changed, and a 1.0.0 CLI cannot talk to this version.

* Fixed: the REST API failed on every request in 1.0.0, so no CLI command worked.
* Fixed: plugin updates from GitHub were never offered in WP-Admin.
* Pairing with the administrator password (plus the PIN shown in WP-Admin), rate limited; the PIN can be regenerated or stopped.
* Push: health check after applying, with automatic restore if the site starts returning errors; deleted files are deleted on the site; binary files transfer intact.
* Review mode: hold pushes until an administrator reads the diff and applies or rejects them.
* Snapshots screen: search, filters, pagination, side-by-side diff, revert a single push, restore, delete, conflict resolution.
* Backup & Restore: full-site backups as one file; restore onto this site or upload a backup from another one, with addresses rewritten.
* Diagnostics (off by default): error logs, WordPress debug logging without editing wp-config.php, read-only access to wp-content and to the database without credentials.
* New admin design, Settings screen, new logo.

= 1.0.0 =
* Initial public version.

== Upgrade Notice ==

= 1.1.0 =
Fixes the REST API, which did not work in 1.0.0. Update the wpicker CLI to 1.1.0 at the same time.
