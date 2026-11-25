=== GitHubSync ===
Contributors: githubsync
Tags: github, deploy, deployment, git, webhook
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Deploy selected files from GitHub repositories into WordPress theme, plugin or static directories on push.

== Description ==

GitHubSync deploys a GitHub branch to a directory inside `wp-content` when a
commit is pushed. It downloads the exact commit archive over HTTPS, validates
and stages it, backs up the current directory, and swaps it in with an atomic
rename. It does not run `git`, does not shell out, and does not touch posts,
options or any other database content.

Supported destinations:

* A WordPress theme: `wp-content/themes/<folder>`
* A WordPress plugin: `wp-content/plugins/<folder>`
* A static site: `wp-content/uploads/githubsync-sites/<folder>`

Features:

* GitHub App installation tokens (preferred) or a fine-grained personal access
  token.
* Signed webhook receiver with delivery deduplication and replay protection.
* Streaming download with byte limits; Zip Slip, symlink and archive-bomb
  defences.
* Protected files such as `.git`, `.env` and `wp-config.php` are never written.
* Excluded files that already exist on the server are preserved across
  deployments.
* Verified backups, one-click rollback, and exponential-backoff retries for
  transient failures.
* Redacted logs, per-deployment history and a file-level diff for each job.
* Action Scheduler support when available; WP-Cron otherwise, with a recovery
  tick so missed events do not strand a job.
* Server-rendered admin screens; no JavaScript build step.
* Self-updates from GitHub releases, offered on Dashboard → Updates like any
  other plugin, with every package verified against a SHA-256 digest.

== Installation ==

1. Upload the `githubsync` folder to `wp-content/plugins/`.
2. Activate the plugin through the *Plugins* screen.
3. Go to *GitHubSync → Settings* and create an encryption key, or define
   `GITHUBSYNC_ENCRYPTION_KEY` in `wp-config.php`.
4. Go to *GitHubSync → GitHub Connection* and connect a GitHub App or a
   personal access token.
5. Add a deployment, then copy its payload URL and secret into the repository's
   webhook settings.

Secrets are encrypted at rest. The plugin refuses to store credentials when no
encryption key is available; there is no plaintext fallback.

== Frequently Asked Questions ==

= Does it need Git or SSH on the server? =

No. Deployments use GitHub's HTTPS archive endpoint and the WordPress HTTP API.

= Does it deploy database content? =

No. Only files inside the configured destination directory are managed.

= What happens to files I create on the server? =

Files matching an exclude pattern or a protected pattern are copied into the
staging tree before promotion, so they are never deleted. Everything else in the
destination is considered owned by the repository.

= Can it roll back? =

Yes. Each successful deployment keeps a verified backup (three per deployment by
default). Any retained backup can be promoted again from the deployment detail
screen.

= Does it support multisite? =

Not in this release. Multisite is detected and a warning is shown.

= Why is my deployment not running? =

Check *GitHubSync → Tools*. It reports the queue backend, whether WP-Cron is
disabled, whether an encryption key and GitHub connection exist, and the last
webhook deliveries. If `DISABLE_WP_CRON` is set, call `wp-cron.php` from a real
system cron or use *Run queue now*.

= How do I update GitHubSync? =

WordPress offers new releases on Dashboard → Updates and the Plugins screen, so
update it like any other plugin. If nothing appears, use **Check for updates now**
on *GitHubSync → Settings → Plugin updates*. Self-updates can be switched off on
the same screen or with `GITHUBSYNC_DISABLE_SELF_UPDATE`; the release zip can
still be installed with *Plugins → Add New → Upload Plugin*.

== Screenshots ==

1. Dashboard with connection, queue and recent deployment status.
2. Deployment form with repository, branch, source path and destination policy.
3. Deployment detail with webhook URL, secret, job history and rollback.
4. History of jobs across all deployments.

== Changelog ==

= 0.1.9 =
* GitHubSync now updates itself from its GitHub releases. New versions appear on Dashboard → Updates and the Plugins screen, with the release notes in the version details dialog, and WordPress installs them normally.
* Every update is checked against a SHA-256 digest before it is installed, and only `https://` packages on GitHub hosts are accepted.
* A twice-daily WP-Cron check looks for new releases. Settings → Plugin updates shows the last check, the installed version and a "Check for updates now" button.
* Self-updates can be switched off on Settings → Plugin updates or with the `GITHUBSYNC_DISABLE_SELF_UPDATE` constant. The release feed can be set with the `GITHUBSYNC_UPDATE_URL` constant.
* Publishing a `v*` tag now builds the release and its update manifest in CI, after the tests pass, and verifies both are publicly downloadable. See `docs/SELF-UPDATE.md`.
* Every admin screen is on one visual system (0.1.8): shared cards, tables, forms, notices, badges, empty states and dialogs, and layouts that stack on narrow screens.
* Destructive actions ask first in an accessible confirmation dialog, and copy buttons report when a value has been copied (0.1.8).

= 0.1.2 =
* The dashboard setup checklist now opens in a modal dialog from a "Show setup checklist" button, instead of occupying the top of the dashboard. The carousel, step dots and the close-only-when-complete rule are unchanged.
* Fix: a duplicated wrapper element in the dashboard template.

= 0.1.1 =
* Dashboard setup checklist: five steps with live completion state, shown as a carousel with Previous/Next and step dots.
* The checklist opens on the first unfinished step, reports WP-Cron and queue-tick health, and can be closed only once every step is complete.

= 0.1.0 =
* Initial release.

== Upgrade Notice ==

= 0.1.9 =
Adds self-updates: GitHubSync now appears on Dashboard → Updates like any other plugin. No settings change needed.

= 0.1.2 =
Moves the dashboard setup checklist into a dialog. No settings change needed.

= 0.1.1 =
Adds a step-by-step setup checklist to the dashboard.

= 0.1.0 =
Initial release.
