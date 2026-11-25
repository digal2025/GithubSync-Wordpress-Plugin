# Changelog

All notable changes to GitHubSync are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.9] - 2026-11-25

### Added

- **GitHubSync updates itself.** New releases are offered on the Plugins screen
  and on Dashboard → Updates exactly like a WordPress.org plugin, and are
  installed by WordPress with the normal one-click update. The version details
  dialog shows the release notes. A twice-daily WP-Cron check finds a new release
  without anyone opening the Plugins screen, and there is a *Check for updates*
  row link on the Plugins screen plus a **Check for updates now** button on
  *GitHubSync → Settings → Plugin updates*.
- **Every download is verified.** The manifest (`update.json`) carries a SHA-256
  digest, so a corrupted or swapped package is rejected before WordPress
  installs it. Only `https://` packages on GitHub hosts are accepted.
- **Self-updates can be switched off.** Clear the **Self-updates** checkbox on
  the Settings screen, or define `GITHUBSYNC_DISABLE_SELF_UPDATE` in
  `wp-config.php`. The release feed URL can be set with the
  `GITHUBSYNC_UPDATE_URL` constant, which wins over the stored setting.
- A `v*` tag now publishes the release in CI: the suite must pass, then
  `githubsync.zip` and `update.json` are attached to the release in the public
  download repository `digal2025/githubsync-releases` and checked to be
  publicly downloadable. See [`docs/SELF-UPDATE.md`](docs/SELF-UPDATE.md).

### Notes

- Nothing changes for a site that leaves self-updates off: no request is made
  and the manual **Plugins → Add New → Upload Plugin** route still works.

## [0.1.8] - 2026-11-25

### Changed

- **Every admin screen has been redesigned on one visual system.** The stylesheet
  is now a small, documented design system built on the WordPress admin colours
  and spacing, with a single set of tokens at the top of `admin.css`. Cards,
  tables, forms, notices, badges, empty states and dialogs all use it, so the
  Dashboard, Deployments, Add/Edit Deployment, Deployment detail, History,
  Connection, Settings and Tools screens look and behave the same way. No new
  dependencies and no build step were added; the plugin still needs only PHP 7.4
  and WordPress 6.2.
- **Layouts adapt to the screen.** On narrow screens the form tables stack each
  label above its field, buttons in action rows become full-width tap targets,
  and the deployment and activity tables turn into one labelled card per row
  instead of scrolling sideways. The wide diagnostics and log tables scroll in
  their own container.

### Added

- **Destructive actions ask first, in a proper dialog.** Deleting a deployment
  configuration, rolling back, generating a new webhook secret, clearing logs and
  disconnecting GitHub now open an accessible confirmation dialog that names the
  action and explains what is kept. The dialog's button labels are translated
  (`githubSyncL10n`).
- Copy buttons on the webhook URL and secret report "Copied" when the value has
  been copied.

### Notes

- Only the admin interface changed. Deployments, settings, stored credentials and
  the deployment history are untouched, and no database changes were made.

## [0.1.7] - 2026-11-24

### Fixed

- **Deploying a repository archive failed with `ARCHIVE_INVALID` — "GitHub did
  not provide an archive location."** GitHub answers the zipball request with a
  redirect to `codeload.github.com`, but GitHubSync read the response headers in
  a way that WordPress 6.2+ does not support, so the `Location` header was always
  read as empty and every deploy stopped before downloading anything. The
  headers are now read correctly and the archive is fetched and extracted as
  normal.
- **Pagination and rate-limit handling read the same broken headers.** The
  `Link` header (used to tell whether more branches or repositories follow) and
  the `Retry-After` / `X-RateLimit-*` headers are now read as GitHub sends them.
  Previously the plugin always believed it was on the last page and always fell
  back to a fixed retry delay.

### Notes

- Only the header handling changed. Deployments, settings and stored credentials
  are untouched.

## [0.1.6] - 2026-11-24

### Added

- **The Add Deployment form explains itself.** A "How to fill this in" card
  lists what each field needs, and every field has a short description and
  placeholder. The card points at the repository URL trick (`?gs_repo=owner/name`)
  and the Browse repositories list, which are what make prefilling possible.
- **"Adopt an existing destination directory" is explained.** The hint now says
  what the checkbox does and does not do: it only allows GitHubSync to overwrite
  a folder that is already on disk. It makes no backup, keeps protected files
  excluded and does not touch the database. Tick it only when overwriting that
  folder is acceptable.
- **New deployments are prefilled from GitHub.** When the form is opened for a
  repository GitHubSync can read, three fields are filled in before the user
  types anything: **Branch** (the repository's default branch), **Source path**
  (empty, meaning repository root) and **Destination folder** (the repository
  name, lower-cased). A notice says which repository the values came from and
  whether it is public or private, and each filled field carries a one-line
  hint naming its source.
- **A failed repository lookup says so.** If metadata cannot be read, a warning
  explains that nothing was prefilled and repeats the GitHub error, instead of
  leaving the form silently empty.

### Changed

- Prefilling never overwrites a value that was already sent, and never runs on
  the edit screen, so a saved deployment is not rewritten by a repository rename.
- **Deployment type is never guessed.** GitHub cannot tell a plugin from a theme
  from a static site, so the type, exclude patterns and display name stay the
  user's choice. The type note was dropped from the suggestion code for that
  reason.
- A folder name that another deployment already uses is flagged in the hint,
  and the plugin never suggests its own folder.
- Only successful repository lookups are cached (5 minutes), so a failed lookup
  is retried the next time the form is opened.

## [0.1.5] - 2026-11-24

### Fixed

- **The App-level webhook URL was unreachable.** The route only matched a
  32-character per-deployment token, so every delivery to the URL handed to
  GitHub in 0.1.4 — which carries the 64-character site-wide App secret — was
  answered `404 rest_no_route`. The route now accepts `[a-f0-9]{32}` and
  `[a-f0-9]{64}`.
- **App deliveries now fan out.** A correctly signed App delivery is offered to
  every active deployment with automatic deployment on; repository and branch
  are checked per deployment, and every non-matching deployment answers 202
  without creating a job.

### Changed

- `AppSetup::app_webhook_secret()` (read-only, returns `''` when none exists)
  sits alongside `AppSetup::hook_secret()` (creates on demand). The webhook
  controller only reads: receiving a delivery never mints or rotates a secret.
- An App delivery is validated *before* the delivery ID is recorded, so a
  deployment whose repository does not match cannot claim the ID from the one
  that does.
- `WebhookController` responses for App deliveries use 202 for every non-queued
  outcome; `queued` returns `{ "deployments": n }`.

## [0.1.4] - 2026-11-24

### Fixed

- **One-click App setup was rejected by GitHub.** The manifest sent
  `permissions` and `events`; GitHub's schema names them `default_permissions`
  and `default_events`, and it refused the registration with
  `"events", "permissions" are not permitted keys`.
- The manifest omitted `hook_attributes.url`, which GitHub requires even for an
  inactive hook (`"url" wasn't supplied`). The App webhook URL is now set to the
  site's webhook route, signed with a site-wide secret from the new
  `AppSetup::hook_secret()`.
- `AppSetup::webhook_url()` built a `?token=` query URL that the
  `githubsync/v1/webhook/{token}` route never matched. It now uses the path form.

## [0.1.3] - 2026-11-24

### Added

- Test suite (`tests/`) reconstructed from the specification: 250 assertions
  across webhook signatures, path safety, file filtering, credential encryption,
  deployment locking, input/destination validation, and backup → promotion →
  rollback.
- Documentation: [`README.md`](README.md), [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md),
  [`docs/SECURITY.md`](docs/SECURITY.md), [`docs/WEBHOOK-SETUP.md`](docs/WEBHOOK-SETUP.md),
  [`docs/DEPLOYMENT-RECOVERY.md`](docs/DEPLOYMENT-RECOVERY.md), and
  [`docs/TESTING.md`](docs/TESTING.md).

### Added

- **One-click GitHub App setup (signed manifest flow).** *Create GitHub App* on
  the connection page posts a signed manifest to GitHub; after approval the App
  ID, private key and webhook secret are fetched through the manifest conversion
  endpoint and stored encrypted. The page then offers **Install**, and a
  pick-your-installation list binds an App that was created but not yet
  installed. A one-time private-key download is offered because GitHub only
  shows the key once.
- `githubsync_app_setup_supported` filter, for sites behind a TLS-terminating
  proxy whose `home_url()` reports `http://` while GitHub sees `https://`.

### Changed

- A GitHub App can be stored before it is installed: the App ID is required but
  a blank installation ID is accepted when a private key is supplied.
- Changing the connection rolls back to the previous credentials if the new
  installation cannot be verified against GitHub, so a half-finished flow never
  replaces a working connection or personal access token.
- `is_connected()` requires an installation for App mode; an App that exists but
  is not installed is reported separately.

## [0.1.2] - 2026-11-24

### Changed

- **The setup checklist is now a dialog.** It opens from a *Show setup checklist*
  button in the dashboard header, shows the completed-step count, and closes with
  the × button, Escape, a click on the backdrop, or *Hide for now*. The carousel,
  step dots, progress bar and the rule that the checklist can only be marked
  closed once every step is complete are unchanged.

### Fixed

- A duplicated `gs-grid` wrapper in `templates/dashboard.php`.

## [0.1.1] - 2026-11-24

### Added

- **Dashboard setup checklist.** Five steps — encryption key, GitHub connection,
  deployment, webhook, first push — shown as a carousel with Previous/Next
  buttons and step dots. Each step states what to do, links to the page where it
  is done, and is marked done only from data the plugin can observe (key
  configured, credentials stored, deployments saved, a `webhook`-triggered job
  recorded). The webhook step says outright that adding the webhook on GitHub is
  manual, because no API call creates it.
- The checklist opens on the first unfinished step, shows a progress bar, and
  reports WP-Cron and deployment-queue-tick health below the slides.
- **Close checklist** appears only once every step is done; it is stored per user
  and checked server-side, and **Show the setup checklist again** restores it.

## [0.1.0] - 2026-11-24

First public release. This is the initial implementation of the specification in
`GithubSync.md`; APIs, option names and database schema may still change before
1.0.

### Added

- **Deployments.** Create a deployment for a repository, branch, source path and
  destination (`plugin`, `theme`, or `static`). Optional path filtering (include
  and exclude) and a source subdirectory.
- **Automatic deployment on push.** A per-deployment webhook endpoint at
  `/wp-json/githubsync/v1/webhook/<token>` verifies the `X-Hub-Signature-256`
  header over the raw body with `hash_equals`, rejects duplicate
  `X-GitHub-Delivery` ids, and answers `ping` and `push` events. A push only
  deploys when the branch and repository match the deployment.
- **GitHub authentication.** A GitHub App installation (private key, installation
  id regenerated on demand) or a fine-grained personal access token. Credentials
  are encrypted with libsodium (or OpenSSL as a fallback) using a key file
  written with `0600` outside the web root, or a base64 key supplied through the
  `GITHUBSYNC_ENCRYPTION_KEY` constant in `wp-config.php` for hosts where
  GitHubSync cannot write the key file.
- **Safe extraction.** Archives are downloaded over HTTPS, size- and count-limited,
  and extracted only after every entry is checked: no absolute paths, no `..`,
  no symlinks, devices or FIFOs, no NUL bytes. A blocklist keeps `.git/`, `.env`,
  `wp-config.php`, key material and OS clutter out of a deployment even if the
  repository contains them.
- **Atomic promotion.** The extracted tree is staged next to the destination,
  the live tree is backed up and hash-verified, and the switch is a single
  `rename()` inside the destination's parent. A failed promotion restores the
  previous tree.
- **Rollback.** Any successful job can be rolled back to from its verified
  backup; the rollback is itself backed up.
- **Queue and retries.** Deployments run through WP-Cron (or Action Scheduler
  when present), with exponential backoff for the four retryable error codes and
  a per-deployment lock that cannot be held by two jobs at once.
- **Admin.** Dashboard, Deployments, Add/Edit Deployment, Deployment detail,
  History, GitHub Connection, Settings and Tools screens, with nonce-checked and
  capability-checked admin-post actions, a delivery log and a plugin log.
- **Uninstall.** Keeps configuration, history, deployed files and backups by
  default. Ticking **Delete GitHubSync tables and settings when the plugin is
  uninstalled** in Settings opts in to dropping the plugin's four tables and its
  options; deployed directories, backups and the encryption key file are still
  kept.

### Known limitations

- No live GitHub deployment has been performed end to end in this build. The
  GitHub code paths are covered by error-branch tests only.
- A job that ends `failed` leaves the previous live tree in place; it does not
  notify anyone. Check **History**.
- No PHPCS, PHPStan or CI configuration yet; the test suite is run by hand.
- Multi-site and Windows are not tested.
