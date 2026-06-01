# Braze Test Send via `/messages/send`

> Binding reference (prefix: `rules_`): the request schema for sending a **test** email
> through Braze's `/messages/send` endpoint. This pipeline is exclusively for test sends
> targeting a designated test segment in a non-production Braze workspace. **Production
> sends are out of scope for this file** — they go through Braze campaigns, configured
> and triggered in the Braze dashboard, not through hand-built JSON sent here.
>
> The brief supplies campaign-specific fields (`app_id`, `from`, segment); env vars on
> the runner supply the API key and REST URL. This file holds no credentials, no URLs,
> no per-campaign data — schema and safety only.
>
> **Last verified against Braze docs:** 2026-05-27. If Braze changes their API, update
> this file and bump the date.

---

## Endpoint

- `POST {BRAZE_REST_URL}/messages/send`
- Headers:
  - `Content-Type: application/json`
  - `Authorization: Bearer {BRAZE_API_KEY}`

`BRAZE_REST_URL` and `BRAZE_API_KEY` come from the runner's environment, not from any
file. See the README's "Sending via Braze (test sends)" section.

---

## Safety constraints

The real safety boundary on this path is **environment scope** — not the `broadcast`
flag (which is just a targeting mode, see below).

- **Environment scope.** This pipeline targets `test` or `non-production` Braze workspaces only — defined by which `BRAZE_API_KEY` and `BRAZE_REST_URL` env vars are set on the runner. The brief does not declare or enforce this; using the right credentials is the user's responsibility.
- **`audience` and `campaign_id` MUST NOT appear** in the send body. Audience filters broaden targeting beyond the named segment; `campaign_id` would pull this test send into production campaign analytics. Both are omitted.

> Note on `broadcast`: Braze's API requires `broadcast: true` when sending to a segment,
> and `broadcast: false` when sending to an explicit `external_user_ids` list. It is a
> *targeting mode flag*, not a safety primitive. Earlier drafts of this file framed it
> as a safety lock; that framing was incorrect.

---

## Request body — segment-based test send

```json
{
  "broadcast": true,
  "segment_id": "<segment-uuid>",
  "messages": {
    "email": { ... }
  }
}
```

| Field | Value | Source |
|---|---|---|
| `broadcast` | `true` | required by Braze for segment sends |
| `segment_id` | UUID | brief §10 |
| `messages.email` | email object | see below |

---

## Email object (inside `messages.email`)

| Field | Required | Source / value |
|---|---|---|
| `app_id` | yes | brief §9 |
| `from` | yes | brief §9 (format: `Display Name <[email protected]>`) |
| `subject` | yes | engine-generated, output in chat per `rules_email_build.md` |
| `preheader` | recommended | engine-generated, 50–100 chars per Braze docs |
| `body` | yes | the full email HTML produced by the build |
| `reply_to` | optional | omit to fall back to workspace default |

Example shape:

```json
{
  "messages": {
    "email": {
      "app_id": "<from brief §9>",
      "from": "<from brief §9>",
      "subject": "<engine-generated>",
      "preheader": "<engine-generated>",
      "body": "<full HTML string>"
    }
  }
}
```

The `body` HTML must be a single JSON string — newlines escaped as `\n`, quotes escaped
as `\"`. The engine handles escaping when writing `send_test_body.json`.

---

## Rate limit

Per Braze docs: 250 requests/minute when using audience filters; otherwise the standard
shared limit across messaging endpoints. Not a concern for hand-run test sends.

---

## What this file does NOT cover

- **Production sends.** Production goes through Braze **campaigns** — created in the Braze dashboard, triggered separately. That path is not implemented or documented in this pipeline.
- **Explicit-recipient sends** (`external_user_ids` + `broadcast: false`). Earlier versions of this file documented that pattern as "Option A." It was removed when segment-based testing became the standard. Braze still supports it; this pipeline does not.
- The API key, REST URL, `app_id`, or `from` — all live elsewhere (env vars or the brief) by design.
- Other Braze endpoints (templates, content blocks, user import, etc.).
