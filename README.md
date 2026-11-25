# GitHubSync

Deploy selected files from a GitHub repository to a WordPress directory whenever
a branch is pushed, with a manual deploy button, verified backups and one-click
rollback.

GitHubSync is deliberately narrow: it moves a subdirectory of a Git repository
into a destination inside `wp-content/`, it never touches WordPress core, and it
refuses any path that could escape the destination you configured.

---

## Contents

1. [Product overview](#product-overview)
2. [Supported use cases](#supported-use-cases)
3. [Requirements](#requirements)
4. [Installation](#installation)
5. [GitHub App setup](#github-app-setup)
6. [Connecting GitHub](#connecting-github)
7. [Creating a deployment](#creating-a-deployment)
8. [Configuring webhooks](#configuring-webhooks)
9. [Manual deployment](#manual-deployment)
10. [Automatic deployment](#automatic-deployment)
11. [Troubleshooting](#troubleshooting)
12. [Security notes](#security-notes)
13. [Uninstall and data retention](#uninstall-and-data-retention)
14. [Documentation](#documentation)
15. [Development](#development)

---

## Product overview

A push to GitHub does not, by itself, put files on your WordPress site. GitHubSync
closes that loop:

1. GitHub sends a signed `push` webhook to your site.
2. GitHubSync verifies the HMAC signature against the raw request body.
3. The event is checked against the repository and branch you configured.
4. A background job resolves the push to an immutable commit SHA.
5. The commit archive is downloaded, validated and extracted into a staging
   directory next to the destination.
6. The current live files are copied to a backup and verified by hash.
7. Only then are the staged files promoted into place.

Every step is recorded in the deployment history, and a failed step leaves the
previous live files untouched.

## Supported use cases

| Use case | Deployment type | Typical destination |
| --- | --- | --- |
| Custom theme deployed from a repo | `theme` | `wp-content/themes/<slug>` |
| Must-use or normal plugin from a repo | `plugin` | `wp-content/plugins/<slug>` |
| Static site, landing page or docs build | `static` | `wp-content/uploads/githubsync-sites/<slug>` |
| Shared assets, fonts, templates | `theme` / `static` | a directory under `wp-content/` |

Multiple deployment configurations can coexist. Each has its own repository,
branch, source path, destination and webhook endpoint.

## Requirements

- WordPress 6.2 or newer.
- PHP 7.4 or newer (tested through PHP 8.4).
- The `curl` and `zip` PHP extensions.
- A destination inside `wp-content/` that the web server can write to.
- Outbound HTTPS access from WordPress to `api.github.com` and
  `codeload.github.com`.
- WP-Cron working, or a system cron job triggering WP-Cron. See
  [Automatic deployment](#automatic-deployment).

## Installation

### From a release ZIP

1. Download `githubsync.zip` from the repository's **Releases** page.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**.
3. Choose the ZIP and click **Install Now**, then **Activate**.

Or with WP-CLI:

```bash
wp plugin install /path/to/githubsync.zip --activate
```

### From source

```bash
cd wp-content/plugins
git clone https://github.com/<you>/githubsync.git
wp plugin activate githubsync
```

On activation GitHubSync creates four tables (`*_githubsync_deployments`,
`*_githubsync_jobs`, `*_githubsync_logs`, `*_githubsync_deliveries`), stores a
schema version, seeds default settings and schedules two cron events:
`githubsync_queue_tick` and `githubsync_cleanup`.

### Encryption key

Credentials are encrypted with libsodium before they are written to the database.
The key is read from, in order:

1. The `GITHUBSYNC_ENCRYPTION_KEY` constant (base64, 32 bytes decoded).
2. The file named by the `GITHUBSYNC_KEY_FILE` constant.
3. `<wp-content>/../githubsync-secret.key` — created automatically with mode
   `0600` on first use.

Keep the key outside the document root and **never** ship it in a plugin ZIP. If
the key is lost, stored credentials cannot be decrypted; GitHubSync fails closed
and asks you to reconnect rather than sending anything unauthenticated.

## GitHub App setup

GitHubSync supports three connection methods. A GitHub App is recommended because
it issues short-lived tokens and scopes access to the repositories you choose.
The quickest way to create one is the signed manifest flow, which GitHub only
accepts on an **HTTPS** home URL (or `http://localhost`).

### One-click setup (recommended)

1. Go to **GitHubSync → GitHub Connection** and click **Create GitHub App**.
2. On GitHub, review the prefilled manifest and click **Create GitHub App**.
3. GitHub redirects back to the connection page. The App ID, private key and
   webhook secret are stored automatically for you.
4. Download the private key once if you want a copy (GitHub only shows it once),
   then click **Install** and pick the repositories.

The webhook is created inactive: select repositories on GitHub and activate it
before relying on automatic deployments. If installing does not finish, the
pick-your-installation list on the same page binds it later.

Sites served over plain HTTP cannot use this flow. WordPress reports that in the
dashboard, and you can either set up the App manually (below) or leave the
redirect on `https://` behind a TLS-terminating proxy and tell GitHubSync the site
is HTTPS-ready:

```php
add_filter( 'githubsync_app_setup_supported', '__return_true' );
```

Only add that filter when GitHub really redirects to an HTTPS URL for your site.

### Manual setup

1. Go to **GitHub → Settings → Developer settings → GitHub Apps → New GitHub App**.
2. Set **Homepage URL** to your site.
3. Uncheck **Active** under *Webhook*. GitHubSync does not create webhooks
   through the API, so the App-level webhook is not used.
4. Under **Permissions → Repository permissions** grant:
   - **Contents: Read-only** (required — archive download)
   - **Metadata: Read-only** (granted automatically)
5. Click **Create GitHub App**, note the **App ID**, and click
   **Generate a private key** to download the `.pem` file.
6. Click **Install App** and install it on the repositories you want to deploy,
   then note the **installation ID** — it is the number at the end of the
   installation's settings URL (`.../installations/<id>`).

## Connecting GitHub

1. Go to **GitHubSync → GitHub Connection**.
2. Use **Create GitHub App** (above), or choose the **GitHub App** section and
   paste the App ID, the installation ID and the contents of the private key,
   then save. The private key is validated as a parseable PEM and encrypted
   before it is stored. The App and installation IDs are not verified against
   GitHub at save time — the first deployment is what proves them, so run
   **Deploy Now** once after connecting.
3. Alternatively use the **Personal access token** tab. A fine-grained token
   needs **Contents: Read-only** on the target repositories. Saving a token
   verifies it immediately against `GET /user` and stores the account name.
4. The page shows the connected mode, account and installation.

**Disconnect** deletes the stored credentials from the database and clears any
cached token. Revoking the App installation itself happens on GitHub.

Tokens are never rendered into HTML, written to logs or returned by the REST API.
`.githubsync` debug output redacts `Authorization` headers.

## Creating a deployment

Go to **GitHubSync → Add Deployment**.

| Field | Notes |
| --- | --- |
| **Name** | Label shown in the UI. |
| **Repository** | Chosen from the repositories the connection can see. Private repos appear when permitted. |
| **Branch** | The branch that triggers deployments. |
| **Source path** | Optional. A subdirectory inside the repo, e.g. `wordpress/my-custom-theme`. Leave empty to deploy the repository root. |
| **Deployment type** | `plugin`, `theme` or `static`. |
| **Destination slug** | The folder name under `wp-content/plugins` or `wp-content/themes`; for `static` it is a folder under `wp-content/uploads/githubsync-sites`. |
| **Exclude patterns** | One glob per line. `.git`, `.github`, `node_modules` and similar are excluded by default. |
| **Automatic deployment** | Whether a matching push should queue a job. |
| **Manual deployment** | Whether **Deploy Now** is offered. |

The wizard runs preflight checks before saving: source path shape, destination
policy, symlink escapes and collisions with existing unmanaged content. An unsafe
configuration is refused with an explanation, not saved silently.

### Path rules

- Source and destination paths are relative and must stay inside their roots.
  Absolute paths, `..` segments, encoded traversal, backslashes and NUL bytes are
  rejected.
- The destination must resolve inside the permitted base for its deployment type.
- A destination that already contains files GitHubSync did not create is flagged
  as a collision and requires an explicit decision.
- GitHubSync refuses to deploy over its own plugin directory.

### Protected files

These patterns are never written or deleted by a deployment, even if they exist
in the repository: the whole `.git/` and `.github/` directories; `.gitignore`,
`.gitattributes`, `.gitmodules`; every `.env` and `.env.*`; `wp-config.php`
anywhere in the tree; `.htpasswd`; private keys and certificates (`*.pem`,
`*.key`, `*.p12`, `*.pfx`, `id_rsa*`, `id_ed25519`, `.ssh/`); and OS clutter
(`.DS_Store`, `Thumbs.db`, `desktop.ini`).

The list is intentionally narrow and lives in `includes/Security/FileFilter.php`.
It is a blocklist of dangerous names, not a whitelist of safe ones.

## Configuring webhooks

For each deployment, the detail page shows the **Payload URL** and the
**Secret**. The URL looks like:

```
https://example.com/wp-json/githubsync/v1/webhook/<32-hex-token>
```

To set it up manually — the only supported way; GitHubSync never creates a
webhook through the API:

1. Open the repository on GitHub → **Settings → Webhooks → Add webhook**.
2. **Payload URL**: paste the URL from the deployment detail page exactly.
3. **Content type**: `application/json`. GitHubSync verifies the signature over
   the raw body, so this must not be changed.
4. **Secret**: paste the secret from the deployment page.
5. **Which events?**: *Just the push event*. Do not subscribe to every event.
6. Ensure **Active** is checked and save.

GitHub sends a `ping` on creation. GitHubSync answers `200 pong`, which confirms
the payload URL and signature are correct. For full detail see
[`docs/WEBHOOK-SETUP.md`](docs/WEBHOOK-SETUP.md).

## Manual deployment

**GitHubSync → Deployments → (a deployment) → Deploy Now**.

The highest commit on the configured branch is resolved to a SHA, then the same
pipeline runs as for a webhook: queue → download → validate → stage → backup →
verify → promote → verify. The job appears immediately in **History** with live
status.

Retry a failed job from the History row, or roll back to a previous successful
one with **Rollback**.

## Automatic deployment

When a `push` arrives for the configured repository and branch:

- The event must be a `push`; anything else answers `ignored_event`. A tag push
  fails the `refs/heads/<branch>` check (`branch_mismatch`), and a branch
  deletion answers `branch_deleted` without deploying.
- The repository ID must match the deployment (name matching alone is not
  trusted).
- An identical `X-GitHub-Delivery` ID does not create a second job.
- A job is queued and processed by WP-Cron via `githubsync_queue_tick`.

The webhook request returns immediately with `202` and a result such as
`queued`, `branch_mismatch`, `branch_deleted`, `repository_mismatch`,
`duplicate_delivery` or `ignored_event`.

### If WP-Cron is disabled

When `DISABLE_WP_CRON` is set, or WP-Cron proves unreliable, add a system cron
entry:

```cron
*/1 * * * * cd /path/to/wordpress && wp cron event run --due-now >/dev/null 2>&1
```

GitHubSync shows a warning on the **Dashboard** when it detects that WP-Cron is
disabled, and **Tools → Environment** reports the WP-Cron state alongside the
storage, working directory and table status.

## Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| Webhook returns `401 invalid_signature` | The secret on GitHub and the deployment do not match, or the wrong content type is set. Re-copy the secret and use `application/json`. |
| Webhook returns `404 unknown_endpoint` | The deployment was deleted or regenerated its token. Copy the payload URL again. |
| Webhook returns `202 branch_mismatch` / `repository_mismatch` | The push targets a different branch or repo than configured. |
| Webhook returns `202 branch_deleted` | The push deleted the deployed branch; nothing was deployed. |
| Webhook returns `429 rate_limited` | That IP has 30 failed signature checks, counted in a 5-minute window that each failure extends. Only `401` responses count — valid deliveries never are throttled. Wait for the window to lapse. |
| Webhook returns `413 payload_too_large` | The body exceeded `max_webhook_bytes` (default 1 MB). GitHub push payloads are normally far smaller; raise the setting if needed. |
| Webhook returns `500 endpoint_not_configured` | The deployment's encrypted secret cannot be decrypted (key changed or missing). Regenerate the secret and update the webhook on GitHub. |
| Job stays `queued` | WP-Cron is not running. Run `wp cron event run githubsync_queue_tick`, use **Tools → Run queue now**, or add the system cron above. |
| `GITHUB_NETWORK_ERROR` | The site cannot reach GitHub, or GitHub is not connected. Retried automatically. Check **GitHub Connection** and outbound HTTPS. |
| `GITHUB_RATE_LIMIT` | GitHub rate-limited the request. Retried automatically with backoff. |
| `AUTH_INVALID` / `AUTH_PERMISSION_DENIED` | The App installation or token was revoked, expired, or lacks **Contents: Read-only**. Reconnect, then retry. |
| `REPOSITORY_NOT_FOUND` / `BRANCH_NOT_FOUND` | The repository or branch is missing or not visible to the connection. |
| `SOURCE_PATH_MISSING` | The deployment's source path does not exist in that commit. |
| `ARCHIVE_TOO_LARGE` | Raise the limits in **Settings** (`max_archive_mb`, `max_uncompressed_mb`, `max_files`). |
| `ARCHIVE_INVALID` / `ARCHIVE_PATH_INVALID` | The download was not a readable archive, or an entry has an absolute path, `..` or NUL. Fix the repository, then retry. |
| `DESTINATION_NOT_ALLOWED` | The slug resolves outside `plugins/`, `themes/` or `uploads/githubsync-sites/`. |
| `DESTINATION_NOT_WRITABLE` | The web server user cannot write the destination or its parent. Check ownership and permissions. |
| `DISK_SPACE_LOW` / `BACKUP_FAILED` | Not enough space for staging or the backup. Free space and retry. |
| `PROMOTION_FAILED` | The atomic swap failed; the previous tree was restored. Check the log for the path. |
| `DEPLOYMENT_LOCKED` | Another job holds this deployment's lock. Retried; the lock expires on its own. |
| Deployment succeeded but the site looks unchanged | Object or page caching. Purge your cache; PHP opcache may need a restart. |

**Tools** shows storage usage (backups, uploads, temp), reports the working
directory, WP-Cron, Action Scheduler and table status, and offers **Run queue
now**, **Prune old backups**, **Clear logs**, and a credential-key regeneration
button. Logs never contain tokens or secrets. Locks are time-based and expire on
their own — there is nothing to clear by hand.

## Security notes

- Webhook signatures are verified with `hash_equals` over the raw body before
  the payload is parsed. Missing, malformed or empty signatures fail closed.- Credentials are encrypted at rest with libsodium (`v1:` sealed box; GitHub App
  private keys use `g1:`). Tampered, truncated or unreadable payloads raise an
  error and never degrade to plaintext.
- Archives are extracted with explicit entry validation: absolute paths, `..`,
  symlinks, hardlinks, device entries and oversized archives are rejected before
  any file is written.
- The deployment lock is per configuration, so two deployments cannot promote
  into the same destination at once. Stale locks expire and can be broken from
  **Tools**.
- Only users with `manage_options` can see the menu, trigger deployments or edit
  settings. Every admin action is nonce-protected and capability-checked.
- Files are written with `0644` and directories with `0755`; existing permission
  bits on deployed files are not preserved from the repository.
- Backups live in `wp-content/uploads/githubsync-backups/<deployment>/<job>-<sha>/`
  and are pruned to the configured retention (default 3).
- **Report vulnerabilities privately** — see
  [`docs/SECURITY.md`](docs/SECURITY.md#vulnerability-reporting). Please do not
  open a public issue for a security bug.

## Uninstall and data retention

**By default, uninstalling deletes nothing.** `uninstall.php` returns
immediately unless the site owner opted in, so deployments, history, settings
and the encrypted credentials all survive an accidental uninstall/reinstall.

To have the database cleaned up, tick **Settings → Delete GitHubSync tables and
settings when the plugin is uninstalled** before deleting the plugin. With that
option enabled, `uninstall.php` drops the four tables and deletes GitHubSync's
options, per-deployment locks and transients.

Either way, **deployed files and the backups in
`uploads/githubsync-backups/` are never deleted** — deliberate, since removing a
theme or plugin's files after a mistake is not recoverable. Remove them manually
if you want a clean slate.

The encryption key file (outside the web root) is also never deleted, so
credentials stay decryptable if you reinstall with the same key. If you want
them unrecoverable, delete the key file (or the `GITHUBSYNC_ENCRYPTION_KEY`
constant) after uninstalling.

## Documentation

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — layers, services, data flow.
- [`docs/SECURITY.md`](docs/SECURITY.md) — threat model and controls.
- [`docs/WEBHOOK-SETUP.md`](docs/WEBHOOK-SETUP.md) — exact webhook steps.
- [`docs/DEPLOYMENT-RECOVERY.md`](docs/DEPLOYMENT-RECOVERY.md) — backups, rollback, manual recovery.
- [`docs/TESTING.md`](docs/TESTING.md) — local test environment and commands.
- [`docs/SELF-UPDATE.md`](docs/SELF-UPDATE.md) — self-updates, the release manifest and publishing.
- [`docs/SPEC-COVERAGE.md`](docs/SPEC-COVERAGE.md) — feature-by-feature audit against the specification.

## Development

Run the test suite against a local WordPress where the plugin is active:

```bash
GITHUBSYNC_WP_ROOT="/path/to/wordpress" \
GITHUBSYNC_PLUGIN_DIR="/path/to/wordpress/wp-content/plugins/githubsync" \
php tests/run-tests.php
```

See [`docs/TESTING.md`](docs/TESTING.md) for what is covered and what is not.

Build a release archive with `./build.sh`. It lints every PHP file, copies the
plugin while honouring `.distignore` (no tests, docs, or key files), and writes
`dist/githubsync.zip` with a single top-level `githubsync/` folder.

### Publishing a release

The repository root *is* the plugin folder, so the same tree that CI installs
into `wp-content/plugins/githubsync` is the tree you zip.

Bump the version in **three** places so they cannot drift: `githubsync.php`
(`Version:` and `GITHUBSYNC_VERSION`), `readme.txt` (`Stable tag:`), and a new
`## [x.y.z] - YYYY-MM-DD` section in `CHANGELOG.md`. Commit, push `main` and wait
for CI. Then tag the same commit and push the tag:

```bash
git tag -a v0.1.9 -m "GitHubSync 0.1.9"
git push origin v0.1.9
```

The `release` job in `.github/workflows/ci.yml` then:

1. reruns the suite (`needs: lint-and-test`), so a red build never ships;
2. fails if the tag and the `githubsync.php` version disagree;
3. builds `githubsync.zip` with `./build.sh`;
4. creates the release for the tag in the public download repository
   [`digal2025/githubsync-releases`](https://github.com/digal2025/githubsync-releases),
   with the matching `CHANGELOG.md` section as its notes (the private source
   repository's assets cannot be downloaded anonymously, so they are not used);
5. attaches `githubsync.zip` and `update.json` (the self-update manifest,
   written by `.github/scripts/write-manifest.php` with the zip's SHA-256);
6. checks both are downloadable **without authentication** and fails if not.

The job needs the repository secret `RELEASES_TOKEN`: a fine-grained token for
`digal2025/githubsync-releases` with *Contents: Read and write*.

Do not create the release by hand as a draft: draft assets are private and
`releases/latest` skips drafts, so self-updates would not see it.

Sites pick the release up through the manifest at
`https://github.com/digal2025/githubsync-releases/releases/latest/download/update.json`.
See [`docs/SELF-UPDATE.md`](docs/SELF-UPDATE.md) for how the updater works, the
manifest format, the `GITHUBSYNC_UPDATE_URL` / `GITHUBSYNC_DISABLE_SELF_UPDATE`
constants and the manual fallback:

```bash
wp plugin install /path/to/githubsync.zip --force
# or: upload the zip from Plugins → Add New → Upload Plugin
```

## License

GPL-2.0-or-later.
