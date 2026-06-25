---
---
# Figma → Email HTML — download & refresh

> **What this is — a developer procedure for turning a Figma design into a reusable email block.**
> Given a Figma node, it decides whether the node is a `component_`, `section_`, or `template_`,
> builds it as email-compliant HTML, generates a playground for it, and stamps a link back to
> Figma so the block can be **refreshed** when the design changes. Two things follow this same
> procedure: the **interactive chat assistant** (which pulls the design from the connected **Figma
> Dev Mode MCP**) and the **automated GitHub Actions builder** (`.github/workflows/figma-build.yml`,
> which is handed the same design via the REST API as `./_figma_in/node.json` + `node.png` — see
> `figma-pipeline/figma_fetch.py`). Where the two differ, it's called out inline.
>
> **Use it when** someone gives you a Figma node/URL and asks to "download" or "import" it, or asks
> to "refresh the design."

## What this is NOT — Figma can't hand you email HTML

Figma's code export and Dev Mode emit `<div>` / flexbox / React-style markup. Our emails are the
opposite: **table-based, inline-CSS, Outlook-safe** HTML (see
`email-design-system/rules_email_build.md`). So this is **not** a copy-paste of Figma's HTML. You
pull the Figma node as a *spec* — its screenshot (visual truth), its variables (tokens), and its
structure — and **build** the block to the email rules. The output is email HTML; the Figma link
embedded in it is what makes it refreshable.

---

## Step 0 — New or update? Check the Figma link first

**Downloading a node is an upsert, keyed on the Figma node** — never blindly create. Before building
anything, search the existing blocks (`sections/`, `components/`, `templates/`) for a `figma-source`
comment whose `node:` matches the node you were given:

- **No match → create.** It's a new control: classify it (Step 1) and build it (Steps 2–7).
- **Match found → update.** The control already exists — do **not** create a second one. Run
  **Refresh** on the matching file (re-pull and rebuild it in place, preserving hand edits). Its
  kind, prefix, and filename stay as they are; only its contents update.

This keeps re-downloading the same node idempotent — one Figma node maps to exactly one control,
forever. ("Refresh everything" is just this update path run across every figma-sourced file at once.)

---

## Step 1 — Classify: component, section, or template?

This sets the prefix and the folder. (Same taxonomy as the prefix system in
`prompt_email_generation.md`.)

| If the Figma node is… | It's a… | Goes in… | Named |
|---|---|---|---|
| A whole email (header → content → footer — a complete send) | **template** | `email-design-system/templates/` | `template_<name>.html` |
| A standalone band that stacks inside an email and could be named in brief §7 (hero, specs panel, reviews, CTA banner, …) | **section** | `email-design-system/sections/` | `section_<name>.html` |
| A small reusable element used *inside* sections (button, card, badge, divider) | **component** | `email-design-system/components/` | `component_<name>.html` |

Tie-breakers: used *inside* other blocks → component; a full self-contained band → section; an
entire email → template. **If you're genuinely unsure, ask rather than guess** — the wrong bucket
changes how the brief and the build treat the block.

### Naming & variants

- **Name by structure, not by content or segment.** Two heroes that differ in *layout* are
  different controls: `section_hero_overlay.html` (text on a photo) vs `section_hero_stacked.html`
  (a text panel above a photo). A segment or campaign label (`acquisitions`, `memorial_day`) is
  **never** part of the name.
- **Near-identical nodes are variants, not new files.** If a new node has the *same structure* as
  an existing control and differs only in image / copy / color, do **not** create a second file —
  those differences are `[BRACKETED]` values, optionally surfaced as named presets in the
  playground. Only a genuinely different *structure* earns a new file. This sharpens Step 0:
  *same node id → update; same structure, new node → variant; new structure → new file.*

---

## Step 2 — Pull the Figma node (the spec)

From the node URL `…/design/<fileKey>/<name>?node-id=<id1>-<id2>`: the **fileKey** is the path
segment; the **node id** is `<id1>:<id2>` (the tools accept the URL's `4618-108` form and you pass
`4618:108`). Then pull via the connected Figma Dev Mode MCP:

- **`get_design_context(fileKey, nodeId)`** — returns a **screenshot** (the visual source of truth),
  reference **code**, and the node's image asset URLs. The code is **React + Tailwind — a SPEC to
  translate, not to copy** (see Step 4). Those image asset URLs **expire in ~7 days** and are not
  hosted by us — never put them in the output (use placehold.co; see Images).
- **`get_variable_defs(fileKey, nodeId)`** — bound design **tokens**. Often `{}` when the design uses
  raw values; then read hexes/sizes off the code + screenshot.
- **`get_metadata(fileKey, nodeId)`** — the node's **size + layer tree** (names, types, x/y/w/h). The
  top-level frame name (e.g. `acquisitions-background`) is the design's intent; the `mj-*` child names
  (`mj-hero-Frame`, `mj-text-Frame`, `mj-button-Frame`, `mj-section`, `mj-column`) are MJML-ish email
  block hints. **600px wide = full email width.**

> **In the automated builder** these three MCP calls don't exist — read the equivalents that
> `figma_fetch.py` already downloaded: `./_figma_in/node.json` (structure, tokens, layer tree) and
> `./_figma_in/node.png` (the screenshot / visual source of truth).

## Step 3 — Reconcile tokens with the style guide

`email-design-system/rules_email_style_guide.md` is the **single source of visual truth.** Map
every Figma token to its existing value there. If Figma introduces a genuinely new value (a color
or size not in the style guide), **add it to the style guide** and reference it — do **not**
hardcode a one-off hex/size in the block. Call out any new token in your summary. **But distinguish
three cases:** a genuinely new *brand* token → add it to the style guide; a value that *conflicts*
with an existing rule (e.g. a coral CTA when the guide says the primary CTA is yellow) → **flag it,
don't silently add**; a *per-campaign accent* (a seasonal promo's pink/magenta) → keep it a dynamic
knob defaulting to the Figma value, **not** a brand token.

## Step 4 — Build the block (email-compliant)

Build to `rules_email_build.md`:

- Table-based, inline CSS, MSO conditionals; no `div`/flex/grid.
- Root class marker — `class="section-<name>"` / `class="component-<name>"`, where `<name>` is the filename's name part with underscores → hyphens (`section_hero_overlay.html` → `class="section-hero-overlay"`). The validator checks for it.
- Put **every editable value in a `[BRACKETED]` placeholder** so the playground and the brief can fill it. Content is never baked in.
- For a **section**, add the `<!-- section-desc: … -->` marker as the very first line, so the brief §7 list and `index.html` pick it up automatically.

### Translate, don't copy — the recurring recipes

The Figma code shows layout intent (Tailwind `flex`, `rounded-[24px]`, `bg-[#hex]`); rebuild it with
these email-safe patterns so results come out consistent every time:

- **Stacked block** (text panel above/below an image, no overlap) → a solid-`bgcolor` `<td>` for the
  text and a normal `<img>` row for the photo. No VML needed; bulletproof everywhere. Reference:
  `section_hero_stacked.html`.
- **Text overlaid on a photo** (Outlook drops background images) → the bulletproof background pattern:
  `background` + `bgcolor` on the `<td>`, an `<!--[if gte mso 9]><v:rect><v:fill type="frame">…`
  block with a **light solid fallback color**, text inside a `<v:textbox>`/`<div>`, and a spacer row
  to give the photo height. Flag that the height is client-sensitive. Reference: `section_hero_overlay.html`.
- **Any CTA** → reuse `component_button.html` verbatim (MSO `v:roundrect` + non-MSO `<a>` pill).
  Standard placeholders: `[CTA_LABEL]`, `[CTA_URL]`, `[CTA_FILL]`, `[CTA_TEXT_COLOR]`; keep the MSO
  `width:` near the visible button width.
- **`rounded-[24px]`** → `border-radius:24px`; **`rounded-tl/tr`** → top-only radius, etc. (per the style guide's 24px rule).
- **Icon-ish graphics** (star ratings, eyebrow badges) → render as **type, not images**, matching the
  existing sections: stars as `&#9733;` in Halo Yellow; an eyebrow as styled uppercase text. Flag in
  your summary that you replaced a graphic with type.

### Decide what's dynamic — and where its values live

Don't hardcode a static snapshot. As you build, decide what a user might reasonably want to vary and
expose it; leave the rest fixed.

- **Dynamic content** (text, links, image URLs) → `[BRACKETED]` placeholders, surfaced as playground
  fields and (for sections) brief-fillable. e.g. `[HEADLINE]`, `[CTA_LABEL]`, `[CTA_URL]`, `[IMG_HERO_URL]`.
- **Dynamic style values** (background color, accent/button color, sometimes padding or radius) are
  **design tokens, and they must come from the style guide** (`rules_email_style_guide.md`) — never a
  one-off hex in the block. If a value can vary, the block defaults to the style-guide token and the
  playground offers a control whose options *are* the style-guide values (e.g. a background picker
  limited to the palette). If a needed token isn't in the style guide yet, add it there first (Step 3).
- **Fixed** = structural or brand-locked things (the table scaffold, the logo, the footer wording).
  Leave them sourced/hardcoded; don't invent knobs for things that shouldn't be touched.

Rule of thumb: **content and tokens are dynamic; structure and brand assets are fixed.** When unsure
whether something should be a knob, note the call in your summary rather than guessing silently.

### Images — never ship a broken one

Figma image fills don't carry a hosted URL, so an `<img>` lifted straight from Figma renders broken.
Every image in a downloaded block therefore defaults to a **placeholder service** —
`https://placehold.co/<width>x<height>?text=<label>` at the node's real dimensions — never an empty
or Figma-internal `src`. The real image is a `[BRACKETED]` placeholder (e.g. `[IMG_HERO_URL]`), and
the **playground exposes an editable image-URL field** so anyone can paste a real hosted URL and see
how it looks. (In a live email the hosted URL comes from the brief or the rule files per the build
rules; the placehold.co URL is only the safe default/fallback.)

## Step 5 — Stamp the `figma-source` comment (this enables refresh)

Add this comment at the top of the file (just under the `section-desc` for sections):

```html
<!-- figma-source
     file:          <fileKey>
     node:          <node-id>          e.g. 1234:5678
     url:           https://www.figma.com/design/<fileKey>/<name>?node-id=<node-id>
     kind:          section            (component | section | template)
     fetched:       2026-06-21
     generated-sha: <sha256 of the block markup — compute per the command below>
     On "refresh" this node is re-pulled and this file + its playground are rebuilt.
     See developer-skills/rules_figma_download.md -> Refresh for how hand edits are preserved. -->
```

`file` + `node` are what a refresh re-pulls. `generated-sha` is the **refresh baseline**: the sha256
of the **block markup only** — from the root element (the line beginning `<tr class="…"` or
`<table class="…"`) to end of file, *excluding* this comment header — so it's stable and doesn't
hash itself. Compute it after writing the file, then paste it into the comment:

```bash
# -E for ERE alternation — works on both GNU and BSD/macOS sed (plain BRE \| does NOT on macOS)
sed -nE '/^<(tr|table) class="/,$p' <file> | shasum -a 256
```

## Step 6 — Generate the playground

Copy an existing playground (e.g. `playground/hero_stacked.html`) and adapt it — they all share one
skeleton: header, `<main>` with a `.lede`, the `pg-grid`, and a `<script>` calling
`initPlayground({ template, fields, wrap })`. Then:

- **template** — the block markup as a JS string (each line `'…\n' +`); escape single quotes
  (`\'Inter\'`, `url(\'[IMG]\')`). The MSO conditional comments can be dropped from the *playground*
  copy (browsers ignore them) — keep them in the section file itself.
- **fields** — one per `[BRACKETED]` value. Supported `type`s: `text`, `url`, `textarea`,
  `select` (+ `options`), `color` (picker + hex). Map content → text/url/textarea; an image → `url`
  defaulting to the placehold.co URL; a **style token** → `color`, or a `select` whose `options` are
  the style-guide values.
- **Variants** (same-structure designs) → add a `select` plus `presets: { VARIANT: { optionValue: { KEY: value, … } } }`
  so picking a variant swaps the defaults. This is how near-identical Figma nodes live as **one**
  control instead of many files.
- **wrap** — the 600px container function; copy it verbatim from a sibling playground.
- Add a `figma-source` comment at the very top so the playground refreshes alongside the block.

## Step 7 — Verify, wire in, ship

Verify the markers (replace `<name>`):

```bash
f=email-design-system/sections/section_<name>.html
grep -q '<!-- section-desc:' "$f" && grep -q 'class="section-<name>"' "$f" \
  && grep -q 'generated-sha: [a-f0-9]' "$f" && ! grep -q GENSHA "$f" && echo OK
ls email-design-system/playground/<name>.html        # playground exists
```

Then:

- Confirm the new **section** reads correctly in the brief form's §7 list (the form auto-discovers
  `section_*.html` minus header/footer — no list to hand-maintain).
- **Wire it into the index** — *interactive path only.* Add one `<li>` under the *Email Design
  System* group in `index.html` linking the block's rendered playground
  (`/halo-resources/email-design-system/playground/<name>.html`), matching the existing entries.
  **In the automated pipeline this is done for you** by `figma-pipeline/generate_index.py`
  (append-only — it adds the entry).
- Run `test/validate.py` against a sample build that uses the block.
- **Commit and push** — *interactive path only* (`git add -A && git commit && git push origin main`).
  **In the automated pipeline the builder must NOT commit** (the build prompt says so); the
  workflow's "Commit on green" step pushes for you, only after the validation gate passes.

---

## Bulk scan / import (many blocks at once)

You don't need a link per block — you can enumerate a container — but **scan a subtree, not the whole
file**: `get_metadata` on a large page can time out (the Halo file's single page does). Work
container-at-a-time:

1. `get_metadata(fileKey)` with no node lists the **pages**; pick one (or have the user select a
   group/section frame in Figma and copy its link — one link covers many blocks).
2. `get_metadata(fileKey, <containerId>)` → the subtree of named child frames = the candidate blocks.
3. **Inventory, don't blind-download.** For each candidate, propose: structural **name**, **kind**,
   and **new vs variant-of-existing vs already-downloaded** (dedup by `figma-source` node id +
   structure). Present the list.
4. Get the user's OK on the import set, then run Steps 1–7 per approved block, folding same-structure
   nodes into one control as variants/presets.

**Never silently create a file per node** — that reproduces the duplicate-control problem the naming
rules exist to prevent.

---

## Refresh — re-pull from Figma, **preserving hand edits**

Trigger: **"refresh `<name>`"** (one block) or **"refresh everything"** (every figma-sourced file).
"Refresh everything" = scan `sections/`, `components/`, and `templates/` for `figma-source`
comments and refresh each one.

Refresh must **not** blow away manual edits. Two layers protect them:

### Layer 1 — things always preserved verbatim
- **`[BRACKETED]` placeholders** — the content/brief layer; refresh never fills or removes them.
- **Guarded regions** — wrap any deliberate manual change in guards:
  ```html
  <!-- local:start (why) -->  …your edit…  <!-- local:end -->
  ```
  Everything between the guards is kept exactly; only the unguarded, Figma-owned markup is rebuilt.

### Layer 2 — 3-way merge for everything else
1. **Baseline** = the clean generation recorded by `generated-sha` (and git history).
2. **Local edits** = current file vs baseline (what a human changed since the last generation).
3. **New design** = freshly built HTML from the re-pulled Figma node.
4. Merge each region:
   - Unchanged by hand → take the **new design**.
   - Hand-edited, Figma part unchanged → **keep the hand edit**.
   - Hand-edited **and** changed in Figma → **conflict**: keep both, wrap them in
     `<!-- conflict: figma vs local … -->` markers, and flag it in your summary for a human to
     resolve. **Never silently clobber a hand edit.**
5. Update `fetched:` and `generated-sha:` to the new clean baseline, regenerate the playground the
   same way, and **report what changed**. Review with `git diff` before committing; roll back if a
   merge went wrong.

> **Honest limit:** perfect automatic preservation can't be guaranteed. `[BRACKETED]` placeholders
> and `local:`-guarded regions are preserved reliably; everything else is a best-effort 3-way merge
> that **surfaces conflicts** rather than guessing. For any structural hand edit you care about,
> put it inside a `local:` guard.

---

## Checklist (per downloaded or refreshed block)

- [ ] Checked the Figma node link first — created only if new, updated in place if a control already has that node (no duplicates).
- [ ] Classified correctly (component / section / template); saved with the right prefix in the right folder.
- [ ] Built to the build rules: tables, inline CSS, MSO, root class marker, `[BRACKETED]` placeholders for all editable values.
- [ ] Decided what's dynamic: content + tokens are knobs (tokens sourced from the style guide); structure and brand assets stay fixed.
- [ ] Images default to a placeholder service (`placehold.co`) at real dimensions — no broken images — and are editable in the playground.
- [ ] Tokens reconciled against the style guide; any new token added **there**, not hardcoded.
- [ ] `figma-source` comment present (file, node, url, kind, fetched, generated-sha).
- [ ] A section also carries its `<!-- section-desc: … -->` marker.
- [ ] Playground generated (or refreshed) and carries the figma-source link.
- [ ] On refresh: `[BRACKETED]` + `local:` regions preserved; conflicts flagged, not clobbered; `fetched`/`generated-sha` updated.
- [ ] Wired into `index.html` (Email Design System group → rendered-playground link).
- [ ] Reviewed with `git diff`, then committed and pushed.
