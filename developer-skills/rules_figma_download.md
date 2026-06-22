---
---
# Figma → Email HTML — download & refresh

> **What this is — a developer procedure for turning a Figma design into a reusable email block.**
> Given a Figma node, it decides whether the node is a `component_`, `section_`, or `template_`,
> builds it as email-compliant HTML, generates a playground for it, and stamps a link back to
> Figma so the block can be **refreshed** when the design changes. The chat assistant runs this
> using the connected **Figma Dev Mode MCP**; the PHP pipeline can't reach Figma, which is why
> this lives in `developer-skills/`.
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

---

## Step 2 — Pull the Figma node (the spec)

From the node URL (`…/design/<fileKey>/<name>?node-id=<node-id>`), pull via the Figma Dev Mode MCP:

- **Screenshot** — the visual source of truth to match.
- **Variables / tokens** — colors, spacing, type sizes.
- **Structure / metadata** — layer order and grouping.

## Step 3 — Reconcile tokens with the style guide

`email-design-system/rules_email_style_guide.md` is the **single source of visual truth.** Map
every Figma token to its existing value there. If Figma introduces a genuinely new value (a color
or size not in the style guide), **add it to the style guide** and reference it — do **not**
hardcode a one-off hex/size in the block. Call out any new token in your summary.

## Step 4 — Build the block (email-compliant)

Build to `rules_email_build.md`:

- Table-based, inline CSS, MSO conditionals; no `div`/flex/grid.
- Root class marker — `class="section-<name>"` or `class="component-<name>"` (the validator checks for it).
- Put **every editable value in a `[BRACKETED]` placeholder** so the playground and the brief can fill it. Content is never baked in.
- For a **section**, add the `<!-- section-desc: … -->` marker as the very first line, so the brief §7 list and `index.html` pick it up automatically.

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
     generated-sha: <sha256 of the freshly generated file, before any hand edits>
     On "refresh" this node is re-pulled and this file + its playground are rebuilt.
     See developer-skills/rules_figma_download.md -> Refresh for how hand edits are preserved. -->
```

`file` + `node` are what a refresh re-pulls. `generated-sha` records the clean generation as the
baseline used to detect hand edits (see Refresh).

## Step 6 — Generate the playground

Create `email-design-system/playground/<name>.html` following the existing playground pattern
(`initPlayground` on `playground.css` + `playground.js`): one control per dynamic value — text/URL
inputs for content, an **image-URL field for each image** (default = the placehold.co URL), and
**style-token controls** (e.g. a background-color picker) whose options are the style-guide values.
Defaults are filled with the Figma values; live preview + copy-the-HTML. Carry the same `figma-source`
link in a comment so the playground refreshes alongside the block.

## Step 7 — Verify

- A new **section** auto-appears in the brief form's §7 list and in `index.html` (the folder is the
  source of truth — no list to hand-maintain).
- Run `test/validate.py` against a sample build that uses the block.

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
- [ ] Reviewed with `git diff`.
