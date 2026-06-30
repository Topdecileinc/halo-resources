# Figma → Email Pipeline — Complete Guide

This is the full reference for the system that turns Figma designs into email-ready HTML
blocks automatically. It covers what every piece does, how it's wired together, how to get
and configure every credential, how the schedule works, and how to operate and troubleshoot
it. It assumes **no prior knowledge** of the system.

> **Plain-English summary:** You mark a design **"Ready for dev"** in Figma. Within ~20 minutes,
> a robot reads that design, converts it into email-safe HTML (plus a preview file), and commits
> it to this repository. If you change the design later, it updates the file automatically. If
> you mark a brand-new design, it creates a brand-new file. Nothing you *don't* mark is ever
> touched.

---

## Table of contents

1. [The core rule: "Ready for dev"](#1-the-core-rule-ready-for-dev)
2. [Architecture — the whole flow](#2-architecture--the-whole-flow)
3. [Why polling instead of webhooks](#3-why-polling-instead-of-webhooks)
4. [Day-to-day: how you actually use it](#4-day-to-day-how-you-actually-use-it)
5. [The triggers — cron, manual, dry-run, external](#5-the-triggers--cron-manual-dry-run-external)
6. [Every file, explained](#6-every-file-explained)
7. [Secrets & tokens — how to get each one](#7-secrets--tokens--how-to-get-each-one)
8. [The schedule (cron) in detail](#8-the-schedule-cron-in-detail)
9. [How change detection works](#9-how-change-detection-works)
10. [Naming rules](#10-naming-rules)
11. [The validation gate](#11-the-validation-gate)
12. [Maintenance — token expiry, rotation, enable/disable](#12-maintenance--token-expiry-rotation-enabledisable)
13. [Troubleshooting](#13-troubleshooting)
14. [If you ever upgrade Figma to Organization/Enterprise](#14-if-you-ever-upgrade-figma-to-organizationenterprise)

---

## 1. The core rule: "Ready for dev"

There is exactly **one rule** that governs everything:

> **A Figma node becomes (and stays) an email block IF AND ONLY IF it is marked "Ready for dev"
> in Figma's Dev Mode.**

From that single rule — **the repo mirrors the Ready-for-dev set of the file:**

- **Marked Ready for dev + never built before** → the pipeline **creates** a new block from it.
- **Marked Ready for dev + already a block + design changed** → the pipeline **rebuilds** it.
- **Marked Ready for dev + already a block + nothing changed** → left alone.
- **No longer Ready for dev** (status **cleared**, or the frame **removed** from Figma) → the block
  is **DELETED** (recoverable from git history).
- **Set to "Completed"** → **protected**: kept as-is, never rebuilt, never deleted.
- **Never marked at all** → completely ignored.

This is why the file can be full of style guides, references, and work-in-progress without the
pipeline touching any of it. You opt a design **in** by marking it Ready for dev, and **out** (which
removes it) by clearing the status. **Caution:** because clearing the status deletes the block,
don't clear it just to pause editing — see §4.

**How to mark Ready for dev:** in Figma, right-click a section/frame (or use the status control
in Dev Mode's right panel) and set its status to **"Ready for dev."** The status is read by the
pipeline through Figma's REST API.

---

## 2. Architecture — the whole flow

```
        ┌──────────────────────────── GitHub (free Actions runners) ────────────────────────────┐
        │                                                                                          │
  Figma │   figma-poll.yml  (the TRIGGER)                 figma-build.yml  (the WORKER)            │
  file  │   ┌───────────────────────────┐                 ┌──────────────────────────────────┐    │
   you  │   │ every 20 min OR manual    │                 │ for ONE block:                   │    │
  edit ─┼─► │ 1. poll.py reads the file │  per changed/   │ 1. figma_fetch.py  → node.json   │    │
        │   │ 2. find Ready-for-dev     │  new block...   │    + node.png                    │    │
        │   │    nodes; diff vs state   │ ──────────────► │ 2. claude-code-action → HTML +   │    │
        │   │ 3. output the list to     │  (workflow_call │    playground (create OR refresh)│    │
        │   │    build + commit state   │   matrix)       │ 3. guard: fail if Claude errored │    │
        │   └───────────────────────────┘                 │ 4. generate_manifest.py          │    │
        │                                                  │ 5. validate_block.py  (gate)     │    │
        │                                                  │ 6. commit + push to main         │    │
        │                                                  └──────────────────────────────────┘    │
        └──────────────────────────────────────────────────────────────────────────────────────────┘
                                                                         │
                                                                         ▼
                                                   email-design-system/sections/*.html
                                                   email-design-system/playground/*.html
                                                   figma-pipeline/figma_manifest.json
```

Two GitHub Actions workflows do all the work; there is **no server to run** (see §3).

- **`figma-poll.yml`** is the *trigger*. It wakes up on a schedule (or when you press a button),
  figures out *what* needs building, and hands each block to the builder.
- **`figma-build.yml`** is the *worker*. It builds exactly one block: download → translate →
  validate → commit. The poll workflow calls it once per block (in parallel) using GitHub's
  "reusable workflow" feature (`workflow_call`).

Everything else in `figma-pipeline/` is a small Python script that those two workflows run.

---

## 3. Why polling instead of webhooks

The "instant" way to do this would be a Figma **webhook**: Figma calls a server the moment you
mark something Ready for dev. We built that (it's gone now — see cleanup note below), but it
**does not work on this Figma account**, because:

> Figma only **delivers** webhook events on **Organization / Enterprise** plans. This account is
> **Professional**. On Professional you can *register* a webhook and Figma sends a one-time test
> "PING," but the real events (`DEV_MODE_STATUS_UPDATE`, file updates, etc.) **never fire.**

So instead of waiting to be told, the pipeline **asks** Figma on a schedule (polling). The cost is
a small delay (up to the poll interval, ~20 min) instead of being instant. The big advantage:
**it runs entirely on GitHub's servers — no always-on server, no hosting, no receiver to maintain.**

The old webhook receiver (a PHP/Python service that used to run on a VM) has been **removed** from
the repo. It is recoverable from git history if the Figma plan is ever upgraded (see §14).

---

## 4. Day-to-day: how you actually use it

### The full lifecycle, step by step

Here is exactly what happens, from a designer's edit to a committed file:

**The designer's side (in Figma).** "Ready for dev" is the **on/off switch for whether a component
is in the library** — the repo mirrors your Ready-for-dev set:
1. **Mark a frame "Ready for dev"** → it's built into the repo (created if new, updated whenever its
   design changes) and kept in sync.
2. **Clear its status, or delete the frame** → the block, its playground, **and** its index entry
   are **deleted** from the repo (recoverable from git history).
3. **Set it to "Completed"** → the block is **kept and frozen**: never rebuilt, never deleted. Use
   this for "done, leave it alone."

> ⚠️ **Do NOT clear a live component's status just to edit it — that deletes it.** Because clearing
> the status removes the block, the old "unmark while you work" habit is **not** available here. To
> change a live component, **edit it while it stays marked Ready for dev.** A poll could rebuild it
> mid-edit, so to avoid shipping a half-finished version, **edit in a Figma branch and merge when
> it's done** — the main file's frame stays stable until the merge. *(This is the trade-off the
> client chose: a dead-simple "library = whatever is marked Ready for dev," at the cost of the
> unmark-to-edit convenience.)*

**The system's side (GitHub, automatic):**
4. On its schedule (every ~20 min) — or a manual trigger — the **poller** reads the whole Figma file
   and looks at every node's dev status.
5. It fingerprints each **Ready-for-dev** node's design and compares to last time (`poll_state.json`):
   **new** ready design → **create**; tracked design that **changed** → **rebuild**; **unchanged** → skip.
6. It finds tracked blocks that are **no longer Ready for dev** (status cleared, or frame removed)
   and **deletes** them — block, playground, manifest entry, index entry. (Completed blocks are
   skipped; safety caps refuse a mass-delete — see §9.)
7. For each create/rebuild, the **builder** runs: download the node → Claude translates it to email
   HTML + playground → "did Claude error?" guard → regenerate manifest → update index → validation
   gate → **commit & push.** Deletions are committed by the poller itself.

So the designer's only job is the **Ready-for-dev flag**; everything else is automatic.

### Quick reference

- **Publish / update a block:** mark it Ready for dev → wait ≤20 min (or trigger a poll, §5).
- **Delete a block:** clear its Ready-for-dev status, or delete the frame in Figma → removed on the
  next poll (recoverable from git history).
- **Keep a block but stop changing it:** set it to **Completed** — frozen, not rebuilt, not deleted.
- **Edit a live block:** edit it **while it stays Ready for dev** (don't clear the status — that
  deletes it); use a Figma branch to avoid a mid-edit rebuild.
- **Rename a block's file:** clear the status (deletes the old file), rename the frame, then mark
  Ready for dev again (creates it under the new name).

**Things to keep in mind:**
- Only designs in the one tracked Figma file are seen (§9).
- The HTML uses `[BRACKETED]` placeholders for dynamic content — filled in later by the
  email-building step, not by Figma.
- Re-running with no design change does nothing (the pipeline detects "no change" and skips).
- The index page auto-syncs both ways — new blocks **added**, deleted blocks **removed** — while
  your hand-curated entries (header/footer/rules/assets) are never altered (§6).

---

## 5. The triggers — cron, manual, dry-run, external

The **poller** (`figma-poll.yml`) can start four ways:

**A. Automatic schedule (cron).** Runs every 20 minutes on its own. See §8.

**B. Manual button (no terminal).** GitHub → **Actions** tab → **figma-poll** → **Run workflow**.
It shows two fields:

*`onboard_cap` — max NEW frames to onboard (create) this run:*
- `10` (default) — create up to 10 new blocks; rebuild any changed ones.
- `1` — create at most one new block (good for testing one at a time).
- `0` — onboard **no new** blocks but **log every new one it found** (a preview of what would be
  created). Not a full no-op — a *changed existing* block still rebuilds (updates aren't capped).

*`delete_cap` — max blocks to DELETE this run* (a block is deleted when its node is no longer
Ready for dev):
- `10` (default) — delete up to 10 unmarked blocks this run.
- `0` — **preview only**: log what *would* be deleted, delete nothing. (Run this first if you're
  unsure what's about to be removed.)
- The safety stop still applies on top: it refuses to delete *all* tracked blocks at once (§9).

**C. Manual via terminal (`gh` CLI).**
```
gh workflow run figma-poll.yml                          # normal (onboard 10, delete 10)
gh workflow run figma-poll.yml -f onboard_cap=1         # onboard at most 1 new block
gh workflow run figma-poll.yml -f onboard_cap=0 -f delete_cap=0   # preview only: build/delete nothing
gh workflow run figma-poll.yml -f delete_cap=0          # preview deletions, still onboard/rebuild
```
(Add `--repo HaloCollar/MarketingOS` to target the live repo from a clone with multiple remotes.)

**D. From your own server / app (optional).** A single authenticated HTTPS call triggers a poll —
useful for a "Sync from Figma now" button in your admin. POST to:
```
https://api.github.com/repos/Topdecileinc/halo-resources/actions/workflows/figma-poll.yml/dispatches
  Authorization: Bearer <fine-grained PAT with Actions: Read and write>
  body: {"ref":"main","inputs":{"onboard_cap":"10"}}
```
This is fire-and-forget — it does **not** require an always-on server.

The **builder** (`figma-build.yml`) can also be run directly (Actions → figma-build → Run workflow)
by giving it `file_key`, `node_id`, and `output_path` — handy to force-rebuild one specific block.

---

## 6. Every file, explained

Everything lives in two places: the workflows under `.github/workflows/`, and the scripts/data
under `figma-pipeline/`.

### Workflows (`.github/workflows/`)

| File | What it is |
|---|---|
| **`figma-poll.yml`** | The **trigger**. Two jobs: `detect` (runs `poll.py`, commits the new state) and `build` (a matrix that calls `figma-build.yml` once per block to build). Has the cron schedule and the manual `onboard_cap` input. |
| **`figma-build.yml`** | The **worker**. Builds ONE block: checkout → `figma_fetch.py` → `claude-code-action` (translate/create) → "fail if Claude errored" guard → `generate_manifest.py` → `generate_index.py` → `validate_block.py` (gate) → commit-and-push (with rebase-retry). Reusable via `workflow_call`; also runnable manually or via `repository_dispatch`. |

### Scripts & data (`figma-pipeline/`)

| File | What it does |
|---|---|
| **`poll.py`** | The change detector. Reads the Figma file, finds every **Ready-for-dev** node, hashes each one's design, compares to `poll_state.json`. Outputs the list of blocks to build (new to create + tracked that changed), **and deletes** the blocks of tracked nodes no longer Ready for dev (Completed is protected; safety caps prevent a mass-delete). One full-file fetch per file — scales to hundreds of nodes. |
| **`poll_state.json`** | The memory. `{ "node_hashes": { "<file>:<node>": "<hash>" } }` — the last-seen design hash of each tracked node, so the poller knows what actually changed. Committed by the workflow each run. |
| **`figma_fetch.py`** | Headless Figma read for the builder. Turns the refresh token into a short-lived access token, then downloads one node's structure (`node.json`) and a rendered PNG (`node.png`) into `./_figma_in/`. |
| **`generate_manifest.py`** | Builds `figma_manifest.json` by scanning the `figma-source` comments embedded in every block. Run after each build so new blocks get tracked. `--check` mode verifies it's up to date (for CI). |
| **`figma_manifest.json`** | The map of `Figma node → output file`, derived from the blocks themselves. This is the list `poll.py` checks for change-detection. |
| **`generate_index.py`** | Keeps the top-level `index.html` listing in sync — **adds** an entry for any block not yet listed and **removes** entries whose playground no longer exists (deleted blocks). Never edits the hand-curated entries (header/footer/rules/assets). Runs in the builder and in the poller. |
| **`validate_block.py`** | The safety gate. Hard-fails the build (blocking the commit) if a generated block is broken: missing class marker, missing/!fake `figma-source` comment, broken image URLs, `display:flex/grid` (not email-safe), etc. |
| **`GUIDE.md`** | This document. |
| **`README.md`** | Short orientation that points here. |

### Related (outside `figma-pipeline/`)

| Path | Role |
|---|---|
| **`developer-skills/rules_figma_download.md`** | The **translation spec** Claude follows when turning a Figma node into email HTML (how to classify it, what becomes a `[BRACKETED]` placeholder, how to build the playground, the `figma-source` comment format, the refresh/merge rules). The builder's prompt says "follow this doc exactly." |
| **`email-design-system/sections/*.html`** | The generated email blocks (the actual deliverables). |
| **`email-design-system/playground/*.html`** | A standalone, viewable preview for each block. |
| **`_figma_in/`** | Throwaway scratch dir the builder writes the fetched `node.json`/`node.png` into. Gitignored. |

### The `figma-source` comment (the anchor)

Every generated block contains an HTML comment like:
```html
<!-- figma-source
     file:          cXkVxm0fI9G49nZtKPbYY7
     node:          4618:108
     url:           https://www.figma.com/design/cXkV.../?node-id=4618-108
     kind:          section
     fetched:       2026-06-25
     generated-sha: 9fb0a793...64-hex... -->
```
This is the link back to Figma. `generate_manifest.py` reads it to build the manifest; the builder
recomputes `generated-sha`. Don't delete it — it's how a block stays connected to its Figma source.

---

## 7. Secrets & tokens — how to get each one

The pipeline's configuration is **all in GitHub, nothing hardcoded** — four secrets, one variable,
and one automatic token. They live under GitHub → the repo → **Settings** → **Secrets and
variables** → **Actions**. That page has **two tabs**:

- **Secrets** tab — for sensitive values (passwords/tokens). Hidden after you save.
- **Variables** tab — for non-sensitive config (like a file ID). Visible/editable any time.

| Name | Tab | Sensitive? |
|---|---|---|
| `FIGMA_FILE_KEY` | **Variables** | No — it's just an ID from a shareable URL |
| `FIGMA_CLIENT_ID` | Secrets | Yes |
| `FIGMA_CLIENT_SECRET` | Secrets | Yes |
| `FIGMA_REFRESH_TOKEN` | Secrets | Yes |
| `CLAUDE_CODE_OAUTH_TOKEN` | Secrets | Yes |
| `GITHUB_TOKEN` | (automatic) | — provided by Actions, you don't create it |

### 7.0 `FIGMA_FILE_KEY` (variable) — which Figma file the pipeline tracks

This is the single source of truth for **which Figma file** the pipeline reads. It is **not**
hardcoded anywhere and **not** read from the blocks — change this one value and the entire pipeline
repoints to a different file on the **next poll, with no rebuild and no file edits.** (This is the
fix for the "wrong file ID" problem — you just edit the variable.)

- **What it is:** a Figma *file key* — the random string in a Figma design URL.
- **How to get it:** open the file in Figma and look at the URL:
  `https://www.figma.com/design/`**`cXkVxm0fI9G49nZtKPbYY7`**`/Halo-Email-...` — the **bolded part**,
  between `/design/` and the next `/`, is the file key.
- **Where to set it:** Settings → Secrets and variables → Actions → **Variables** tab → **New
  repository variable** → name `FIGMA_FILE_KEY`, value = that key.
- It's a *variable*, not a secret, because a file key isn't sensitive (it's in any share link) and
  because you want to be able to read/change it easily.

> Note: each block still records where it came from in its `figma-source` comment (for humans and
> the clickable URL), but the **pipeline ignores that** for targeting — only the variable decides
> which file is read. After you repoint and the blocks rebuild, those comments self-correct.

### The secrets

Add each on the **Secrets** tab (**New repository secret**), by exact name.

### 7.1 `FIGMA_CLIENT_ID` and `FIGMA_CLIENT_SECRET` — the Figma OAuth app

These identify an OAuth application that's allowed to read your Figma files.

1. Go to **figma.com** → your account → **Settings** → **Security** (or the Figma developer site
   under "My apps") → **create a new OAuth app** ("app" / "OAuth 2.0").
2. Give it a name. Set a **redirect URL** — any URL you control works for the one-time setup; even
   `https://localhost/callback` is fine because you only need to read the `code` out of the address
   bar. Save it; you'll reuse the *exact same* redirect URL in step 7.3.
3. Figma shows a **Client ID** and **Client Secret**. These become `FIGMA_CLIENT_ID` and
   `FIGMA_CLIENT_SECRET`.

### 7.2 `FIGMA_REFRESH_TOKEN` — long-lived permission to read your files

The OAuth app needs a one-time human approval, which produces a **refresh token** the pipeline
reuses forever. You generate it once via the OAuth "authorization code" flow:

1. **Authorize in a browser.** Open this URL (substitute your client id and the *registered*
   redirect URL):
   ```
   https://www.figma.com/oauth?client_id=<CLIENT_ID>&redirect_uri=<REDIRECT_URL>&scope=file_content:read,file_metadata:read&response_type=code&state=setup
   ```
   `file_content:read` is what lets the pipeline read the design (this also includes the
   **Ready-for-dev status**). Approve it. Figma redirects to your redirect URL with
   `?code=XXXX&state=setup` in the address bar — **copy that `code`** (even if the page fails to
   load, the code is in the URL).

2. **Exchange the code for tokens** (in a terminal):
   ```
   curl -X POST https://api.figma.com/v1/oauth/token \
     -d client_id=<CLIENT_ID> \
     -d client_secret=<CLIENT_SECRET> \
     -d redirect_uri=<REDIRECT_URL> \
     -d code=<CODE_FROM_STEP_1> \
     -d grant_type=authorization_code
   ```
   The JSON response contains an `access_token` (short-lived, ignore it) **and a `refresh_token`**.
   The `refresh_token` is the value for `FIGMA_REFRESH_TOKEN`.

The pipeline turns this refresh token into fresh access tokens automatically on every run via
`POST https://api.figma.com/v1/oauth/refresh` — you never have to touch it again unless it's
revoked (see §12).

### 7.3 `CLAUDE_CODE_OAUTH_TOKEN` — runs Claude on your subscription

This lets the builder use Claude (to translate designs) on your Claude **Pro/Max subscription**,
so there's **no per-build API billing**.

1. On your own machine, with Claude Code installed and logged in to a Pro/Max account, run:
   ```
   claude setup-token
   ```
2. It opens a browser to authorize, then prints a token. That value is `CLAUDE_CODE_OAUTH_TOKEN`.

(If you were ever on an account without Pro/Max, the alternative is an `ANTHROPIC_API_KEY` from
console.anthropic.com — small per-build cost — but the workflow is currently wired for the OAuth
token.)

### 7.4 `GITHUB_TOKEN` — automatic

GitHub Actions injects this for every run. The workflows use it to commit the generated blocks back
to the repo and to let Claude's action authenticate. **You don't create or store it.** The only
time you'd make a GitHub token by hand is for the optional "trigger from your server" path (§5-D),
which needs a fine-grained PAT with **Actions: Read and write**.

### Summary

| Name | Tab | What it's for | How you get it |
|---|---|---|---|
| `FIGMA_FILE_KEY` | Variables | Which Figma file to track | the key in the file's URL (§7.0) |
| `FIGMA_CLIENT_ID` | Secrets | Identifies the Figma OAuth app | Figma → create OAuth app |
| `FIGMA_CLIENT_SECRET` | Secrets | Secret for that app | same screen |
| `FIGMA_REFRESH_TOKEN` | Secrets | Long-lived read access to your files | one-time OAuth code flow (§7.2) |
| `CLAUDE_CODE_OAUTH_TOKEN` | Secrets | Run Claude on your subscription | `claude setup-token` |
| `GITHUB_TOKEN` | (automatic) | Commit results / auth Claude action | **automatic** — nothing to do |

---

## 8. The schedule (cron) in detail

The schedule lives in **`.github/workflows/figma-poll.yml`**:
```yaml
on:
  schedule:
    - cron: "*/20 * * * *"   # every 20 minutes
```

**Reading cron:** five fields — `minute hour day-of-month month day-of-week` — in **UTC**.
`*` = "every", `*/N` = "every N".

| Expression | Meaning |
|---|---|
| `*/20 * * * *` | every 20 minutes (current) |
| `*/30 * * * *` | every 30 minutes |
| `0 * * * *` | once an hour, on the hour |
| `0 */2 * * *` | every 2 hours |
| `0 13 * * 1-5` | 13:00 UTC, Mon–Fri only |

**To change it:** edit that `cron:` line, commit, and push to `main`. The new schedule takes effect
automatically. (A cron change only counts once it's on the **default branch**, `main`.)

**To pause/resume:** GitHub → **Actions** → **figma-poll** → the **"···"** menu → **Disable workflow**
/ **Enable workflow**. Disabling stops both the schedule and the manual button.

**Two honest caveats about GitHub cron:**
1. It is **best-effort** — runs can be delayed a few minutes under GitHub load. "Every 20 min" is
   approximate, not to-the-second.
2. GitHub **auto-disables** scheduled workflows after **60 days with no repo activity**. This repo
   commits regularly (including the bot's own commits), so it won't trip — but if the repo went
   totally silent for two months, you'd re-enable it with one click (above).

### Runs never overlap (even if one takes longer than the interval)

A poll run can take longer than 20 minutes — building many blocks takes ~6–7 min **each**, and they
run one at a time (next paragraph), so a big batch can run 40+ min. That is safe: **two poll runs
can never overlap.** Two `concurrency` guards enforce it:

- **The poller** (`figma-poll.yml`) has `concurrency: { group: figma-poll, cancel-in-progress: false }`.
  A whole run — detection **and** all its builds — is "in progress" until everything finishes, so a
  cron tick that fires while a run is still going **queues and waits** instead of starting alongside.
  `cancel-in-progress: false` means the running one is never killed mid-commit.
- **The builder** (`figma-build.yml`) shares one `concurrency: { group: figma-build }`, so even
  within a single poll the per-block builds run **one at a time** — they never race each other on the
  Figma token or the git push.

**What this means in practice:** if builds consistently run longer than the cron interval, GitHub
keeps **at most one** run queued (a newer cron tick supersedes an older still-pending one), so there
is **no pileup and no overlap** — the effective cadence just becomes "after each run finishes"
instead of strictly every 20 minutes, and it always catches up. To keep individual runs short, lower
`onboard_cap` (fewer new blocks per run) or widen the cron interval — neither is required, since
overlap is already impossible; they only change how big each run gets.

---

## 9. How change detection works

The pipeline tracks **one Figma file** (the design system), named by the **`FIGMA_FILE_KEY`
variable** (§7.0) — not by anything in the code or the blocks. Currently that variable is set to
`cXkVxm0fI9G49nZtKPbYY7` ("Halo Email Design System (Copy)"). To track a different file, change the
variable; nothing else.

Each poll (`poll.py`) does this:

1. **Fetch the whole file once** via the Figma REST API (`GET /v1/files/<key>`). One call, not one
   per node — this is what makes it scale to hundreds of blocks.
2. **Read every node's dev status** — walk the file tree once, collecting nodes marked
   `READY_FOR_DEV` and the ids of nodes marked `COMPLETED` (those are protected).
3. **Hash each Ready-for-dev node's design** — a SHA-256 of the node's design subtree
   (geometry/text/layout). Volatile file metadata (thumbnails, timestamps) is excluded, so the hash
   only changes when the *design* changes.
4. **Compare to memory** (`poll_state.json`):
   - tracked node, hash differs → **rebuild** it.
   - tracked node, hash same → skip.
   - **not** tracked yet → **onboard** it (create a new block), up to the per-run cap.
   - a node's *first* sighting is recorded as a silent baseline (it's already in sync / just built).
5. **Find deletions** — any **tracked** node (in the manifest) that is **no longer Ready for dev**
   (status cleared, or the frame was removed from Figma) → its block + playground are **deleted**,
   and it's dropped from the manifest, index, and `poll_state.json`. **`COMPLETED` nodes are
   exempt** (kept, frozen).
6. **Write the new hashes** back to `poll_state.json` (committed by the workflow, along with any
   deletions).

**Deletion safety stops** (`poll.py`): a bad or partial Figma read must never wipe the library, so
deletion **refuses** to run if it would remove more than **`DELETE_CAP`** blocks in one cycle
(default 10), or if it would remove **every** tracked block at once. Either case logs an error and
skips all deletions that cycle. If you genuinely need to delete more than the cap, raise the
`DELETE_CAP` env var on the poll workflow. Deleted files are always recoverable from git history.

So `poll_state.json` is the pipeline's memory of "what each design looked like last time," and the
manifest is "which node maps to which file."

---

## 10. Naming rules

When a **new** Ready-for-dev node is onboarded, its filename is derived from the **Figma frame's
name**:

- The name is lower-cased and non-alphanumeric runs become underscores (a "slug").
  e.g. `Acquisitions No Background` → `acquisitions_no_background`.
- It's written to `email-design-system/sections/section_<slug>.html`, with a matching
  `email-design-system/playground/<slug>.html` preview.

**Implications:**
- **Spelling matters, and the name locks at creation.** The frame named `aquisitions-no-background`
  (missing the "c") creates `section_aquisitions_no_background.html`. **Renaming the frame later does
  NOT rename the file** — the name is fixed when the block is first created (the manifest maps the
  Figma node id to that path; later rebuilds reuse it). To fix a name: **clear the frame's
  Ready-for-dev status** (deletes the old file), rename the frame, then **mark Ready for dev again**
  (re-creates it under the new name).
- **Name collisions are skipped, not clobbered.** If a new frame's slug matches an existing block's
  file, the pipeline skips it and logs a warning rather than overwriting.

Existing/refreshed blocks keep whatever filename they already have (recorded in the manifest).

---

## 11. The validation gate

Before any block is committed, `validate_block.py` runs and **blocks the commit** if the block is
broken. It checks, deterministically:

1. File is non-empty.
2. Root CSS class marker matches the filename (`section_hero_overlay.html` → `class="section-hero-overlay"`).
3. Sections carry a `<!-- section-desc: ... -->` marker.
4. The `figma-source` comment exists with a real `generated-sha` (64 hex chars, not a placeholder).
5. Every image source is a `[BRACKETED]` placeholder or an absolute `https://` URL — never a Figma
   internal/expiring asset URL, empty, or relative path.
6. No `display:flex` / `display:grid` (not email-safe; email must be table-based).

There's also an earlier guard ("Fail if Claude errored") that fails the run if Claude itself errored
(e.g. an expired token) — without it, an auth failure could look like a green "nothing changed" run.

---

## 12. Maintenance — token expiry, rotation, enable/disable

- **Figma access tokens** are short-lived, but the pipeline mints fresh ones from the refresh token
  automatically every run. You don't manage these.
- **The Figma refresh token (`FIGMA_REFRESH_TOKEN`)** is long-lived and reusable indefinitely. You
  only regenerate it if it's revoked, if you re-authorize the OAuth app (which **invalidates the old
  one** — you must then update the secret), or as a security rotation. Regenerate via §7.2 and update
  the GitHub secret.
- **Rotating the Figma client secret** (`FIGMA_CLIENT_SECRET`): regenerate it in the Figma app
  settings, update the GitHub secret. (Note: rotating the secret may require re-minting the refresh
  token too.)
- **`CLAUDE_CODE_OAUTH_TOKEN`** lasts a long time but can expire/revoke. If builds start failing with
  an auth error, run `claude setup-token` again and update the secret.
- **To pause everything:** disable the `figma-poll` workflow (§8). The builder won't run on its own
  without the poller calling it.

After updating any secret, you can verify with a manual poll (§5) or by running `figma-build` directly.

---

## 13. Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| Marked Ready for dev but nothing built | Wrong file — check the `FIGMA_FILE_KEY` **variable** matches the file you're editing (§7.0). Or the poll hasn't run yet (≤20 min) — trigger manually. Run with `onboard_cap=0` (onboards nothing new, but logs what it found) to see what the poller detects. |
| A build run failed (red ✗) | Open it in the Actions tab. "Fail if Claude errored" red = Claude/token problem (re-mint `CLAUDE_CODE_OAUTH_TOKEN`). "Validation gate" red = the generated block was malformed; the log lists exactly which check failed. |
| Figma fetch step fails | Refresh token invalid/revoked → re-mint `FIGMA_REFRESH_TOKEN` (§7.2). The error prints the HTTP status from Figma. |
| It built something I didn't want | That node was marked Ready for dev. **Clear its status** in Figma → the next poll deletes the block (recoverable from git). |
| It deleted a block I wanted to keep | Its status was cleared (or the frame removed). Restore the file from git history, then either re-mark it Ready for dev or set it to **Completed** (which is protected from deletion). |
| A big unmark didn't delete anything | Hit a safety cap — more than `DELETE_CAP` (10) blocks, or *all* of them, would have been removed in one cycle. Check the run log; raise `DELETE_CAP` if the bulk delete is intentional. |
| A tracked block stopped updating | It's no longer marked Ready for dev. Re-mark it. |
| Two builds collided on commit | Handled automatically — the commit step rebases and retries up to 3×. |
| Wrong/misspelled filename | Derived from the frame name — rename the frame (§10). |
| Scheduled poll stopped running | GitHub disables cron after 60 days of repo inactivity — re-enable the workflow (§8). |

To read any run's detail: **Actions** tab → click the run → click a job → expand steps. Or
`gh run view <run-id> --log`.

---

## 14. If you ever upgrade Figma to Organization/Enterprise

On those plans Figma **does** deliver webhooks, so you could switch from 20-minute polling to
**instant** updates. The old webhook receiver was removed but is in git history. To go that route
you'd: restore the receiver, host it (it's a tiny PHP/Python endpoint), register a Figma
`DEV_MODE_STATUS_UPDATE` webhook pointing at it, and have it call `repository_dispatch` on
`figma-build.yml` per changed block. The polling setup can keep running as a backstop. Until then,
polling is the correct and fully-working approach — and needs no server at all.
