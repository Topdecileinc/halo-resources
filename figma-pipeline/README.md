# figma-pipeline — the automated Figma → email pipeline (kept separate)

This directory is the **automation system** that regenerates email blocks from Figma and (later)
drives generation + send. It is **intentionally separate** from the email content and the design
system in this repo: it *reads* them, but it is **not part of them**, is **not loaded into the
chatbot**, and is **deployed / run by the dev team** — not by the email-building flow.

Nothing in `email-design-system/`, `brand-guidelines/`, `email-examples/`, or `brief/` depends on
anything in here. Keep it that way.

## Contents

- **`generate_manifest.py`** — builds `figma_manifest.json` from the `figma-source` comments in the
  `email-design-system` blocks (the Figma-node → output-file map). Run after any download/refresh.
- **`figma_manifest.json`** — the generated mapping; the webhook receiver's lookup table.
- **`figma_fetch.py`** — headless Figma read for the CI builder (OAuth refresh token → node JSON +
  rendered PNG via the Figma REST API). Replaces the remote Figma MCP, which can't run headless.

_Coming:_ the GitHub Actions builder workflow, the webhook receiver, and the Lane B send app. The
larger services (receiver, Lane B app) may move to their own repo entirely.

## Where the procedure is documented

The build/translation rules these implement live in **`developer-skills/rules_figma_download.md`**
(a rule doc, kept with the other rules). This folder is the *code*; that file is the *spec*.

## Secrets

All credentials — `FIGMA_CLIENT_ID`, `FIGMA_CLIENT_SECRET`, `FIGMA_REFRESH_TOKEN`,
`ANTHROPIC_API_KEY`, the webhook passcode, the Braze key — live in **CI secrets / a vault**, never
in this directory or anywhere in the repo.
