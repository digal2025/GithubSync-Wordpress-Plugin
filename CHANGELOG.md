# Changelog

All notable changes to GitHubSync are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Nothing yet.

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
