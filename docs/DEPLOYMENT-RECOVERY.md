# Deployment recovery

What to do when a deployment fails, when a site is left looking wrong, and how
to get it back.

## Guarantees

GitHubSync is built so that a failed deployment leaves the previous live files
in place:

- The archive is extracted into a **staging directory** next to the destination,
  never into it.
- Before anything is promoted, the current live tree is copied to a backup and
  every file is verified by SHA-256. `.githubsync-backup.json` is written only
  after verification passes.
- Promotion is a `rename()` within the same parent directory. If it fails, the
  previous tree is restored and the job records `PROMOTION_FAILED`.
- The job log records each step with a stable `event_code`, so a failure names
  the step that broke.

If a job ended `failed`, the live directory is what it was before that job
started. Verify by looking, though; a deployment is not a substitute for version
control of the deployed output.

## Reading a failed job

**GitHubSync → History** shows the job with its status, attempt count and error
code. Codes come from `Support/ErrorCodes.php`. Retries use exponential backoff
(roughly 30 s, then 60 s, then 120 s, plus up to 15 s of jitter) up to
`max_attempts` (default 3), and **only four codes are retryable** —
`GITHUB_RATE_LIMIT`, `GITHUB_NETWORK_ERROR`, `DEPLOYMENT_LOCKED`,
`QUEUE_UNAVAILABLE`. Every other code ends the job immediately, because
retrying a security or validation failure cannot succeed. (`QUEUE_UNAVAILABLE`
is on the retryable list but this build never raises it — scheduling failures
surface through WP-Cron's status instead.)

| Code | What it means | Action |
| --- | --- | --- |
| `GITHUB_NETWORK_ERROR` | No route to GitHub, DNS, TLS, timeout — or GitHub is not connected. Retried. | Check **GitHub Connection** and outbound HTTPS. |
| `GITHUB_RATE_LIMIT` | GitHub's API or codeload rate limit. Retried. | Wait; large or repeated deploys share the unauthenticated allowance if the token is invalid. |
| `DEPLOYMENT_LOCKED` | Another job holds this deployment's lock. Retried. | Let it finish. The lock expires on its own. |
| `AUTH_INVALID` | Token or App private key rejected, expired or revoked. | Reconnect in **GitHub Connection**, then retry. |
| `AUTH_PERMISSION_DENIED` | The connection cannot see or read this repository. | Grant **Contents: Read-only** (App: install it on the repo) and retry. |
| `REPOSITORY_NOT_FOUND` | Repo missing, renamed, or invisible to the token. | Fix the repository on the deployment. |
| `BRANCH_NOT_FOUND` | Branch missing or renamed. | Fix the branch, or push the branch first. |
| `SOURCE_PATH_MISSING` | The configured source path does not exist in that commit. | Correct the source path (empty means repo root). |
| `ARCHIVE_TOO_LARGE` | Archive above `max_archive_mb`, or expanded above `max_uncompressed_mb`, or more than `max_files`. | Raise the limits in **Settings**, or slim the repo. |
| `ARCHIVE_INVALID` | The download was not a readable archive (GitHub error page, truncated body). | Retry from History; if it persists, check outbound proxy filtering. |
| `ARCHIVE_PATH_INVALID` | An entry has an absolute path, `..`, backslash or NUL. | Remove the bad entry from the repository — Git otherwise stores such names happily. |
| `DESTINATION_NOT_ALLOWED` | Destination escapes the approved root, the slug is invalid, or it targets GitHubSync's own directory. | Choose a slug under `plugins/`, `themes/` or `uploads/githubsync-sites/`. |
| `DESTINATION_NOT_WRITABLE` | The web user cannot write the destination or its parent. | Fix ownership/permissions, then retry. |
| `DISK_SPACE_LOW` | Not enough free space for staging, the backup or the work directory. | Free space, retry. |
| `BACKUP_FAILED` | Could not copy or verify the live tree (disk space, the tree changed mid-copy, permissions). | Free space and retry. Never promote by hand to "skip" this. |
| `PROMOTION_FAILED` | The atomic swap failed; the previous tree was restored. | Usually permissions or a cross-filesystem destination. Check the log for the path. |
| `ROLLBACK_FAILED` | The backup source job is not `succeeded`/its backup is gone, or the requested GitHub commit is invalid or not in the branch history. | Choose a different restore point or commit. |
| `LIMIT_EXCEEDED` | A configured limit was hit outside the archive checks. | Review **Settings**. |
| `CONFIG_INVALID` | The deployment row is invalid (bad slug, type, repository name). | Edit the deployment; saving re-runs validation. |
| `ENCRYPTION_UNAVAILABLE` | The vault cannot decrypt with the current key. | Restore the key file or `GITHUBSYNC_ENCRYPTION_KEY`, then reconnect GitHub. |
| `INTERNAL_ERROR` | Unexpected state (work directory not creatable, unexpected response). | Check that the temp/work directory is writable and there is disk space. |

Webhook-level codes (`WEBHOOK_SIGNATURE_INVALID`, `WEBHOOK_DUPLICATE`) appear in
the log, not on jobs; see `docs/WEBHOOK-SETUP.md`.

## Rolling back

The deployment detail page offers two paths back, both one click:

- **GitHub history** lists the latest commits on the configured branch. **Roll
  back** on an older commit queues a deployment of that exact SHA. The SHA is
  checked against the branch's recent commits before the job is accepted, and
  the normal archive pipeline runs: download → validate → stage → back up the
  live files → promote. The job is recorded with `trigger_type = rollback` and
  no `rollback_of`.
- **Backup restore points** lists earlier successful jobs whose local backup is
  still present. **Roll back to this** restores those files without a GitHub
  request:

  1. take the backup recorded for *that* job
     (`uploads/githubsync-backups/<deployment>/<job>-<sha>/`);
  2. copy it into a fresh staging directory;
  3. back up the current live tree, so the rollback is itself reversible;
  4. promote with the same atomic swap as a normal deploy;
  5. record a job with `trigger_type = rollback` and `rollback_of = <job id>`.

Requirements for a backup restore: the target job must be `succeeded`, and its
backup directory must still exist with its manifest. Backups are pruned to
`backup_retention` (default 3, clamped 1–20) after each successful deployment.
Use **Clear old backups** on **GitHubSync → Settings → Backups** or
**GitHubSync → Tools → Maintenance** to remove older backups on demand; the
newest `backup_retention` backups stay in place. **GitHubSync → History → Clear
history** removes finished job records and their log entries, but keeps active
jobs, deployed files and every retained backup. When a backup has been cleared,
use the GitHub history path instead.

## Backups on disk

```
wp-content/uploads/githubsync-backups/
└── <deployment id>/
    └── <job id>-<first 8 chars of sha>/
        ├── .githubsync-backup.json   manifest: file count, bytes
        └── <the live tree as it was>
```

A directory without `.githubsync-backup.json` is not a verified backup and will
be refused as a rollback source.

## Manual recovery

Only when the plugin cannot do it. Each step assumes the previous one is
unavailable.

1. **Roll back from GitHubSync** if a target job is still listed as succeeded.
   Always preferable: hashes are verified and permissions are written
   consistently.
2. **Copy a backup by hand.** Pause the deployment first so no job starts
   mid-copy, then:
   ```bash
   cd wp-content/uploads/githubsync-backups/12
   ls -la                                   # choose the <job>-<sha>
   rsync -a --delete 34-1a2b3c4d/ /path/to/wp-content/plugins/my-plugin/
   ```
   `--delete` removes files the newer version added. Take your own copy of the
   live tree first — this is the step where a mistake is permanent.
3. **Restore from Git.** With a checkout of the repository:
   ```bash
   git archive --format=tar <commit> | tar -x -C /path/to/destination
   ```
   This adds and overwrites, but does not delete files added by the newer
   version. Remove the destination first, or extract into a temp dir and
   `rsync -a --delete`.
4. **Take the destination down.** If a broken theme or plugin is fataling the
   site, deactivate it (`wp plugin deactivate my-plugin`) or rename its folder,
   and fix it out of band. An inactive broken plugin cannot take the site down.

After any manual repair, run **Deploy Now** once so GitHubSync's record and the
live tree agree again, and confirm the new job succeeds.

## Locks

Each deployment has an advisory lock so two jobs cannot promote into the same
destination at once. The lock is stored as an expiring option with a heartbeat:
if a worker dies mid-deployment, the lock lapses on its own and the next attempt
proceeds. There is nothing to clear by hand and no "break lock" button.

If jobs keep reporting `DEPLOYMENT_LOCKED`, open **Tools → Maintenance**:
**Run queue now** performs one queue tick and is safe to press repeatedly. Stuck
jobs are reclaimed by the hourly recovery tick: a job still marked `running`
after an hour is marked `failed` with `INTERNAL_ERROR` ("Job was abandoned by a
previous request."), because a worker died holding it. A `queued` job is not
touched by that sweep — it simply runs on the next tick.

## Stuck queue

If a job has been `queued` for more than a couple of minutes:

1. **Tools → Environment** — is WP-Cron reported as *Disabled*? The Dashboard
   shows the same warning. If `DISABLE_WP_CRON` is true, add the system cron
   entry described in [`README.md`](../README.md#automatic-deployment).
2. **Tools → Run queue now** to force a tick.
3. With WP-CLI:
   ```bash
   wp cron event run githubsync_queue_tick
   ```
4. Read the job's error code in **History** and act on the table above.
