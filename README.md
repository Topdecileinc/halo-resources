---
---
# Halo Email Resources — Developer README

This repository is Halo's **email-building engine**. A brief form (`brief/form.php`) feeds
these resources plus a filled-in brief to the Claude API, which produces a production-ready,
cross-client HTML marketing email; the pipeline then validates it, shows a preview, and (on
approval) sends it through Braze. This README explains how the system is put together and how
to work in it. It's for the team — not for the model.

> **Not to be confused with `prompt_email_generation.md`.** That file is the instruction
> *to the model* (the system prompt it reads). This README is documentation *for us* about
> how the whole thing works.

---

## Mental model

Three layers:

1. **The pipeline** (`brief/`) — the brief form and the PHP behind it. It loads the resources
   below, calls the Claude API, validates the result, shows a preview, and sends via Braze.
   This is the runner; it replaces the old "paste the resources into a chat" workflow.
2. **`prompt_email_generation.md`** — the orchestrator / system prompt. It defines the flow,
   the prefix system, and the order to use everything. It points; it doesn't restate.
3. **Everything else** — the modular resources: rules, brief, sections, components, templates,
   examples, assets. Each is prefixed by role and owned by exactly one concern.

The guiding principle: **facts live in the rule files; the brief sets the campaign.** Brand
voice, product specs, reviews, membership terms, and legal text come from the `rules_` files
and are relayed verbatim. The brief sets what's specific to one send (segment, occasion,
offer, hero, CTAs). The engine writes only the **sales pitch**; the orchestrator stitches it
all together.

---

## Setup — standing the pipeline up

The engine runs as a small PHP app in `brief/`. To stand it up on a PHP-capable host:

### 1. Deploy the repo to a PHP host

GitHub Pages is static and **will not** run the form — it only serves the docs site. Put the
repo on a PHP server (Apache/Nginx + PHP). Point the web root at `brief/`; the form reads its
sibling resource files (rules, sections, examples) from the **filesystem**, not over HTTP.
Make sure `submissions/` and `generated/` are writable by the web server.

### 2. Configure keys

```bash
cd brief
cp config.sample.php config.php   # config.php is gitignored — secrets never land in the repo
# then edit config.php
```

Fill in `config.php`:

- `anthropic_api_key` — from platform.claude.com → API keys. Used to generate the email.
- `braze_api_key` + `braze_rest_url` — a Braze key scoped to **`messages.send` only**, and
  your workspace's cluster URL (e.g. `https://rest.iad-01.braze.com`).
- The send identity is already set: `braze_app_id`, `braze_from`, and `braze_segment_id` (the
  test segment). These match the values `brief/form.php` writes into every brief.

Leave the model (`claude-opus-4-8`) and the gate toggles at their defaults unless you have a
reason to change them. With no `config.php`, the form still works but only **saves the brief**
to `submissions/` — it won't generate.

### 3. Run an email

1. Open `form.php` in a browser and fill in the brief. **Only the campaign name is required;**
   everything else is optional and generated when left blank.
2. Hit **Generate email.** The pipeline saves the brief, calls Claude with the orchestrator +
   every rule + sections/components + examples, validates the result, and shows a **preview.**
3. From the preview, **Redo** (regenerate from the same brief) or **Send.** Nothing goes out
   until you click Send.
4. **Send** posts the email to Braze `/messages/send` targeting the fixed test segment, and
   shows the HTTP result.

### How generation is gated

- **Deterministic validation** (`test/validate.py`) is the hard gate — the email is checked
  against the rule files. On failure the pipeline retries once; a second failure means it is
  **not sent**, and you're told it couldn't be generated cleanly.
- **AI review** is a second, advisory pass. It surfaces concerns on the preview but does
  **not** block the send — unless `ai_review_blocking` is turned on in `config.php`.

### Sending: scope & safety

- The pipeline does **test sends only** — always `broadcast: true` + the test `segment_id`,
  never `audience` or `campaign_id`. Production emails go through Braze **campaigns**, created
  and triggered in the Braze dashboard, not here.
- The Braze key must be scoped to `messages.send` only, and lives in `config.php` (gitignored)
  — never in a brief, the docs, or a commit.
- `"message": "success"` from Braze only means the request was accepted, not delivered. If
  nothing arrives, the segment is usually empty or has users without email addresses.

### Images

Images are **hosted** and referenced by absolute URL so they render in the inbox. The logo URL
lives in `rules_brand.md` and the four social-icon URLs in `rules_email_footer.md` (set once,
reused every send). The hero is per-campaign: the brief supplies its hosted URL and the email
links to it. The engine never generates or sources imagery. (Reference copies of the brand
assets live under `email-design-system/assets/` for design reference.)

---

## The prefix system

Every file announces its role with a prefix — lowercase, underscores, no spaces or special
characters. The pipeline resolves files **by prefix, not by exact filename**, so the system
grows by *adding correctly-prefixed files*, not by editing the orchestrator.

| Prefix | Role | Edit / add when… |
|---|---|---|
| `prompt_` | The single entry point (orchestrator). **Only one file.** | …you're changing the *flow itself*. Rarely. |
| `rules_` | Binding standards — brand, product facts, design, code, send. | …you add or change a standard or a product fact. |
| `brief_` | Per-campaign input, filled in per email. | …you start a new campaign (the form writes one). |
| `template_` | Full email skeletons to start from. | …you have a new repeatable email shape. |
| `section_` | Composed blocks that occupy a vertical slice of the email (header, footer, tech specs, reviews, membership, etc.). **The opt-in ones are what brief §7 names.** | …you formalize a recurring middle-of-email pattern. |
| `component_` | Reusable primitives used *inside* sections (button, card, etc.). | …you build a new reusable primitive (not a section). |
| `sample_` | Real, shipped example emails for tone/structure reference. | …you want to add a proven email as reference. |
| `img_` | Shared brand images (logo, header). | …you add a shared brand image. |
| `social_` | Footer social icons. | …you add/replace a social icon. |

`rules_` files live across the layer folders (`brand-brain/`, `product-brain/`,
`email-design-system/`, `braze-deployment/`); the loader finds them by prefix at any depth.

**Rule of precedence** (when two files overlap): the most specific owner wins. The style guide
wins on visual values (hex, font size, button shape). The build rules win on code structure.
The product-brain files win on product facts. The brief wins on what this specific send says.
The orchestrator only routes.

---

## Repository layout

```
halo-resources/
├── prompt_email_generation.md          ← entry point / orchestrator (the ONE prompt_)
├── brief/
│   ├── brief_sample.md                 ← copy per campaign → brief_<name>.md
│   ├── form.php                        ← brief form + pipeline entry point
│   ├── config.sample.php               ← copy to config.php (gitignored) with keys
│   └── lib/claude_pipeline.php         ← Claude REST API → validate → preview → Braze
├── brand-brain/
│   ├── rules_brand.md                  ← brand identity (name, site, voice)
│   └── rules_segment_definition.md     ← audience segment definitions
├── product-brain/
│   ├── rules_technical_features.md     ← product specs & features
│   ├── rules_social_proof.md           ← customer reviews & ratings
│   └── rules_membership.md             ← membership / plan facts
├── email-design-system/
│   ├── rules_email_style_guide.md      ← visual standards (from Figma design system)
│   ├── rules_email_copy.md             ← copy/voice + punctuation rules
│   ├── rules_email_build.md            ← HTML / code standards
│   ├── rules_email_footer.md           ← stable footer/legal boilerplate
│   ├── sections/                       ← section_*.html (header, footer, ...)
│   ├── components/                     ← component_*.html (button, ...)
│   ├── templates/                      ← template_*.html   (to be built)
│   └── assets/
│       ├── shared-images/              ← img_*.webp
│       └── social-icons/               ← social_*.webp
├── braze-deployment/
│   └── rules_braze_send.md             ← Braze /messages/send schema + safety
├── email-examples/                     ← sample_*.html
├── test/                               ← validation harness (local tooling, not loaded)
│   ├── validate.py                     ← Python 3 entry point
│   ├── config.ini                      ← paths and toggles
│   ├── validators/                     ← one module per concern
│   └── emails/                         ← campaign packages, gitignored
└── README.md                           ← this file (team docs, not loaded)
```

> **Folders are for humans.** The pipeline discovers files by prefix, recursively — folder
> depth doesn't matter. So folder placement is purely about making the repo easy for *us* to
> navigate and edit. A file's **prefix** defines its role, wherever it physically sits.

---

## How an email gets made (the flow)

This mirrors the flow inside `prompt_email_generation.md`:

1. **Brief.** Fill in the brief form (`brief/form.php`); it's saved to `submissions/` and passed to the pipeline.
2. **Rules.** The pipeline loads all `rules_` files — binding. Style guide governs look; build rules govern code; the `product-brain/` files supply product facts, relayed verbatim.
3. **Examples.** It reviews `sample_` emails for tone and HTML structure **only** — never for facts.
4. **Start point.** If the brief names a template, it starts from a `template_` file; if it names sections, it assembles those. With neither, it builds a **minimal email** — header, hero (if a hero URL is given), headline/subhead, body, CTA, footer — and nothing more. Extra blocks (tech specs, reviews, membership) are opt-in via §7.
5. **Build.** It places the hero from the brief's hosted URL, pulls the logo/icon URLs from `rules_brand.md` / `rules_email_footer.md`, and applies the rules throughout. The only copy it writes is the sales pitch.
6. **Check.** The deterministic validator is the hard gate; an advisory AI pass adds notes. You see a preview, then Redo or Send.

---

## Testing (validating builds before you send)

A zero-dependency Python validation harness lives in `test/`. It's the deterministic hard
gate the pipeline runs on every generated email, and you can also run it by hand. It catches
the things the build can get wrong before any email goes out — malformed JSON, masked-email
leaks, HTML that won't render, brief-to-output divergence, pricing math that doesn't reconcile.

**`test/` is local tooling**, not a resource the engine loads (same as this README).

### What it checks

~46 checks across five groups:

- **JSON send body** — schema, required fields, UUID formats, the chat-mask leak (`[email protected]`), forbidden fields (`audience`, `campaign_id`, etc.), `broadcast: true` for segment sends.
- **HTML hygiene** — well-formedness, em-dash detection, `<img>` attribute completeness, absolute URL enforcement, no `display:flex`/`grid`, table-based structure, MSO conditionals, web-safe font fallback.
- **Brand fidelity** — reads the `rules_` files (`brand-brain/`, `email-design-system/`, `braze-deployment/`) at runtime; verifies the footer legal line, unsubscribe link, social icon URLs, and brand logo URL actually appear in the HTML.
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

Exits 0 if no errors, 1 if any campaign failed. (The pipeline runs this same check
automatically on each generated email; running it by hand is for validating a saved package.)

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

- **New standard or product fact** → add a `rules_` file in the matching layer folder (`brand-brain/`, `product-brain/`, `email-design-system/`, or `braze-deployment/`). The flow already says "read all `rules_` files."
- **New section** (composed block like tech specs, reviews) → add a `section_*.html` file (with a `<!-- section-desc: … -->` marker so the form lists it). The flow already assembles from `section_*.html`.
- **New component** (primitive like button, card, used inside sections) → add a `component_*.html` file.
- **New email shape** → add a `template_` file. The flow already starts from `template_`.
- **New reference email** → add a `sample_` file. The flow already reviews all `sample_`.
- **New asset** → add an `img_` or `social_` file.

**Only edit `prompt_email_generation.md` to change the flow itself** — never just to
register a new file.

### Conventions for new files

- Match the prefix to the role (see the table above).
- Lowercase, underscores, no spaces or special characters.
- Keep each `rules_` file scoped to **one domain** (build, style, copy, a product-fact area…). Avoid a catch-all `rules_everything.md`.
- Put binding standards in the matching layer folder. If something is reference-but-not-binding, it isn't a `rules_` file — give it its own prefix and home.

---

## What lives where (quick reference)

| Concern | Home |
|---|---|
| Run the pipeline (form, generate, preview, send) | `brief/form.php` + `brief/lib/claude_pipeline.php` |
| Keys + send identity (gitignored) | `brief/config.php` (copied from `config.sample.php`) |
| Flow, prefix system, precedence | `prompt_email_generation.md` |
| Brand identity (name, site, voice) | `brand-brain/rules_brand.md` |
| Audience segment definitions | `brand-brain/rules_segment_definition.md` |
| Product specs & features | `product-brain/rules_technical_features.md` |
| Customer reviews & ratings | `product-brain/rules_social_proof.md` |
| Membership / plan details | `product-brain/rules_membership.md` |
| Colors, type, buttons, logos | `email-design-system/rules_email_style_guide.md` |
| HTML structure, CSS, Outlook, image markup, checklist | `email-design-system/rules_email_build.md` |
| Footer, legal line, unsubscribe text (stable across sends) | `email-design-system/rules_email_footer.md` |
| Braze `/messages/send` schema + safety constraints | `braze-deployment/rules_braze_send.md` |
| Campaign offer, CTAs, hero URL, segment (per send) | the active `brief_` file |
| Composed blocks (header, footer, tech specs, reviews, membership, ...) | `email-design-system/sections/` |
| Reusable primitives (button, card, ...) used inside sections | `email-design-system/components/` |
| Email skeletons | `email-design-system/templates/` |
| Tone / pattern reference | `email-examples/` |
| Logo, icons | `email-design-system/assets/` |
| Pre-send validation (JSON, HTML, brief reconciliation, optional W3C) | `test/validate.py` (config in `test/config.ini`) |

---

## Brief reference

Everything in `brief_sample.md`, explained — so anyone filling one in (or filling the form,
which mirrors it) knows what each field does and how it's used.

### 1. Campaign basics

- **Product / subject** — what the email is about. The campaign angle lives in the brief; the brand is fixed in `rules_brand.md` and the product facts in `product-brain/`.
- **Campaign name** — your internal label for the send. **Required** — it names the saved file.
- **Occasion / theme** — the hook (holiday, awareness month, flash sale, evergreen).
- **Send date** — when it goes out.
- **Primary goal** — the one action the email is built around (drive purchase, re-engage, announce).

### 2. Audience — segments

The email's angle is driven by **which segment it targets**. Name the segment in the brief;
its definition drives the message, tone, and offer emphasis.

> Segment definitions live in `brand-brain/rules_segment_definition.md` — that's the
> source of truth, and the pipeline reads it at build time (the form even reads the segment
> dropdown live from it). Add or refine segments there, not here. Example: **Acquisition** =
> people we're targeting to buy the product but who don't own one yet → email emphasizes core
> value, trust, and a low-pressure CTA.

### 3. Content

- **Headline** — the largest piece of text in the email body. Sits below the hero, usually one short line, sentence case. Provide it, or write `suggest` and the build drafts one to match the campaign and segment.
- **Subhead** — the smaller line directly under the headline. Adds context or specifics ("for Dad and pup", "$50 off through Sunday"). Optional; write `suggest` or leave blank.
- **Key message or offer** — the single thing the email is trying to communicate. Drives the sales pitch and what gets emphasized. If there's an offer, state it here in plain terms (e.g. "$50 off the Halo Collar 5"). The build won't invent an offer that isn't stated.
- **Body** — there is no body field. The sales-pitch copy is **AI-generated** from the subhead, key message, segment, and brand voice — but every factual claim in it is relayed from the rule files. To constrain the generated copy, leave a note in §8 (Notes for the builder).

> Tone is **not** a brief field — it's fixed (warm and inviting) and defined in
> `rules_brand.md`. Every email uses it.

### 4. Hero image

The hero is **hosted** — the brief supplies its live URL and the email links to that URL
directly. The engine never generates or sources hero imagery. Fields: hosted hero URL and alt
text (for accessibility and the Outlook image-off fallback). Use a normally-rendering format
(PNG / JPG / GIF), not `.webp`, which some clients won't display.

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
> (This is the campaign offer — distinct from standing facts like membership pricing, which
> come from `product-brain/rules_membership.md`.)

### 6. Call to action

Up to **three** CTAs, each a label + destination. **A blank row renders nothing** — so one
strong CTA is fine; fill rows 2 and 3 only if the campaign needs them. (Many competing CTAs
dilute a promo; reserve multiple links for content-roundup layouts.)

### 7. Structure & starting point

- **Start from a template?** — `newsletter`, `promo`, or `none` (build fresh). If a template is named, the build starts from that `template_` file. If none and no sections are specified, the build produces a **minimal email** — header, hero, headline/subhead, body, CTA, footer (see "How an email gets made").
- **Sections to include** — the building blocks to assemble, in order. The form reads the options live from the section vocabulary; the header and footer sections are always included automatically, so this field governs the campaign-specific middle. **Leave blank for the minimal email**; name a section like `tech specs`, `reviews`, or `membership` here to add it. Extra sections are opt-in — the build won't add a specs/review/membership block on its own.
- **Anything to exclude** — call out anything to leave off.

### 8. Notes for the builder

Free-form text the build will read alongside the rest of the brief. Useful for things
that don't fit anywhere else, including:

- Constraints on the generated copy ("don't mention training time," "keep it under 100 words").
- References to a past email to match in tone or structure ("similar to last year's Black Friday").
- One-off exceptions to standard rules ("for this send only, swap the footer for the holiday version").
- Anything you'd tell a human teammate verbally that affects how the email gets built.

Leave blank if there's nothing campaign-specific to say.

### 9. Sender (for the Braze send)

The sender travels with the send request, not the email design — and it's **set by the
pipeline, not filled in per brief.** `brief/form.php` writes the fixed `app_id` and `from`
(from `config.php`) into every brief automatically:

- **Braze `app_id`** — the App Identifier for the email app.
- **`from`** — the sender, in Braze's format: `Display Name <sender@example.com>`.

Neither is a secret. The Braze API key and REST URL are **not** here — they live in
`config.php` on the server (see "Setup — Configure keys").

### 10. Test targeting (segment)

Also **set by the pipeline, not per brief.** Test sends target a designated test segment in a
non-production Braze workspace; its UUID is the `braze_segment_id` in `config.php`, written
into every brief automatically.

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

The source of truth for what sections exist is **`email-design-system/sections/`** — every
`section_*.html` file in that folder is a recognized section name.

### Currently formalized

Each has a `section_*.html` file in `email-design-system/sections/` and an interactive
preview under `email-design-system/playground/`:

| Section | What it is | Named in §7? |
|---|---|---|
| `header` | Logo at top of the email, left-aligned. | No — **always included** (part of the frame). |
| `footer` | Social icons, legal/address line, unsubscribe link. | No — **always included** (part of the frame). |
| `tech specs` | Key product specs in a light stats panel. Facts from `rules_technical_features.md`. | **Yes** — opt-in. |
| `reviews` | Customer reviews as quote cards. Quotes from `rules_social_proof.md`. | **Yes** — opt-in. |
| `membership` | Pack Membership Plan details panel. Facts from `rules_membership.md`. | **Yes** — opt-in. |

### Not chosen in §7 (driven by other fields)

- `hero` — top image + headline + subhead. Driven by the **hero URL in §4**, not named in §7; it appears whenever a hero URL is given.
- `offer / pricing` — the deal: price, discount, promo code. Driven by **§5 Pricing**. **Bold text, not a pill** (pills are reserved for CTAs — see `rules_email_style_guide.md`).

### How the system grows

When a section pattern stabilizes across multiple campaigns, promote it to a
`section_*.html` file in `email-design-system/sections/`. The vocabulary above expands
automatically — there's no separate list to maintain. If you're describing something
that doesn't fit any pattern here, describe it in your own words and add detail in §8
(Notes for the builder).

> **Components ≠ sections.** Primitives like the button live in
> `email-design-system/components/` and are used *inside* sections. Don't name a component
> in §7 — name a section.

---

## Notes & open items

- **This is Halo's engine.** Brand-specific content is defined on purpose: identity and voice in `rules_brand.md`, product facts in the `product-brain/` files, and visual tokens (named from the source Figma design system, e.g. "Halo Yellow") in `rules_email_style_guide.md`.
- **Facts come from the rule files, relayed verbatim.** Product specs, reviews, and membership terms live in `product-brain/`; the engine must state them exactly and must **not** invent facts or pull them from the example emails. The campaign-specific offer/pricing still comes from the brief. The only copy the engine writes is the sales pitch.
- **Templates aren't built yet.** The orchestrator references `template_*.html` files by convention so they work the moment they're added.
- **Formalized sections:** header and footer (always-on frame) plus the three opt-in blocks — `tech specs`, `reviews`, `membership`. The hero and offer/pricing are driven by §4 and §5, not §7. New recurring patterns can be promoted to a `section_*.html` file when the shape stabilizes.
- **Images are hosted, not generated.** The hero comes as a hosted URL in the brief; the logo and social icons are hosted URLs in `rules_brand.md` / `rules_email_footer.md`. The engine never sources or generates imagery on its own.
</content>
</invoke>
