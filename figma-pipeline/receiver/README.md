# Webhook receiver

The always-on service that turns a Figma publish event into builder runs. It must return
`200` quickly. Two equivalent implementations are here — **deploy one:**

- **`figma-webhook.php`** — recommended for this project. Plain PHP, runs under the Apache +
  PHP this VM already has (no Python/venv/gunicorn/systemd). Secrets in a config file
  outside the webroot, like `brief/config.php`.
- **`app.py`** — the same logic as a Python/Flask reference, for a different host.

**Step-by-step deploy: see [DEPLOY.md](DEPLOY.md)** (written for the PHP path on Apache + a subdomain).

## Flow

```
Figma (LIBRARY_PUBLISH) → POST /figma-webhook.php → validate passcode → look up figma_manifest.json
                        → repository_dispatch (per affected block) → .github/workflows/figma-build.yml
```

## Secrets (never in the repo)

The receiver needs: a **passcode** (you invent it; Figma echoes it back so the call can be
trusted), a **GitHub token** (fine-grained: Contents R/W on `Topdecileinc/halo-resources`),
the **repo** name, and the **manifest URL**
(`https://raw.githubusercontent.com/Topdecileinc/halo-resources/main/figma-pipeline/figma_manifest.json`).

- PHP (`figma-webhook.php`): a `return [...]` array in `/etc/figma-receiver-config.php` (see DEPLOY.md step 3).
- Python (`app.py`): the env vars `FIGMA_WEBHOOK_PASSCODE`, `GITHUB_TOKEN`, `GITHUB_REPO`, `MANIFEST_URL`.

## Register the Figma webhook (run once, after the receiver is live)

Use a **Figma OAuth access token** with `webhooks:write` (mint it from the refresh token —
DEPLOY.md step 6). Point the endpoint at your deployed URL, e.g.
`https://hooks.example.com/figma-webhook.php`, with `passcode` matching the one above. Figma
sends a `PING` on creation — the receiver acks it with `200`.

## Known follow-up (precision)

`LIBRARY_PUBLISH` reports *which components* changed (by component key), but the manifest keys
on node id, so the receiver currently rebuilds **every** target in the published file (correct,
just coarse). To rebuild only the changed component, record the published component key in the
`figma-source` comment at download time and match it here. Fine to ship coarse first.
