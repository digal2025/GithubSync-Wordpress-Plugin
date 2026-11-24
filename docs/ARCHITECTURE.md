# GitHubSync architecture

This document describes the implemented structure of the plugin, the
responsibilities of each service and how a push travels from GitHub to a live
directory.

## Compatibility baseline

| Item | Value |
| --- | --- |
| Plugin version | 0.1.0 |
| Minimum WordPress | 6.2 |
| Minimum PHP | 7.4 (developed and tested on 8.4) |
| Autoloading | hand-rolled PSR-4 (`GitHubSync\Core\Autoloader`), no Composer at runtime |
| Tables | 4, created by `GitHubSync\Database\Schema` |
| Queue | WP-Cron (`githubsync_queue_tick`) with a documented system-cron fallback |

## Design decisions

### Authentication: GitHub App preferred, PAT supported

The GitHub App flow mints short-lived installation tokens from a locally stored
RSA private key, so no long-lived token is written to the database. A personal
access token is offered as a fallback for hosts where installing an App is not
practical. Both paths converge on `GitHubAuth::get_token()`, so the API client
does not care which is in use.

### Queue: WP-Cron, not Action Scheduler

Action Scheduler is bundled with WooCommerce but not guaranteed to exist. Making
it a hard dependency would either force a second copy or fail activation on
sites without it. WP-Cron is universal, and running the same hook from a system
cron entry is a documented, reliable escape hatch for low-traffic sites.
Jobs are idempotent and re-entrant, so a duplicated tick cannot double-deploy.

### Filesystem: staging beside the destination, then atomic rename

Extracting directly into the live directory means a failure half-way through
leaves the site broken. Instead the archive is extracted into a staging
directory in the *same parent* as the destination, so the final promotion is a
`rename()` on one filesystem — effectively atomic. The previous tree is moved
aside first and kept until the new tree is in place.

## Layers

```
githubsync.php                 entry file, constants, hooks, bootstrap
└── Core/
    ├── Autoloader.php         PSR-4 GitHubSync\ → includes/
    ├── Plugin.php             wires hooks, cron, REST, admin
    ├── Activator.php          schema install, defaults, cron scheduling
    └── Deactivator.php        clears scheduled events
```

### Presentation — `Admin/`

| Class | Responsibility |
| --- | --- |
| `AdminMenu.php` | Registers the top-level menu and its seven submenus under `manage_options`. |
| `DashboardPage.php` | Deployment summary, last run status, connection state, warnings. |
| `DeploymentListPage.php` | Deployment table with status and actions. |
| `DeploymentFormPage.php` | Add/edit wizard with preflight validation. |
| `DeploymentDetailPage.php` | Webhook URL/secret, recent jobs, Deploy Now, Rollback. |
| `HistoryPage.php` | Job history with logs, retry and rollback. |
| `ConnectionPage.php` | GitHub App and PAT connection, disconnect. |
| `SettingsPage.php` | Limits, retention, uninstall behaviour, system status. |
| `ToolsPage.php` | Environment checks, queue diagnostics, cleanup actions. |
| `Actions.php` | Nonce- and capability-checked handlers for every admin POST. |
| `Assets.php` | Enqueues CSS/JS only on plugin pages. |

Templates live in `templates/`; pages prepare data and `include` a template.

### Web layer — `Webhooks/WebhookController.php`

`WebhookController::register_routes()` is the only `register_rest_route()` call
in the plugin. It registers the `githubsync/v1` namespace and the
`/webhook/(?P<token>[a-f0-9]{32})` route (token validated against the regex in
`args`) with `permission_callback` returning true — the token *is* the
authentication; the signature check happens inside the handler, over the raw
body, before the payload is parsed.

### Domain

| Class | Responsibility |
| --- | --- |
| `Domain/DeploymentConfiguration.php` | Typed view of a deployment row: validation, defaults, serialisation. |
| `Domain/DeploymentStatus.php` | Allowed statuses and transitions for deployments and jobs. |
| `Domain/DestinationPolicy.php` | Permitted roots per deployment type, slug resolution, collision detection. |
| `Domain/Exceptions/GitHubSyncException.php` | Exception carrying a stable `get_error_code()`. |
| `Support/ErrorCodes.php` | The single list of machine-readable error codes. |

### Security — `Security/`

| Class | Responsibility |
| --- | --- |
| `Webhooks/SignatureVerifier.php` | `sha256=` HMAC over the raw body, `hash_equals`, fail-closed. |
| `CredentialVault.php` | libsodium encryption at rest, key discovery, fail-closed decryption. |
| `PathGuard.php` | Normalisation, traversal rejection, containment and symlink resolution. |
| `FileFilter.php` | Protected paths, default and custom excludes. |
| `InputValidator.php` | Repository names, branches, slugs, source paths, numeric IDs. |
| `Capability.php` | Capability constants and checks. |

### Deployment engine — `Deployment/`

| Class | Responsibility |
| --- | --- |
| `DeploymentManager.php` | Orchestrates one job: resolve SHA → download → extract → stage → backup → promote → verify → record. Also exposes `deploy_now()`, `retry()`, `rollback()`. |
| `ArchiveDownloader.php` | Streams the commit archive from codeload with size limits and timeouts. |
| `SafeArchiveExtractor.php` | Validates every entry (count, size, traversal, symlink, NUL) then extracts to staging. |
| `StagingManager.php` | Creates, seals and disposes staging directories next to the destination. |
| `BackupManager.php` | Copies the live tree, hash-verifies it, writes a manifest, prunes old backups. |
| `PromotionManager.php` | Atomic swap of staging into the destination; restore from backup with rollback-on-failure. |
| `DeploymentLock.php` | Per-deployment advisory lock with heartbeat and stale-lock stealing. |

### Queue — `Queue/Queue.php`

`enqueue()` deduplicates on (deployment, SHA, active status), `process_due()`
pulls due jobs and hands them to `DeploymentManager`. Retries use exponential
backoff up to `max_attempts`; permanent errors (auth, invalid config) are not
retried.

### Data — `Database/`

`Schema.php` owns table creation and versioned upgrades. `DeploymentRepository`,
`JobRepository`, `LogRepository` and `DeliveryRepository` wrap `$wpdb` with
prepared statements and no raw interpolation of user input.

### Filesystem — `Filesystem/FilesystemAdapter.php`

All file mutations go through this single class so permission modes, path
containment within an allowed root, and cleanup semantics are enforced in one
place.

## Data flow: push to live files

```
GitHub push
   │
   ▼
POST /wp-json/githubsync/v1/webhook/<token>
   │  WebhookController::register_routes() → handle()
   ├─ 1. token → deployment row          (404 unknown_endpoint)
   ├─ 2. rate limit                      (429)
   ├─ 3. body size limit                 (413)
   ├─ 4. SignatureVerifier on raw body   (401 invalid_signature)
   ├─ 5. event type: ping→200, push only (202 ignored_event)
   ├─ 6. delivery dedupe                 (202 duplicate_delivery)
   └─ 7. repository id + branch check    (202 repository_mismatch / branch_mismatch)
          │
          ▼  202 queued
   Queue::enqueue(deployment, sha)
          │
   ── WP-Cron: githubsync_queue_tick ──
          ▼
   DeploymentManager::run_job()
   ├─ DeploymentLock::acquire
   ├─ GitHubAuth token → ArchiveDownloader (immutable SHA)
   ├─ SafeArchiveExtractor → StagingManager path
   ├─ FileFilter + PathGuard classify files
   ├─ BackupManager::create + verify
   ├─ PromotionManager::promote (atomic rename)
   ├─ post-deploy verification
   └─ JobRepository + LogRepository + DeploymentLock::release
```

## Database schema

| Table | Purpose | Key columns |
| --- | --- | --- |
| `wp_githubsync_deployments` | Configurations | `github_repository_id`, `repository_full_name`, `branch`, `source_path`, `destination_path`, `deployment_type`, `destination_slug`, `exclude_patterns`, `auto_deploy`, `manual_deploy`, `webhook_token`, `webhook_secret_encrypted`, `status` |
| `wp_githubsync_jobs` | Deployment attempts | `deployment_id`, `commit_sha`, `trigger_type`, `status`, `attempt_count`, `max_attempts`, `scheduled_at`, `lock_token`, `rollback_of`, counters, `error_code`, `error_message` |
| `wp_githubsync_logs` | Structured log lines | `deployment_id`, `job_id`, `level`, `event_code`, `message`, `context_json` |
| `wp_githubsync_deliveries` | Webhook dedupe | `delivery_id` (unique), `deployment_id`, `event_type`, `processing_status` |

Only `deployment_id` and `job_id` from these tables are ever signed against a
token, so a leaked delivery row cannot be replayed.

## Directory layout

```
githubsync/
├── githubsync.php
├── uninstall.php
├── readme.txt
├── assets/            css/js
├── includes/
│   ├── Admin/         pages + actions
│   ├── Core/          bootstrap, autoloader, activation
│   ├── Database/      schema + repositories
│   ├── Deployment/    engine
│   ├── Domain/        models, policy, exceptions
│   ├── Filesystem/    adapter
│   ├── GitHub/        auth + API client
│   ├── Queue/         cron queue
│   ├── Security/      vault, guard, filter, validator, signature
│   ├── Support/       helpers, settings, error codes
│   └── Webhooks/      webhook controller
├── templates/
└── tests/             reconstructed suite (excluded from release ZIP)
```

## Deviation from the specification's proposed layout

Section 5.3 of the specification permits structural adjustments provided the
result is modular and documented. This build uses a flatter tree than the
sketch:

| Spec sketch | This build | Why |
| --- | --- | --- |
| `Application/`, `Container.php` | `Core/Plugin.php` with explicit wiring | A container with a dozen services adds indirection without a testing payoff. |
| `Webhooks/DeliveryDeduplicator.php` | `Database/DeliveryRepository.php` | Dedupe is enforced by a unique index, which is a persistence concern. |
| `Queue/ActionSchedulerQueue`, `Queue/WPCronQueue` | `Queue/Queue.php` | Only one mechanism is implemented; a second is a system-cron trigger of the same hook. |
| `Domain/Deployment` model | `Domain/DeploymentConfiguration.php` | Names the object for what it holds. |

Both the class responsibilities and the data flow above mirror the
specification.
