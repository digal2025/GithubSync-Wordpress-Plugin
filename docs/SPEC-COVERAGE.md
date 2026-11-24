# Spec coverage audit

Audit of the shipped plugin against `GithubSync.md` (the source of truth). Every
verdict below was checked against the code in `includes/` and, where noted,
against a running WordPress install. This file is deliberately blunt: it records
what is **not** implemented as well as what is.

Verdicts: **Implemented** (behaves as the spec describes), **Partial** (exists
but a listed sub-requirement is missing or weaker), **Missing** (no code path).

## §3.1 GitHub connection and authentication — Partial

| Requirement | Verdict | Evidence |
| --- | --- | --- |
| "Connect GitHub" action in admin | Implemented | `Admin/ConnectionPage.php`, `Admin/Actions.php::save_connection_app` / `::save_connection_pat` |
| Prefer GitHub App installation auth | Implemented | `GitHub/GitHubAuth::connect_app()`, `installation_token()` (cached transient), `app_jwt()` |
| Private repositories via installation access | Implemented | `GitHubApiClient::repositories()` uses the installation token for App mode |
| OAuth only if App flow is not feasible | Implemented (by omission) | No OAuth flow at all |
| `state` + one-time expiring nonce on the callback | **Missing** | There is no callback. The browse view uses a `githubsync_browse` nonce; nothing CSRF-protects a redirect |
| Constant-time comparison of callback state | **Missing** | No state value exists |
| Validate all callback parameters | Missing (n/a) | No callback parameters |
| Never expose tokens in HTML/JS/URLs/logs/REST | Implemented | `08-github-auth.php` asserts the token is absent from option metadata and log rows; `GitHubApiClient` only sends it in the `Authorization` header |
| Credentials via a dedicated service | Implemented | `Security/CredentialVault.php` (fail-closed, `v1:` sodium / `g1:` AES-256-GCM) |
| Authenticated encryption at rest | Implemented | Same |
| Key derived from a site secret outside the database | Implemented | `GITHUBSYNC_ENCRYPTION_KEY` constant or a `0600` key file outside the docroot |
| Fail closed without a key, no weak fallback | Implemented | `CredentialVault::from_site()` returns `CONFIG_INVALID`; verified by hand |
| Disconnect removes credentials | Implemented | `disconnect()` deletes both options |
| Disconnect **revokes** tokens when the API supports it | **Missing** | `disconnect()` performs no API call. An App installation is not uninstalled and a PAT is not revoked; the operator must revoke it on GitHub |
| Show connection status/identity without secrets | Implemented | `GitHubAuth::summary()` |
| Actionable messages for expired/revoked/insufficient permission | Implemented | `AUTH_INVALID` ("invalid or have been revoked. Reconnect GitHub.") and `AUTH_PERMISSION_DENIED` ("lacks permission for this repository") in `Support/ErrorCodes.php`; there is no separate `AUTH_EXPIRED` code, expiry surfaces as `AUTH_INVALID` |
| Refresh tokens where the mechanism provides them | Partial | App installation tokens are re-minted on expiry (`installation_token()`); there is no OAuth refresh-token support, because there is no OAuth |

**Summary:** the security-relevant half (storage, encryption, no-leak, fail
closed) is solid. The **OAuth-based connect flow described in §3.1 does not
exist**; the plugin uses a GitHub App (App ID + installation ID + PEM) or a PAT,
which is the spec's *preferred* mechanism, so the CSRF/state requirements are
moot rather than violated. The real gap is **revocation on disconnect**.

## §3.2 Repository discovery — Implemented

Pagination (`repositories( $page, $per_page )` with `has_next`), name/owner/
visibility/default branch columns, browser-side filter, 5-minute transient cache
with a refresh action (`githubsync_browse` + `refresh`), rate-limit detection
(`GitHubResponse::is_rate_limited()`, `GITHUB_RATE_LIMIT`), and owner/name taken
from GitHub API data with `github_repository_id` stored alongside it.

## §3.3 Branch selection — Implemented

`DeploymentFormPage` pulls branches from the API (cached 5 min) and
`InputValidator::branch()` enforces `git check-ref-format` rules, with two
cosmetic gaps recorded in `TESTING.md` (leading `-`, trailing space trimmed).

## §3.4 Deployment configuration — Implemented, one deviation

Source path, the three destination roots, canonicalisation, symlink escape
refusal, "one deployment per destination", a `.githubsync-owner.json` ownership
marker and an explicit **Adopt existing directory** checkbox are all present
(`Domain/DestinationPolicy.php`, `Admin/Actions.php::save_deployment`).
Include/exclude defaults and administrator patterns live in `FileFilter`, which
also applies to stale-file deletion, and protected files cannot be deleted.

Deviation: the spec lists `webhook_id` / `webhook_status` and separate
manual/automatic enable flags. The shipped schema stores `auto_deploy` and a
per-deployment `webhook_token`/`webhook_secret_encrypted` instead of a GitHub
webhook id, because **the plugin never creates hooks via the API** (manual setup
only — see §3.5).

## §3.5 Automatic deployment — Implemented (manual webhook setup)

`Webhooks/WebhookController.php` on `/githubsync/v1/webhook/<token>`: raw-body
HMAC-SHA256 check (`SignatureVerifier`, uppercase hex accepted, tolerant of both
formats), `X-GitHub-Delivery` dedupe, `ping` → `pong`, `branch_mismatch`,
`repository_mismatch`, `branch_deleted`, `ignored_event`, per-IP rate limiting on
failed signatures (30 failures / self-extending 5-minute window), and
`202 queued` otherwise. All of these were exercised by hand over HTTP.

## §3.6 Manual deployment — Implemented

`Deploy now` admin action with capability + nonce, a confirmation screen showing
repository/branch/source/destination, SHA resolved at deploy time, duplicate
active jobs refused by the queue and the lock.

## §3.7 Deployment engine — Implemented

Lock → validate → resolve SHA → archive by immutable SHA → temp dir → size/time
limits → format validation → safe extraction (`SafeArchiveExtractor`) → expected
root and source-path verification → protected exclusions → count/size limits →
destination + `free_space()` check → backup → same-filesystem staging → promotion
(`PromotionManager`) → record SHA/result → release lock and clean up.

File permissions: extracted files `0644`, created directories `0755`; nothing is
made world-writable.

## §3.8 Background processing — Implemented

`Queue` uses single `wp_schedule_single_event` jobs plus a 5-minute
`githubsync_queue_tick` safety net, unique job ids and statuses, a duplicate-
active-job refusal, `DeploymentLock` with expiry and `recover_stale()` (1 hour),
bounded attempts (`max_attempts`, default 3), retry only for the four retryable
codes, exponential backoff with jitter (`30 * 2^(n-1)` capped at 300 s, plus
0–15 s), an admin retry action, SHA pinned at enqueue time, a `DISABLE_WP_CRON`
admin warning, and a bounded `process_due( $limit = 3 )`.

## §3.9 History, logs, retry, rollback — Partial

Per-deployment and per-job logs (`Database/LogRepository`), the last 200 jobs
with outcomes and error details on the History page, retry and rollback are all
present. **Deviation:** the History page lists the 200 most recent jobs with no
filter controls (no per-deployment / per-status / date filters), and log retention
is a fixed `log_retention_days` setting swept by `githubsync_cleanup`.

## §3.10 Rollback — Implemented

`DeploymentManager::rollback()` queues a `rollback` job pointing at a previous
successful job; `execute_rollback()` re-deploys that exact SHA and snapshots the
current live tree first, so a rollback is itself reversible.

## §3.11 Notifications — Implemented (MVP scope)

`admin_notices` for connection problems, `DISABLE_WP_CRON`, multisite and
deployment outcomes; status is on the dashboard. Email/Slack are explicitly
"optional later" in the spec and are absent.

## §4 User interface — Implemented

Seven submenus (Dashboard, Deployments, Add/Edit, Detail, History, Connection,
Tools, Settings). Capability checks and nonces on every action, escaped output,
confirmations on destructive actions. UI security was verified by rendering each
page and checking for PHP warnings, not by automated tests.

## §6.6 Uninstall retention — Implemented

`uninstall.php` keeps all tables, options and backup files by default and only
drops the four `wp_githubsync_*` tables plus options when the opt-in
`githubsync_delete_data_on_uninstall` setting is on.

## §7.3 Archive download — Implemented

`archive_url()` builds `ZipBall` URLs pinned to a commit SHA; `validate_download_url()`
enforces HTTPS, the `codeload.github.com`/`api.github.com` hosts and a redirect
cap. No live download was performed during this audit.

## §14 Quality / §16 acceptance — **Not met**

`IMPLEMENTATION-PLAN.md`, `composer.json`, `phpcs.xml.dist`,
`phpstan.neon.dist` and `phpunit.xml.dist` are absent. PHPCS, PHPStan and PHPUnit
were never run (Composer is not installed here). CI is written but has never
executed. The acceptance criteria for connection, configuration, manual and
automatic deployment were verified by hand and by the reconstructed suite; the
**Quality** criteria are not satisfied.

## §17 Milestones — all functional milestones implemented

Milestones 0–11 are represented in code, with two caveats: milestone 0's
`IMPLEMENTATION-PLAN.md` does not exist (the file was lost and not reconstructed),
and milestone 3's "OAuth" wording is satisfied by the App/PAT flow described in
§3.1 above.

## Reconstructed test suite

329 assertions, run twice back to back, 329 passed / 0 failed / 0 skipped, PHP
8.4.8, WordPress 7.1.5. See `TESTING.md` for what is and is not covered, and for
the `FINDING:` assertions that document current behaviour.
