# Webhook receiver

The always-on service that turns a Figma publish event into builder runs. `app.py` is a Flask
reference — host it anywhere with a public HTTPS URL (Cloud Run, a small VM, a Lambda+API
Gateway, etc.) and adapt the framework to your stack. It must return `200` quickly.

## Flow

```
Figma (LIBRARY_PUBLISH) → POST /figma-webhook → validate passcode → look up figma_manifest.json
                        → repository_dispatch (per affected block) → .github/workflows/figma-build.yml
```

## Environment (host secrets — never in the repo)

| Var | Value |
|---|---|
| `FIGMA_WEBHOOK_PASSCODE` | the passcode you choose at registration (below) |
| `GITHUB_TOKEN` | a token with `repo` scope (or fine-grained: contents + dispatch) on the repo |
| `GITHUB_REPO` | `Topdecileinc/halo-resources` |
| `MANIFEST_URL` | raw URL of the manifest, e.g. `https://raw.githubusercontent.com/Topdecileinc/halo-resources/main/figma-pipeline/figma_manifest.json` |

## Register the Figma webhook (run once, after the receiver is live)

Use the **OAuth access token** (minted from the refresh token; needs `webhooks:write`). Confirm
the exact V2 fields against Figma's current Webhooks docs at deploy time.

```bash
curl -X POST 'https://api.figma.com/v2/webhooks' \
  -H 'Authorization: Bearer <FIGMA_ACCESS_TOKEN>' \
  -H 'Content-Type: application/json' \
  -d '{
    "event_type": "LIBRARY_PUBLISH",
    "team_id": "<YOUR_TEAM_ID>",
    "endpoint": "https://<your-receiver-host>/figma-webhook",
    "passcode": "<MATCHES FIGMA_WEBHOOK_PASSCODE>",
    "description": "Halo email design-system publish events"
  }'
```

Figma sends a `PING` on creation — the receiver acks it with `200`.

## Known follow-up (precision)

`LIBRARY_PUBLISH` reports *which components* changed (by component key), but the manifest keys
on node id, so the receiver currently rebuilds **every** target in the published file (correct,
just coarse). To rebuild only the changed component, record the published component key in the
`figma-source` comment at download time and match it here. Fine to ship coarse first.
