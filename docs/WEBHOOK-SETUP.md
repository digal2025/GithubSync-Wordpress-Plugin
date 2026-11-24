# Webhook setup

Each deployment has its own webhook endpoint. You need one webhook per
deployment, on the repository that deployment watches.

## Where the values come from

Open **GitHubSync → Deployments → (your deployment)**. The detail page shows:

- **Payload URL** — `https://your-site.example/wp-json/githubsync/v1/webhook/<32 hex characters>`
- **Secret** — 64 hex characters, generated when the deployment was created.

Click the copy button next to each; retyping them by hand is the most common
cause of `401`.

If the site is behind HTTPS you must use `https://`. GitHub still delivers to
`http://`, but the secret then crosses the network in clear text on every push.

## Adding the webhook on GitHub

1. Open the repository → **Settings** → **Webhooks** → **Add webhook**.
2. **Payload URL**: paste the full URL from the deployment page, including the
   hex token. No trailing slash.
3. **Content type**: `application/json`.
   > This is not cosmetic. GitHubSync computes the HMAC-SHA256 over the raw
   > request body. With `application/x-www-form-urlencoded` the body arrives as
   > `payload=<urlencoded json>` and every delivery fails with
   > `401 invalid_signature`.
4. **Secret**: paste the deployment secret.
5. **SSL verification**: *Enable*.
6. **Which events would you like to trigger this webhook?**: *Just the push
   event*. GitHubSync ignores other events with `202 ignored_event`, but
   subscribing to everything only wastes deliveries.
7. **Active**: checked.
8. **Add webhook**.

GitHub sends a `ping` immediately. In **Recent Deliveries** you should see a
green `200` with the response body `pong`.

## What each response means

| Response | Meaning | What to do |
| --- | --- | --- |
| `200 pong` | Ping received, signature valid. | Nothing. |
| `202 queued` | Push accepted, a job was queued. | Check **History** for the job. |
| `202 duplicate_delivery` | Same `X-GitHub-Delivery` seen before. | Nothing; the first delivery already queued the job. |
| `202 branch_mismatch` | Push is not for the configured branch. | Expected unless you deploy `main` from a feature branch. |
| `202 repository_mismatch` | Push came from a different repository ID. | The deployment points at another repo. Forked repo? Pin the right one. |
| `202 ignored_event` | Not a push (tag, pull request, …). | Nothing. |
| `401 invalid_signature` | Secret mismatch, wrong content type, or a proxy rewrote the body. | Re-copy the secret, set `application/json`. |
| `404 unknown_endpoint` | Token no longer exists. | The deployment was deleted or its token regenerated — re-copy the URL. |
| `413 payload_too_large` | Body above `max_webhook_bytes` (1 MB default). | Raise it in **Settings** if you really push that much, or shrink the payload. |
| `429 rate_limited` | Too many rejected requests from your IP in 5 minutes. | Wait a few minutes; the block is transient. |

A `200`/`202` means *GitHubSync accepted the delivery*, not that files were
deployed. Deployment happens on the next WP-Cron tick; see
[Automatic deployment](../README.md#automatic-deployment).

## There is no automatic creation

This version has **no API call that creates a webhook on GitHub** — the plugin
never requests that scope. The GitHub connection needs `Contents: Read-only`
only (plus `Metadata`), and every deployment's webhook is added with the manual
steps above. What you *can* do from the plugin is **regenerate** a deployment's
secret on the detail page after changing it on GitHub.

## Rotating a secret

1. In GitHubSync, open the deployment and click **Regenerate secret**. The new
   secret is generated locally (`bin2hex(random_bytes(32))`) and encrypted.
2. Immediately go to GitHub → the webhook → **Edit** and paste the new secret.

Regenerating on one side only produces `401 invalid_signature` on every push.
Pending jobs are unaffected; they do not depend on the webhook secret.

## Verifying without a real push

```bash
SITE=https://your-site.example
TOKEN=<token from the deployment page>
SECRET=<secret from the deployment page>
BODY='{"zen":"test","hook_id":1}'
SIG="sha256=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$SECRET" -r | cut -d' ' -f1)"

curl -i -X POST "$SITE/wp-json/githubsync/v1/webhook/$TOKEN" \
  -H 'Content-Type: application/json' \
  -H 'X-GitHub-Event: ping' \
  -H "X-Hub-Signature-256: $SIG" \
  --data-raw "$BODY"
```

Expected: `HTTP/1.1 200` and the body `pong`.
