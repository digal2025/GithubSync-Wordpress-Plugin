# Testing

## What these tests are

The original test suite for GitHubSync was lost. The suite in `tests/` was
**reconstructed from `GithubSync.md`** against the recovered source, so it is not
the suite that was originally written — treat it as a second pair of eyes on the
security-critical code, not as proof that everything in the spec is exercised.

It is a plain-PHP suite with no Composer, PHPUnit or WordPress test library:

- `tests/bootstrap.php` loads a **real WordPress installation** (`wp-load.php`),
  refuses to run if `GITHUBSYNC_PLUGIN_DIR` is not the *active* plugin, and
  defines a scratch directory under `sys_get_temp_dir()`.
- `tests/lib/TestCase.php` is a small assertion harness
  (`CaseRunner::group()`, `::case()`, `Assert::…`, plus `mktmp()`, `rmtree()` and
  an exception helper) and prints a pass/fail/skip summary.
- `tests/cases/*.php` are the test groups; `tests/run-tests.php` loads them all
  in filename order and exits non-zero on failure.

## Running it

The tests run against the live active plugin directory, not a copy.

```bash
GITHUBSYNC_WP_ROOT="/path/to/wordpress" \
GITHUBSYNC_PLUGIN_DIR="/path/to/wordpress/wp-content/plugins/githubsync" \
php -d mysqli.default_socket="/path/to/mysqld.sock" \
    tests/run-tests.php
```

`mysqli.default_socket` is only needed when the database is reached over a
non-default socket (Local by Flywheel puts the socket under
`~/Library/Application Support/Local/run/<site>/mysql/mysqld.sock`). On a normal
server without this flag the tests run unchanged.

Exit codes: `0` pass, `1` at least one assertion failed, `2` the environment was
wrong (no WordPress root, plugin not active, or the plugin path does not match
the active plugin). Failures and environment-dependent skips are listed at the
end of the output.

Tests create their fixtures under a per-process temp directory and remove what
they create. `07-backup-promotion.php` is the exception: `BackupManager` writes
into the site's real `uploads/githubsync-backups/`, so that case uses
deployment ids derived from the process id and deletes those directories again
on the way out. The suite is therefore **repeatable** — running it twice in a
row must give the same result, and the backup directory must contain only
`index.php` and `.htaccess` when it finishes. It does not touch the deployment
tables unless a case explicitly says so, and it never calls the GitHub API.

## Current results

Last run, PHP 8.4.8, WordPress 7.1.5, on a Local by Flywheel site with the plugin
active. The command was run twice consecutively; both runs were identical, which
is what the repeatability guarantee above means in practice:

```
GitHubSync reconstructed test suite
PHP 8.4.8 | WordPress 7.1.5

--------------------------------------------
Assertions: 329  Passed: 329  Failed: 0  Skipped: 0

RESULT: PASS
```

| File | Covers |
| --- | --- |
| `01-signature.php` | `SignatureVerifier`: HMAC-SHA256 over the raw body, uppercase hex accepted, wrong secret, tampered body, missing/empty/malformed header, missing secret, and the secret generator. |
| `02-path-safety.php` | `PathGuard` (`normalize`, `is_safe_relative`, `is_inside`, `join`, `resolves_inside`) and `SafeArchiveExtractor`: Zip Slip, absolute paths, NUL bytes, symlinks, devices/FIFOs/sockets, file-count and size limits, zipball wrapper stripping. |
| `03-file-filter.php` | `FileFilter`: the blocklist (`.git/`, `.env`, `wp-config.php`, key material, OS clutter), nested paths, case-insensitivity. |
| `04-credential-vault.php` | `CredentialVault`: `v1:`/`g1:` round-trip, ciphertext differs per write, rejection of tampered, truncated and garbage payloads, 32-byte key requirement. |
| `05-deployment-lock.php` | `DeploymentLock`: acquire/release, second acquirer is refused, expiry lets a new holder in. |
| `06-input-destination.php` | `InputValidator` and `DestinationPolicy`: repository names, branches, slugs, and the approved destination roots. |
| `07-backup-promotion.php` | `BackupManager` → `PromotionManager` → rollback against a real filesystem, including corrupted-backup refusal and pruning. |
| `08-github-auth.php` | `GitHubAuth` state machine and credential storage (PAT/App validation, encrypted options, disconnect), `GitHubApiClient::to_error()` status→code mapping, rate-limit detection, and a `pre_http_request` spy proving the bearer token is sent but never written to a log row. |

The suite also records **`FINDING:`** assertions — places where the code does
something the spec does not justify, kept as passing tests that document the
current behaviour:

- `CredentialVault::encrypt( '' )` produces a payload that `decrypt()` cannot
  read back.
- `InputValidator::repository_full_name( "owner/repo\n" )` trims trailing
  whitespace rather than rejecting it.
- `InputValidator::branch( '-main' )` accepts a leading dash (a valid Git ref
  name, but easy to mistake for an argument).
- `InputValidator::branch( 'main ' )` trims.
- `ErrorCodes::retryable()` takes no argument and returns the *list* of
  retryable codes; the predicate is `is_retryable()`. Calling
  `retryable( $code )` returns a non-empty array for every input, so a caller
  that reads the name as a predicate would retry permanent failures. Nothing in
  the plugin does this today, but the name is a trap.
- `GitHubAuth::connect_pat()` only accepts `[A-Za-z0-9_]` after the prefix, so a
  token containing `-` is refused. Current GitHub token formats do not use `-`,
  so this is conservative rather than broken — it would reject a token if the
  format ever changes.
- **No OAuth flow exists.** Spec §3.1 describes an OAuth redirect/callback with
  a `state` parameter; the implementation offers a GitHub App connection (App ID
  + installation ID + private key) and a PAT instead. `disconnect()` deletes the
  stored credentials but does not revoke the installation on GitHub's side, and
  reconnecting is a form submission, not a callback. See "Not covered" below.

## Not covered

Be honest with yourself about the gaps before shipping:

- **No live GitHub calls.** Nothing downloads a release asset, calls
  `/repos/{owner}/{repo}`, or exchanges an App installation token. Those paths
  are only exercised through their error branches.
- **No webhook HTTP test in the suite.** Handler behaviour (`401`, `404`, `413`,
  `429`, `500 endpoint_not_configured`, `pong`, `queued`, `branch_mismatch`,
  `repository_mismatch`, `branch_deleted`, `duplicate_delivery`) was verified by
  hand against `/wp-json/githubsync/v1/webhook/<token>`; it is not automated
  here.
- **No admin UI tests.** The pages were rendered manually and checked for PHP
  warnings; there are no assertions on markup or on nonce handling.
- **No multi-site testing.** Behaviour on a network install is untested.
- **No concurrency testing.** The lock is tested single-threaded; two real
  workers racing on one deployment is not reproduced.
- **No static analysis or coding-standards run.** PHPCS, PHPStan and
  `composer.json` are not present, so spec §14 is not met. Every PHP file is
  linted (`php -l`, in `build.sh` and in CI) but that is not a standards check.
- **CI is unproven.** `.github/workflows/ci.yml` exists and is written to lint,
  install WordPress from wordpress.org, activate the plugin, build the archive
  and run this suite across PHP 7.4/8.1/8.3 and WordPress 6.2/latest. It has
  **never been executed** — it was added without a GitHub remote, so treat the
  first run as a debugging session, not a guarantee.
- **Windows** paths are untested.

## Adding a test

Drop a new file in `tests/cases/` — it is `require`d automatically in
alphabetical order. Use the existing files as the pattern:

```php
CaseRunner::group( 'My area' );

CaseRunner::case( 'does the thing' );
Assert::true( $actual, 'human-readable expectation' );
```

Anything needing the database should create and remove its own rows, and use
`mktmp()`/`rmtree()` for filesystem work so a failed assertion does not leave
files behind.
