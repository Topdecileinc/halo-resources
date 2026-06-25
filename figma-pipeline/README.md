# figma-pipeline — Figma → email block automation

This directory is the **automation system** that turns Figma designs (marked **"Ready for dev"**)
into email-ready HTML blocks and commits them to this repo. It runs entirely on GitHub Actions —
**there is no server to host.**

> **Full documentation: [GUIDE.md](GUIDE.md)** — architecture, every file, how to get every token,
> the schedule, day-to-day use, and troubleshooting. Read that first.

It is **intentionally separate** from the email content and design system: it *reads* them, it is
**not loaded into the chatbot**, and it is run by the dev/ops side — not by the email-building flow.
Nothing in `email-design-system/`, `brand-guidelines/`, `email-examples/`, or `brief/` depends on it.

## The one rule

A Figma node becomes/stays an email block **only if it is marked "Ready for dev."** Mark it → it's
built (or updated). Unmark it → it's left alone. Everything else in the file is ignored.

## What's here

| File | Role |
|---|---|
| `poll.py` | The trigger's brain: finds Ready-for-dev nodes, detects design changes, lists what to build. |
| `poll_state.json` | Remembers each tracked node's last-seen design hash. |
| `figma_fetch.py` | Downloads one Figma node (JSON + PNG) for the builder. |
| `generate_manifest.py` / `figma_manifest.json` | The Figma-node → output-file map, built from each block's `figma-source` comment. |
| `validate_block.py` | Pre-commit safety gate; fails the build on a broken block. |
| `GUIDE.md` | The complete reference. |

The two workflows live at `.github/workflows/figma-poll.yml` (the trigger, on a 20-min cron) and
`.github/workflows/figma-build.yml` (the per-block builder). The translation rules Claude follows
live in `developer-skills/rules_figma_download.md`.

## Secrets (set in GitHub → Settings → Secrets and variables → Actions)

`FIGMA_CLIENT_ID`, `FIGMA_CLIENT_SECRET`, `FIGMA_REFRESH_TOKEN`, `CLAUDE_CODE_OAUTH_TOKEN`.
`GITHUB_TOKEN` is automatic. See [GUIDE.md §7](GUIDE.md#7-secrets--tokens--how-to-get-each-one) for
exactly how to obtain each.
