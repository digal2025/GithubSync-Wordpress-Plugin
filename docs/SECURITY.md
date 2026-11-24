# GitHubSync security

This document describes the threat model, the controls that exist, what they
deliberately do not cover, and how to report a vulnerability.

## Threat model

GitHubSync takes untrusted input from three places:

1. **Anonymous HTTP requests** to `/wp-json/githubsync/v1/webhook/<token>`.
2. **Remote repository content** — filenames, symlinks, sizes and file bodies
   arriving from GitHub in a tar/zip archive.
3. **Repository metadata** — branch names, source paths, repository names.

The assets worth protecting are: the WordPress site's files outside the
configured destination, the plugin's own credentials, and the integrity of the
live directory (a deployment must never leave it half-updated).

Administrators with `manage_options` are trusted. A compromise of an
administrator account or of the host itself is out of scope.

## Controls

### Webhook authentication

- The payload URL carries a 32-hex-character token, stored per deployment.
  An unknown token returns `404 unknown_endpoint` without describing why.
- The `X-Hub-Signature-256` header is verified as
  `sha256=` + hex HMAC-SHA256 of the **raw** request body with the deployment
  secret (`Webhooks/SignatureVerifier.php`).
- Comparison uses `hash_equals`. Missing, empty, malformed or wrong-prefix
  signatures are rejected with `401 invalid_signature` — failure is closed, not
  open. There is no "unsigned allowed" mode.
- The signature is checked before the body is JSON-decoded, so parsing never
  sees unauthenticated input.
- The deployment secret is generated with `random_bytes(32)` and stored
  encrypted (`webhook_secret_encrypted`).
- Failed signatures are rate-limited (`429 rate_limited`): once a client IP
  (`REMOTE_ADDR`) accumulates 30 failed signature checks, every request from it
  is refused until the 5-minute transient lapses; each failure refreshes that
  window. Only `401 invalid_signature` responses increment the counter, so a
  valid, signed delivery is never throttled. The body is size capped
  (`413 payload_too_large`, default `max_webhook_bytes` = 1 MB), and a deployment
  whose secret cannot be decrypted answers `500 endpoint_not_configured`
  rather than skipping verification.
- Replays are rejected: a repeated `X-GitHub-Delivery` ID is recorded once via a
  unique index in `*_githubsync_deliveries` and answered
  `202 duplicate_delivery`.

Abuse is limited to the holder of a token *and* a matching secret, and the worst
outcome is a deployment of the configured branch from the configured repository.

### Event authorisation

A valid signature is not enough. A delivery also has to be a `push`, come from
the repository **ID** recorded on the deployment (names are not trusted), and
target the configured branch. Anything else returns `202` with a reason and
queues nothing.

### Credential storage

`Security/CredentialVault.php` encrypts with libsodium sealed boxes
(`crypto_box_seal`) and prefixes payloads so formats can evolve: `v1:` for
general secrets, `g1:` for GitHub App private keys. The 32-byte key is read from
`GITHUBSYNC_ENCRYPTION_KEY`, then `GITHUBSYNC_KEY_FILE`, then an auto-generated
file outside the document root (`<wp-content>/../githubsync-secret.key`, mode
`0600`).

Decryption is fail-closed: a tampered, truncated or garbage payload raises a
`GitHubSyncException` and is never returned as plaintext. A wrong key produces
an explicit "reconnect GitHub" error rather than an unauthenticated API call.

Tokens are never written to logs, never rendered into HTML, and the REST and
admin layers return account names and expiry dates only. `.githubsync` debug
output redacts `Authorization` headers.

### Archive handling

Downloads come from `codeload.github.com` for an immutable commit SHA, not a
branch head, so what is validated is what is deployed. `SafeArchiveExtractor`
enumerates every entry before writing anything and rejects:

- absolute paths and any path containing `..` after normalisation;
- backslashes and NUL bytes;
- symlinks, hardlinks and device entries;
- entry counts, total uncompressed size and per-file size above the configured
  limits (`10000` files, `300 MB`, `50 MB` by default);
- archives larger than `max_archive_mb` (default 100 MB) — enforced on download
  as well as on disk.

Filenames that survive are re-checked by `PathGuard` against the staging root
before a write, and again before promotion.

### Path containment

- `PathGuard` normalises separators, collapses empty and `.` segments, rejects
  `..` and encoded traversal, and resolves symlinks before comparing against the
  approved root. Comparison is per path segment, so `/plugins-evil` does not
  pass as `/plugins`.
- `Domain/DestinationPolicy` fixes the approved roots: `wp-content/plugins`,
  `wp-content/themes`, and `wp-content/uploads/githubsync-sites` for `static`.
  Slugs cannot contain a slash or start with a dot.
- A destination that already contains content GitHubSync did not create is
  reported as a collision (`DESTINATION_COLLISION`) instead of being overwritten.
- GitHubSync refuses to deploy into its own plugin directory.

### File filtering

`Security/FileFilter.php` blocks dangerous names even when they are present in
the repository: `.git/`, `.github/`, `.gitignore`, `.gitattributes`,
`.gitmodules`, `.env` and `.env.*`, `wp-config.php`, `.htpasswd`, `*.pem`,
`*.key`, `*.p12`, `*.pfx`, `id_rsa*`, `id_ed25519`, `.ssh/`, and OS clutter
(`.DS_Store`, `Thumbs.db`, `desktop.ini`). Administrators can add glob excludes
per deployment.

This is a blocklist, not an allowlist: a repository can still ship PHP that
executes. Only deploy repositories you control.

### Integrity: backup before promote

No live file is removed until a verified copy exists. `BackupManager::create()`
copies the current tree, walks it and compares a SHA-256 per file
(`verify()`), writes a manifest, and only then does `PromotionManager::promote()`
swap staging in with a `rename()` inside the same parent directory. If the swap
fails, the previous tree is restored and the job records `PROMOTION_FAILED`.

Per-deployment advisory locks (`DeploymentLock`) stop two jobs promoting into
the same destination, with heartbeats so a crashed worker's lock goes stale
instead of blocking forever. Stale locks can be released from **Tools**.

Backups are pruned to the configured retention (default 3) and are stored under
`wp-content/uploads/githubsync-backups/`, outside any deployable root.

### Admin surface

- Every menu page, form and action requires the `manage_options` capability.
- Every state-changing request is nonce-protected.
- All SQL goes through `$wpdb` prepared statements in `Database/*Repository`;
  no user input is interpolated into SQL.
- Admin inputs are validated by `Security/InputValidator.php` (repository
  name shape, branch, slug, source path, numeric IDs) and sanitised for output.

## Known limitations

- **No guarantee about deployed code.** A deployment writes files; whether they
  are safe is the repository author's responsibility.
- **Webhooks are created by hand.** This build never calls GitHub's webhook
  API, so the connection needs only `Contents: Read-only` and `Metadata:
  Read-only`. You add the webhook yourself (see `WEBHOOK-SETUP.md`), and a
  misconfigured secret fails closed with `401`.
- **Rate limiting keys on the client IP** and depends on transients; a flush of
  the object cache resets the counters, and clients behind a shared proxy share
  a bucket. It slows guessing, it does not stop a distributed attack — the HMAC
  is what actually protects the endpoint.
- **The encryption key is only as safe as the filesystem.** Its auto-generated
  location is outside the document root but inside the hosting account. Use
  `GITHUBSYNC_KEY_FILE` to place it where only the web user can read it.
- **Uninstall does not delete deployed files or backups**, by design (see
  `README.md`). The key file is never deleted automatically.
- **No multi-user audit trail** beyond the deployment log; there is no record of
  *who* clicked Deploy Now.
- **Rollback restores a backup directory, not a commit.** Rolling back to a job
  whose backup has been pruned is refused.

## Vulnerability reporting

Please report security issues **privately**, not in a public issue:

1. Use GitHub's **Report a vulnerability** button on the repository's
   **Security** tab (private security advisories), or
2. email the maintainer listed in `readme.txt` if advisories are unavailable.

Include the version, the affected file, a reproduction and the impact. Please do
not include live credentials or secrets; redact them. You will get an
acknowledgement, an assessment, and credit in the release notes unless you ask
to stay anonymous. Fixes are released as a new patch version; no public
disclosure before the fix ships.
