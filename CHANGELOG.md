# Changelog

All notable changes to GitHubSync are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Test suite (`tests/`) reconstructed from the specification: 250 assertions
  across webhook signatures, path safety, file filtering, credential encryption,
  deployment locking, input/destination validation, and backup → promotion →
  rollback.
- Documentation: [`README.md`](README.md), [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md),
  [`docs/SECURITY.md`](docs/SECURITY.md), [`docs/WEBHOOK-SETUP.md`](docs/WEBHOOK-SETUP.md),
  [`docs/DEPLOYMENT-RECOVERY.md`](docs/DEPLOYMENT-RECOVERY.md), and
  [`docs/TESTING.md`](docs/TESTING.md).

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
