# figma-pipeline — the automated Figma → email pipeline (kept separate)

This directory is the **automation system** that regenerates email blocks from Figma and (later)
drives generation + send. It is **intentionally separate** from the email content and the design
system in this repo: it *reads* them, but it is **not part of them**, is **not loaded into the
chatbot**, and is **deployed / run by the dev team** — not by the email-building flow.

Nothing in `email-design-system/`, `brand-guidelines/`, `email-examples/`, or `brief/` depends on
anything in here. Keep it that way.

## How a change gets noticed — TWO trigger options

- **Polling (the one in use).** Figma only *delivers* webhook events on Org/Enterprise plans;
  this repo's Figma account is **Professional**, so we poll instead. `.github/workflows/figma-poll.yml`
  runs `poll.py` every 20 min (and on demand) to hash each tracked block's current Figma design and
  rebuild only the ones that changed. **No webhook, no receiver, no extra hosting.**
- **Webhook (built, but inert on Professional).** `receiver/` + a Figma webhook would push events
  in real time — but only on Org/Enterprise. Kept for if the plan ever upgrades.

Either way the trigger calls the same builder (`figma-build.yml`), which is reusable.

## Contents

- **`poll.py`** — change detection for the poller: hashes each tracked block's Figma *design*
  subtree, compares to `poll_state.json`, emits the changed ones. First sighting = silent baseline.
- **`poll_state.json`** — last-seen design hashes (so unchanged blocks aren't rebuilt).
- **`generate_manifest.py`** — builds `figma_manifest.json` from the `figma-source` comments in the
  `email-design-system` blocks (the Figma-node → output-file map). Run after any download/refresh.
- **`figma_manifest.json`** — the generated mapping; the list of blocks the poller checks.
- **`figma_fetch.py`** — headless Figma read for the builder (OAuth refresh token → node JSON +
  rendered PNG via the Figma REST API). Replaces the remote Figma MCP, which can't run headless.
- **`validate_block.py`** — the pre-commit safety gate; hard-fails a bad/broken block so it can't
  be committed.
- **`receiver/`** — the webhook receiver (only useful on Org/Enterprise; see polling above).
- Workflows under **`.github/workflows/`**: `figma-poll.yml` (the trigger) and `figma-build.yml`
  (the builder; runs from a poll match, a manual run, or repository_dispatch).

**Scope:** this pipeline ends at **commit-to-git** — detect a Figma change, regenerate the email
HTML block, validate it, and check it in. It does **not** send anything (no Braze / Lane B); that
is intentionally out of scope.

## Where the procedure is documented

The build/translation rules these implement live in **`developer-skills/rules_figma_download.md`**
(a rule doc, kept with the other rules). This folder is the *code*; that file is the *spec*.

## Secrets

All credentials — `FIGMA_CLIENT_ID`, `FIGMA_CLIENT_SECRET`, `FIGMA_REFRESH_TOKEN`,
`ANTHROPIC_API_KEY`, the webhook passcode, the Braze key — live in **CI secrets / a vault**, never
in this directory or anywhere in the repo.
