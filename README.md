---
---
# Halo Email Resources — Developer README

This repository is a **brand-neutral email-building engine**. You give a model these
resources plus a filled-in brief, and it produces production-ready, cross-client HTML
marketing emails. This README explains how the system is put together and how to work in
it. It's for the team — not for the model.

> **Not to be confused with `prompt_email_generation.md`.** That file is the instruction
> *to the model* (the entry point it reads). This README is documentation *for us* about
> how the whole thing works.

---

## Mental model

Think of it in three layers:

1. **Initial prompt** (lives in the tool's settings, not in this repo) — a thin signpost. It sets the role and tells the model to start by reading `prompt_email_generation.md`. It never holds build rules or brand facts.
2. **`prompt_email_generation.md`** (this repo) — the orchestrator / entry point. It defines the flow, the prefix system, and the order to use everything. It points; it doesn't restate.
3. **Everything else** (this repo) — the modular resources: rules, briefs, sections, components, templates, examples, assets. Each is prefixed by role and owned by exactly one concern.

The guiding principle: **the engine is faceless.** What an email is *about* — brand,
product, price, voice, legal text — comes from the **brief**. What an email *looks like*
and *how it's coded* comes from the **`rules_` files**. The orchestrator just stitches
them together.

---

## Setup — standing this project up from scratch

Follow these steps to (re)create the project in a tool that supports project resources
(e.g. a Claude Project or similar). This is everything needed to go from an empty project
to a working email engine.

### 1. Create the project and connect this repo

1. Create a new project in the tool.
2. Connect this GitHub repository as the project's resource source so its files are pulled in:
   - In the project's settings, add a GitHub connection / integration.
   - Authorize access to the org/account that owns this repo.
   - Select this repository (and the branch you want — typically `main`).
   - Confirm the files sync in. You should see the prefixed files (`prompt_`, `rules_`, `brief_`, `sample_`, `img_`, `social_`) appear as project resources.
3. Re-sync whenever the repo changes. Adding or editing a file in GitHub means re-pulling so the project picks it up. (If the tool doesn't auto-sync, trigger a manual refresh.)

> **Why GitHub?** It keeps the resources versioned and editable by the team. The project
> consumes a flattened copy; GitHub remains the source of truth.

#### What to sync (and what not to)

Sync **everything the model needs to build an email**, and leave out anything that's only
for the team.

| Sync into the project | Why |
|---|---|
| `prompt_email_generation.md` | The entry point the model reads first. |
| All `rules_*` files (brand, segments, style guide, copy, build, footer, Braze) | Binding standards the model applies. |
| All `section_*.html`, `component_*.html`, and `template_*` files | The blocks/skeletons emails are built from. |
| All `sample_*.html` | Tone and structure reference. |
| All `img_*` and `social_*` images | Brand assets the emails reference by name. |
| `brief_sample.md` | The blank template the model copies/fills per campaign. |

| Do **not** sync | Why |
|---|---|
| `README.md` (this file) | Team documentation. The model never reads it; it only adds noise to the resources. |
| Anything under `.git`, editor configs, etc. | Not resources. |

> **The brief:** sync the blank `brief_sample.md` so it's available as a template. For an
> actual send, you'll either fill in a copy and **attach it to the chat**, or upload the
> filled-in `brief_<campaign>.md` — either works. The model reads whichever brief is
> present for that conversation.
>
> **Images:** every image an email references must be in the project resources (see step 3
> below). GitHub stores them, but the model resolves them from the synced resources.

### 2. Paste the initial prompt

The **initial prompt** lives in the project's custom-instructions / system-prompt field —
**not** in the repo. It's the thin signpost that points the model at the entry file. Paste
this verbatim:

```
You are an email-building engine. You generate production-ready,
cross-client HTML marketing emails from a structured set of resource files.

Before doing anything else, read prompt_email_generation.md. It is the
entry point and README: it defines the full flow, the prefix system for
all resource files, and the order in which to use them. Follow it.

A few things always hold, regardless of what any file says:

- This engine is brand-neutral. The brief defines who the email is for
  and what it says. Never invent a brand, product name, price, offer,
  claim, or legal text — use only what the brief and the prefixed
  resource files provide. If something needed is missing, ask.

- Hero and content images are uploaded by the user via the brief. Never
  generate, source, or substitute images on your own.

- Begin every email by reading the attached brief_ file. If no brief is
  attached, ask for one before proceeding.

Do not wander into other resource files first or improvise a process —
prompt_email_generation.md is the single source of truth for how to work.
```

> Keep this copy in the README so it's recoverable. If you lose the project, you rebuild
> it from: this repo + this prompt.

### 3. Image hosting (hosted URLs, not uploads)

Images are **hosted** and referenced by absolute URL, so they render in a recipient's
inbox:

- **Logo and social icons** are hosted on the CDN; their URLs live in `rules_brand.md` (logo) and `rules_email_footer.md` (the four social icons). These are stable — set once, reused every send.
- **Hero** images are hosted per campaign; the brief carries the live hero URL. The brief *also* attaches a copy of the hero image so the build can see the visual and write matching content — but the email links to the hosted URL, not the upload.

You do not need to upload brand assets into the project resources for them to appear in
emails; the hosted URLs handle that. (Reference copies of the assets still live in the repo
under `design-libraries/assets/` for preview and design reference.)

### 4. Run an email (per-campaign workflow)

Once setup is done, producing an email is repeatable:

1. **Copy the brief.** Duplicate `brief_sample.md`, rename it `brief_<campaign>.md` (e.g. `brief_spring_sale.md`), and fill it in — product, copy, pricing, CTA, hosted hero URL, and (for a test send) §9 sender and §10 test segment.
2. **Host the hero and paste its URL** into the brief (§4), and **attach the image** to the chat so content can match the visual.
3. **Start a chat**, attach the filled-in brief, and ask for the email. The model reads the initial prompt → `prompt_email_generation.md` → the rules → your brief, and builds it.
4. **Review the output** against the checklist in `rules_email_build.md` and the rendering-risk flags the model surfaces.
5. **Send the test (optional)** — if the brief filled in §9 + §10, the build also produces `send_test_body.json` alongside the HTML targeting the test segment. See the next section.

### 5. Sending via Braze (test sends)

This sends an immediate test email through Braze's `/messages/send` endpoint. It targets
a **designated test segment** in a test/non-production Braze workspace — never production.
The schema and safety constraints are documented in `brand-standards/rules_braze_send.md`.

**One-time setup — set your env vars** (do this once per shell session, or add to your
`~/.zshrc` / `~/.bashrc` to persist):

```bash
export BRAZE_API_KEY="your-restricted-messages-send-key"
export BRAZE_REST_URL="https://rest.iad-XX.braze.com"   # your workspace's cluster URL
```

**Important security notes:**

- `BRAZE_API_KEY` should be a **dedicated key** with only the `messages.send` permission — not a shared key with broader scope. Create it in Braze under Settings → APIs and Identifiers → API Keys.
- The key must **never** be pasted into files, briefs, chats, or commits. It lives only in your shell env.
- `BRAZE_REST_URL` is your workspace's REST endpoint (the cluster). You'll find it in your Braze dashboard. Common values: `https://rest.iad-01.braze.com`, `https://rest.iad-03.braze.com`, `https://rest.fra-01.braze.com` (EU), etc.

**Per-test-send workflow:**

The build produces `send_test_body.json` alongside the email HTML. The JSON has
`broadcast: true`, the segment ID from brief §10, and the email object (subject,
preheader, from, body). To run the test:

```bash
# Verify your env vars are set
echo $BRAZE_REST_URL
echo "Key is set: $([ -n "$BRAZE_API_KEY" ] && echo yes || echo NO)"

# Send the test (from the folder containing send_test_body.json)
curl -X POST "$BRAZE_REST_URL/messages/send" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $BRAZE_API_KEY" \
  --data @send_test_body.json
```

Braze returns a JSON response with a `dispatch_id` on success. Check inboxes within a
minute or two. Note: `"message": "success"` only means the request was accepted — it
does not confirm delivery. If nothing arrives, the most common cause is that the
segment is empty or contains users without email addresses.

**Safety reminders:**

- The `send_test_body.json` produced by the engine **always** uses `broadcast: true` + `segment_id` for segment-based sends. It does not include `audience` or `campaign_id`. Do not edit those fields.
- The segment must exist in the workspace the API key targets.
- This pipeline handles **test sends only**. Production emails go through Braze **campaigns** — created and triggered in the Braze dashboard, not through hand-built JSON.

---

## The prefix system

Every file announces its role with a prefix — lowercase, underscores, no spaces or
special characters. The model resolves files **by prefix, not by exact filename**, so the
system grows by *adding correctly-prefixed files*, not by editing the prompt.

| Prefix | Role | Edit / add when… |
|---|---|---|
| `prompt_` | The single entry point (orchestrator). **Only one file.** | …you're changing the *flow itself*. Rarely. |
| `rules_` | Binding standards — design and code. | …you add or change a standard (style, build, future image rules). |
| `brief_` | Per-campaign input, filled in per email. | …you start a new campaign (copy `brief_sample.md`). |
| `template_` | Full email skeletons to start from. | …you have a new repeatable email shape. |
| `section_` | Composed blocks that occupy a vertical slice of the email (header, footer, hero, feature row, etc.). **This is what brief §7 names.** | …you formalize a recurring middle-of-email pattern. |
| `component_` | Reusable primitives used *inside* sections (button, card, etc.). | …you build a new reusable primitive (not a section). |
| `sample_` | Real, shipped example emails for tone/structure reference. | …you want to add a proven email as reference. |
| `img_` | Shared brand images (logo, header). | …you add a shared brand image. |
| `social_` | Footer social icons. | …you add/replace a social icon. |

**Rule of precedence** (when two files overlap): the most specific owner wins. The style
guide wins on visual values (hex, font size, button shape). The build rules win on code
structure. The brief wins on what this specific send says. The orchestrator only routes.

---

## Repository layout

```
halo-resources/
├── prompt_email_generation.md          ← entry point / orchestrator (the ONE prompt_)
├── brief_sample.md                     ← copy per campaign → brief_<name>.md
├── brand-standards/
│   ├── rules_brand.md                  ← brand identity (name, site, voice)
│   ├── rules_segment_definition.md     ← audience segment definitions
│   ├── rules_email_style_guide.md      ← visual standards (from Figma design system)
│   ├── rules_email_copy.md             ← copy/voice + punctuation rules
│   ├── rules_email_build.md            ← HTML / code standards
│   ├── rules_email_footer.md           ← stable footer/legal boilerplate
│   └── rules_braze_send.md             ← Braze /messages/send schema + safety
├── design-libraries/
│   ├── sections/                       ← section_*.html (header, footer, ...)
│   ├── components/                     ← component_*.html (button, ...)
│   ├── templates/                      ← template_*.html   (to be built)
│   └── assets/
│       ├── shared-images/              ← img_*.webp
│       └── social-icons/               ← social_*.webp
├── email-examples/                     ← sample_*.html
├── test/                               ← validation harness (NOT synced; see "Testing" below)
│   ├── validate.py                     ← Python 3 entry point
│   ├── config.ini                      ← paths and toggles
│   ├── validators/                     ← one module per concern
│   └── emails/                         ← campaign packages, gitignored
└── README.md                           ← this file (NOT synced — team docs)
```

> **Folders are for humans, not the engine.** On import, the resources are flattened —
> the model sees files by name/prefix, not by folder. So folder placement is purely about
> making the repo easy for *us* to navigate and edit. A file's **prefix** defines its
> role, wherever it physically sits.

---

## How an email gets made (the flow)

This mirrors the flow inside `prompt_email_generation.md`:

1. **Brief.** Copy `brief_sample.md` → `brief_<campaign>.md`, fill it in, attach it along with the uploaded hero image.
2. **Rules.** The model reads all `rules_` files — binding. Style guide governs look; build rules govern code.
3. **Examples.** It reviews `sample_` emails for tone and structure.
4. **Start point.** If the brief names a template, it starts from a `template_` file; otherwise it assembles from `section_*.html` files (with `component_*.html` primitives inside where needed).
5. **Build.** It places the uploaded image, pulls logo/icons from the `img_`/`social_` assets, and applies the rules throughout.
6. **Check.** It runs the checklist in `rules_email_build.md` and flags client-specific rendering risks.

---

## Testing (validating builds before you send)

A zero-dependency Python validation harness lives in `test/`. It catches the
things the build can get wrong before any email goes out — malformed JSON,
masked-email leaks, HTML that won't render, brief-to-output divergence,
pricing math that doesn't reconcile.

**The `test/` folder does not sync to the project resources** (same as this
README). It's tooling for your local runner, not source material for the engine.

### What it checks

~46 checks across five groups:

- **JSON send body** — schema, required fields, UUID formats, the chat-mask leak (`[email protected]`), forbidden fields (`audience`, `campaign_id`, etc.), `broadcast: true` for segment sends.
- **HTML hygiene** — well-formedness, em-dash detection, `<img>` attribute completeness, absolute URL enforcement, no `display:flex`/`grid`, table-based structure, MSO conditionals, web-safe font fallback.
- **Brand fidelity** — reads `brand-standards/*.md` at runtime; verifies the footer legal line, unsubscribe link, social icon URLs, and brand logo URL actually appear in the HTML.
- **Brief reconciliation** — confirms the JSON's `app_id`, `from`, and `segment_id` match what the brief said; verifies pricing math reconciles; confirms brief prices and headline appear in the HTML. This is the "not making things up" layer.
- **W3C HTML validation (optional)** — disabled by default; when enabled, uses W3C's free web service at validator.w3.org/nu (no install needed) with email-specific exceptions filtered out.

To see the full catalog with descriptions:

```bash
python3 test/validate.py --list-checks
```

### Usage

Drop campaign packages into `test/emails/`, one folder per campaign, each with
**exactly one `.html` file, one `.json` file, and one `.md` file** (filenames
don't matter — found by extension). Then run:

```bash
python3 test/validate.py
```

Exits 0 if no errors, 1 if any campaign failed. Use it as a gate before
running the `/messages/send` curl:

```bash
python3 test/validate.py && curl -X POST "$BRAZE_REST_URL/messages/send" ...
```

### Config

`test/config.ini` controls paths, output verbosity, fail-fast behavior, and
the W3C toggle. All paths resolve relative to the `test/` folder so the script
runs identically from any working directory. The `test/README.md` documents
each setting in detail.

### Limits

The harness catches **mechanical and factual** errors — things that can be
checked against the rules files or the brief. It can't catch *editorial*
problems: whether the copy is good, the layout feels right, or the tone
lands. Human review still required.

---

## How to extend the system

The whole point of the design is that **you rarely touch the orchestrator.** To add
capability, drop in a correctly-prefixed file:

- **New standard** (e.g. copy/voice rules, or image-generation rules if that ever returns) → add a `rules_` file in `brand-standards/`. The flow already says "read all `rules_` files."
- **New section** (composed block like hero, feature row) → add a `section_*.html` file. The flow already assembles from `section_*.html`.
- **New component** (primitive like button, card, used inside sections) → add a `component_*.html` file.
- **New email shape** → add a `template_` file. The flow already starts from `template_`.
- **New reference email** → add a `sample_` file. The flow already reviews all `sample_`.
- **New asset** → add an `img_` or `social_` file.

**Only edit `prompt_email_generation.md` to change the flow itself** — never just to
register a new file.

### Conventions for new files

- Match the prefix to the role (see the table above).
- Lowercase, underscores, no spaces or special characters.
- Keep each `rules_` file scoped to **one domain** (build, style, copy…). Avoid a catch-all `rules_everything.md`.
- Put binding standards in `brand-standards/`. If something is reference-but-not-binding, it isn't a `rules_` file — give it its own prefix and home.

---

## What lives where (quick reference)

| Concern | Home |
|---|---|
| Role + "start here" handoff | Initial prompt (tool settings, not this repo) |
| Flow, prefix system, precedence | `prompt_email_generation.md` |
| Brand identity (name, site, voice) | `brand-standards/rules_brand.md` |
| Audience segment definitions | `brand-standards/rules_segment_definition.md` |
| Colors, type, buttons, logos | `brand-standards/rules_email_style_guide.md` |
| HTML structure, CSS, Outlook, image markup, checklist | `brand-standards/rules_email_build.md` |
| Footer, legal line, unsubscribe text (stable across sends) | `brand-standards/rules_email_footer.md` |
| Braze `/messages/send` schema + safety constraints | `brand-standards/rules_braze_send.md` |
| Product, price, copy, CTA, hero image (per campaign) | the active `brief_` file |
| Composed blocks (header, footer, hero, feature row, ...) | `design-libraries/sections/` |
| Reusable primitives (button, card, ...) used inside sections | `design-libraries/components/` |
| Email skeletons | `design-libraries/templates/` |
| Tone / pattern reference | `email-examples/` |
| Logo, icons | `design-libraries/assets/` |
| Pre-send validation (JSON, HTML, brief reconciliation, optional W3C) | `test/validate.py` (config in `test/config.ini`) |

---

## Brief reference

Everything in `brief_sample.md`, explained — so anyone filling one in knows what each
field does and how it's used. Copy `brief_sample.md` → `brief_<campaign>.md`, fill it in,
provide the hero image, and attach it to the chat.

### 1. Campaign basics

- **Product / subject** — what the email is about. Products vary per send, so this lives in the brief (brand does not — it's fixed in `rules_brand.md`).
- **Campaign name** — your internal label for the send.
- **Occasion / theme** — the hook (holiday, awareness month, flash sale, evergreen).
- **Send date** — when it goes out.
- **Primary goal** — the one action the email is built around (drive purchase, re-engage, announce).

### 2. Audience — segments

The email's angle is driven by **which segment it targets**. Name the segment in the brief;
its definition drives the message, tone, and offer emphasis.

> Segment definitions live in `brand-standards/rules_segment_definition.md` — that's the
> source of truth (and it's a synced `rules_` file, so the model reads it at build time).
> Add or refine segments there, not here. Example: **Acquisition** = people we're targeting
> to buy the product but who don't own one yet → email emphasizes core value, trust, and a
> low-pressure CTA.

### 3. Content

- **Headline** — the largest piece of text in the email body. Sits below the hero, usually one short line, sentence case. Provide it, or write `suggest` and the build drafts one to match the campaign and segment.
- **Subhead** — the smaller line directly under the headline. Adds context or specifics ("for Dad and pup", "$50 off through Sunday"). Optional; write `suggest` or leave blank.
- **Key message or offer** — the single thing the email is trying to communicate. Drives the body copy and what gets emphasized. If there's an offer, state it here in plain terms (e.g. "$50 off the Halo Collar 5"). The build won't invent an offer that isn't stated.
- **Body** — there is no body field. Body copy is **AI-generated** from the subhead, key message, segment, and brand voice. To constrain the generated body, leave a note in §8 (Notes for the builder).

> Tone is **not** a brief field — it's fixed (warm and inviting) and defined in
> `rules_brand.md`. Every email uses it.

### 4. Hero image (hosted URL + reference upload)

The hero is **hosted** — the brief supplies its live URL and the email links to that URL
directly. The brief **also attaches the actual image** so content can be written to match
the visual (subject, mood, what's pictured). The engine never generates or sources hero
imagery. Fields: hosted hero URL, reference image attached, and alt text (for accessibility
and the Outlook image-off fallback).

### 5. Pricing

Fill only what applies; the point is to make the **relationship between numbers
unambiguous**:

- **Show pricing?** — yes/no.
- **Original price** — the pre-discount/list price, if shown.
- **Sale / final price** — what the customer actually pays.
- **Discount** — e.g. "$50 off" or "20% off"; must reconcile with the two prices above.
- **Promo code** — if any.

> Example: Original $529, Sale $479, Discount "$50 off" → shown as ~~$529~~ $479, $50 off.
> Never imply a discount stacks on an already-discounted price unless that's explicitly stated.

### 6. Call to action

Up to **three** CTAs, each a label + destination. **A blank row renders nothing** — so one
strong CTA is fine; fill rows 2 and 3 only if the campaign needs them. (Many competing CTAs
dilute a promo; reserve multiple links for content-roundup layouts.)

### 7. Structure & starting point

- **Start from a template?** — `newsletter`, `promo`, or `none` (build fresh). If a template is named, the build starts from that `template_` file. If none and no sections are specified, the build amalgamates the structure of the `sample_` emails (see "How an email gets made").
- **Sections to include** — the building blocks to assemble, in order. Free-text, but use the names from the **Section vocabulary** appendix at the bottom of this README so the build maps them cleanly. The header and footer sections are always included automatically; this field governs the campaign-specific middle. Leave blank to let the build choose a sensible order from the sample patterns.
- **Anything to exclude** — call out anything to leave off.

### 8. Notes for the builder

Free-form text the build will read alongside the rest of the brief. Useful for things
that don't fit anywhere else, including:

- Constraints on the generated body copy ("don't mention training time," "keep it under 100 words").
- References to a past email to match in tone or structure ("similar to last year's Black Friday").
- One-off exceptions to standard rules ("for this send only, swap the footer for the holiday version").
- Anything you'd tell a human teammate verbally that affects how the email gets built.

Leave blank if there's nothing campaign-specific to say.

### 9. Sender (for the Braze send)

Two fields that travel with the send request, not the email design:

- **Braze `app_id`** — the App Identifier for the email app you're sending from (from Braze → Settings → APIs and Identifiers → App Identifiers). Stable across most campaigns; may vary if you have multiple apps.
- **`from`** — the sender, in Braze's required format: `Display Name <[email protected]>`.

Neither is a secret — both live safely in the brief. The API key and Braze REST URL do
**not** live here; they're env vars on the runner's machine (see "Sending via Braze").

### 10. Test targeting (segment)

Test sends target a designated test segment in a non-production Braze workspace. The
brief provides the segment **UUID**, and that's all the build needs.

> **Production sends are not done through this pipeline.** Production emails go through
> Braze **campaigns** — created in the Braze dashboard and triggered there. The
> `/messages/send` path documented here is exclusively for test sends.

---

## Section vocabulary

The named blocks the build understands when you list them in brief §7. Each section is a
composed block of HTML that occupies a vertical slice of the email — usually a paragraph,
a row of imagery, or a structured grouping. Sections can be built from primitives
(`component_*.html` files like the button), but they are not the same thing as
components. **Brief §7 names sections, not components.**

The source of truth for what sections exist is **`design-libraries/sections/`** — every
`section_*.html` file in that folder is a recognized section name.

### Currently formalized

| Section | What it is |
|---|---|
| `header` | Logo at top of the email, left-aligned. **Always included** — you don't need to name it in §7. |
| `footer` | Social icons, legal/address line, unsubscribe link. **Always included** — you don't need to name it in §7. |

### Common recurring patterns (not yet formalized as section files)

These appear in the `sample_` emails repeatedly. The build will assemble them fresh from
the sample patterns and the rules when you name them in §7. Promote any of them to a
`section_*.html` file when the shape stabilizes:

- `hero` — top image + headline + subhead; the visual anchor below the header.
- `feature row` — a row of icon + label + short copy, usually 2-3 columns; describes product features or benefits.
- `offer / pricing` — the deal: price, discount, promo code. **Bold text, not a pill** (pills are reserved for CTAs — see `rules_email_style_guide.md`).

### How the system grows

When a section pattern stabilizes across multiple campaigns, promote it to a
`section_*.html` file in `design-libraries/sections/`. The vocabulary above expands
automatically — there's no separate list to maintain. If you're describing something
that doesn't fit any pattern here, describe it in your own words and add detail in §8
(Notes for the builder).

> **Components ≠ sections.** Primitives like the button live in
> `design-libraries/components/` and are used *inside* sections. Don't name a component
> in §7 — name a section.

---

## Notes & open items

- **Style guide is the one brand-specific file.** Its tokens are named from the source Figma design system (e.g. "Halo Yellow"). That's intentional — it documents a real design system. If the engine ever runs multiple brands, each brand supplies its own `rules_email_style_guide.md`; the *engine* stays faceless.
- **Templates aren't built yet.** The orchestrator references `template_*.html` files by convention so they work the moment they're added.
- **Sections are sparse on purpose.** Only `section_header.html` and `section_footer.html` are formalized today. Common patterns like hero, feature row, and offer block are assembled fresh from the sample emails and the rules; promote them to `section_*.html` when the shape stabilizes across campaigns.
- **Product facts are not hard-coded anywhere.** Product name, pricing, and claims must come from the brief. If you find a brand or price baked into a `prompt_` or `rules_` file, that's a bug — move it to the brief.
- **Images are hosted, not generated.** The hero comes as a hosted URL in the brief (with a reference upload so content can match the visual); the logo and social icons are hosted URLs in `rules_brand.md` / `rules_email_footer.md`. The engine never sources or generates imagery on its own.
