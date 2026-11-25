# Self-updates

GitHubSync can update itself from its own GitHub releases, the same way a
WordPress.org plugin updates. You do not need to download a zip and upload it by
hand when a new version is published.

## How it works

WordPress only asks WordPress.org for plugin updates. GitHubSync answers for
itself on the standard core filters, so nothing beside WordPress is involved:

| Filter | What GitHubSync adds |
| --- | --- |
| `pre_set_site_transient_update_plugins` | The new version on the Plugins screen and on Dashboard → Updates. |
| `plugins_api` (`plugin_information`) | The "View version details" dialog, including the release notes. |
| `upgrader_source_selection` | Renames the extracted folder to `githubsync` so the swap works. |

Three more pieces support this:

- A **manifest** (`update.json`) tells the plugin which version exists and where
  to download it.
- A **twice-daily WP-Cron check** (`githubsync_update_check`) fetches the
  manifest, so the update usually appears without anyone opening the Plugins
  screen.
- A **"Check for updates"** link on the Plugins row and a **Check for updates
  now** button on *GitHubSync → Settings → Plugin updates*.

Updates are still installed by WordPress itself, from Dashboard → Updates or the
Plugins screen, with the normal "update available" notice.

## Why the manifest has to be public

WordPress downloads plugin packages with **no credentials**. The plugin
repository is private, so its release assets cannot be fetched anonymously and
cannot be used as `download_url` directly. Release assets on *public* GitHub
releases can.

The release workflow (`.github/workflows/ci.yml`, job `release`) therefore, for
every `v*` tag:

1. runs the test suite first (`needs: lint-and-test`);
2. builds `githubsync.zip` with `./build.sh`;
3. creates or updates the GitHub release for the tag and attaches the zip;
4. writes `update.json` (`.github/scripts/write-manifest.php`) and attaches it to
   the same release;
5. verifies both are reachable **without** authentication and fails the job if
   they are not.

The manifest URL is stable across releases, because `releases/latest` always
resolves to the most recent published release:

```
https://github.com/digal2025/GithubSync-Wordpress-Plugin/releases/latest/download/update.json
```

> **The release must be published, not left as a draft.** A draft release's
> assets are not public, and `releases/latest` skips drafts entirely.

### Manifest format

`update.json` is plain JSON. Only `version`, `download_url` and `sha256` are
required.

```json
{
  "slug": "githubsync",
  "version": "0.1.9",
  "download_url": "https://github.com/digal2025/GithubSync-Wordpress-Plugin/releases/download/v0.1.9/githubsync.zip",
  "sha256": "sha256:0123…(64 hex characters)",
  "requires": "7.4",
  "tested": "6.8",
  "homepage": "https://github.com/digal2025/GithubSync-Wordpress-Plugin",
  "released_at": "2026-12-01",
  "changelog": "### Added\n\n- …"
}
```

Validation is strict and any failure is ignored (the site keeps working, it just
shows no update):

- `version` must be numeric (`1.4.0`, `v1.4.0`, `1.4.0-beta.1`);
- `download_url` must be `https://` on `github.com`,
  `objects.githubusercontent.com` or `release-assets.githubusercontent.com`;
- `sha256` must be 64 hex characters, with or without the `sha256:` prefix;
- optional: `changelog` (markdown, shown in the details dialog), `notes`,
  `requires` (PHP), `tested` (WordPress), `homepage`, `released_at`, `slug`.

## Configuring a site

1. Go to **GitHubSync → Settings → Plugin updates**.
2. Paste the manifest URL into *Release feed* (or set it in `wp-config.php`):

   ```php
   define( 'GITHUBSYNC_UPDATE_URL', 'https://github.com/digal2025/GithubSync-Wordpress-Plugin/releases/latest/download/update.json' );
   ```

   The constant wins over the stored option, which makes the feed easy to lock
   down on managed sites.
3. Leave **Self-updates** ticked (the default) and click **Check for updates
   now**. The status line reports either "Up to date" or the available version.

Once a newer release exists, Dashboard → Updates lists GitHubSync like any
other plugin and the Plugins row offers the usual "update now" link.

## Switching self-updates off

Either clear the **Self-updates** checkbox on the Settings screen, or define:

```php
define( 'GITHUBSYNC_DISABLE_SELF_UPDATE', true );
```

The constant always wins. With self-updates off, GitHubSync never fetches the
manifest, never appears in the update list, and the manual route still works:
download `githubsync.zip` from the release and use **Plugins → Add New → Upload
Plugin**. WordPress's own upload path is unaffected by any of this.

## Publishing a release

1. Bump `Version:` in `githubsync.php` and `GITHUBSYNC_VERSION`, and add a
   `## [x.y.z] - YYYY-MM-DD` section to `CHANGELOG.md` (that section becomes the
   release notes and the in-plugin changelog).
2. Commit and push to `main`; CI must pass.
3. Tag the same commit and push the tag:

   ```bash
   git tag v0.1.9
   git push origin v0.1.9
   ```

4. Watch the `release` job. It fails loudly if the tag and the plugin version
   disagree, or if either asset is not publicly downloadable.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| "No release feed is configured" | The manifest URL is empty; set it on the Settings screen or with the constant. |
| "last check failed: … HTTP 404" | No published release at that URL yet, or the release is still a draft. |
| "last check failed: … not valid JSON" | The URL points at GitHub's HTML page rather than the raw `update.json` asset. |
| "Up to date" but you know a release exists | The manifest's `version` is not greater than the installed one (versions are compared numerically, so `0.1.10` > `0.1.9`). Clear the cache with **Check for updates now**. |
| Update is listed, install fails on download | The zip asset is not public, or the site cannot reach `github.com`. The SHA-256 check also rejects a re-uploaded zip that does not match the manifest. |
| Nothing happens at all | `DISABLE_WP_CRON` or a host that never runs WP-Cron: use **Check for updates now**. |

The check runs on WP-Cron and is rate limited to one forced check every five
minutes; the manifest itself is cached for six hours.
