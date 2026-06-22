---
---
# Email Generation — Main Prompt

> **What this is:** The single entry point and orchestrator for generating a marketing
> email from this repository. The brand (Halo), its voice, product facts, reviews, and
> membership terms live in the `rules_` files; the **brief** sets what is specific to each
> send (segment, occasion, offer, hero, CTAs). This file defines how the repo is organized
> and the flow for using it. It is the **only** file with the `prompt_` prefix — everything
> else is a rule, a reference, an input, an asset, or an example that this file orchestrates.
>
> **Design principle:** This file references other files *by prefix and convention*, not
> by hard-coded list. Adding a new rule, component, or example means dropping in a
> correctly-prefixed file — **you should not need to edit this prompt to do it.**
>
> **How to run it:** This file is the orchestrator, used two ways with the **same inputs and
> the same flow below**:
>
> 1. **Chat training** — load this file plus the resources (every `rules_` file, the
>    `section_`/`component_` HTML, the `sample_` example emails, and `brief/brief_sample.md`)
>    into an AI chatbot's knowledge (e.g. a Claude Project). Describe your campaign — the brief
>    fields — in the chat, and the model returns the email.
> 2. **Pipeline** — the brief form (`brief/form.php`) sends this file to the Claude API
>    together with the same resources and a filled-in brief; the pipeline then validates the
>    result, shows a preview, and (on approval) sends it through Braze.
>
> Either way, hero images are supplied as a **hosted URL** in the brief — the engine never
> generates or sources imagery.

---

## The prefix system (how to read this repo)

Every file announces its role with a prefix: lowercase, underscores, no spaces or special
characters. To work with the repo, resolve files **by prefix**, not by memorizing names.

| Prefix | Role | How the flow uses it |
|---|---|---|
| `prompt_` | **The entry point.** This file only. | Start here. Defines the flow. |
| `rules_` | **Binding reference docs.** Design + build standards. | Obey all of them. Read every `rules_` file before building. |
| `brief_` | **Per-campaign input** filled in via the brief form. | Read the one for this run for what this email needs. |
| `template_` | **Full email skeletons** to start from. | If the brief names one, start from it. |
| `section_` | **Composed blocks** that occupy a vertical slice of the email (header, footer, tech specs, reviews, membership, etc.). | Header and footer are always included; the opt-in middle sections are what brief §7 names. |
| `component_` | **Reusable primitives** used *inside* sections (button, card, etc.). | Drop into sections where needed. Brief §7 does NOT name these directly. |
| `sample_` | **Example emails** (real, shipped). | Review for tone, structure, and proven patterns. |
| `img_` | **Shared brand images** (e.g. logo/header). | Place directly; never recreate. |
| `social_` | **Footer social icons.** | Place in the footer. |

**Rule of precedence** when two files overlap: the most specific owner wins. Any `rules_`
file that covers *visual design* (the style guide) wins on hexes, fonts, and button
shape; any `rules_` file that covers *code* (build rules) wins on HTML structure. The
brief wins on what goes in this specific send. This prompt only orchestrates.

---

## The flow

Follow these steps every time. They reference file **types**, so they stay correct as the
repo grows.

1. **Read the brief.** Open the `brief_` file for this run (in chat, this is the campaign you describe). Note the hosted hero URL, theme, target segment, copy direction, pricing/offer, and CTAs. Anything left blank is generated from context. (In the automated pipeline there is no human to ask mid-run, so generate sensibly; in chat, ask only when something essential is genuinely ambiguous — otherwise generate it.)

2. **Load every rule.** Read all `rules_` files — they are binding. The style guide governs visual values (color, typography, buttons, logos); the copy rules (the *Email Copy Rules* section of `brand-guidelines/rules_brand.md`) govern voice and punctuation (e.g. no em dashes); the build rules govern HTML structure, inline CSS, Outlook handling, image markup, and the pre-delivery checklist. **Product facts — specs, customer reviews, and membership/plan details — come verbatim from the *Technical Features*, *Social Proof*, and *Membership* sections of `brand-guidelines/rules_brand.md`; relay them exactly, never invent them or pull them from the example emails.** Never invent a value a `rules_` file already defines.

3. **Study the examples.** Review the `sample_` emails for tone, voice, and HTML structure that have actually shipped — for *feel and structure only*. Never use them as a source of product facts, specs, prices, or claims (those come from the rule files). Match their feel; don't copy their content.

4. **Pick a starting point** — three cases, in order:
   - **Brief names a template** → start from the matching `template_` file.
   - **No template, but the brief names sections (§7)** → include those sections, assembled from `section_*.html` files (with `component_*.html` primitives inside). **You decide the order and where the CTA, hero, and pricing sit** — arrange it whichever way reads best (header first, footer last).
   - **No template and no layout given** → build a **minimal email** — the essentials only: the header, the hero (when the brief gives a hero URL), the headline/subhead, the body copy (the sales pitch), any CTA and pricing the brief provides, and the footer. **You arrange the middle however reads best** — header first and footer last are the only fixed positions; the CTA can sit wherever it fits and may repeat. Use the `sample_` emails only for tone and HTML structure, and assemble from `section_*.html` files (with `component_*.html` primitives inside). **Do not add a tech-specs, reviews, or membership section on your own** — those are opt-in and appear only when the brief's §7 names them (having product facts on hand does not mean you should showcase them). Structure/tone come from the samples; all visual values from the style guide; product facts come verbatim from the rule files; campaign specifics from the brief.

5. **Build.** Place the hero image from the brief's hosted URL. Pull the logo URL from `rules_brand.md` and the social-icon URLs from `rules_email_footer.md` (the `img_`/`social_` files under `assets/` are reference copies — emails link to the hosted URLs). Apply the style guide's design values and the build rules' code conventions throughout. The only copy you write is the sales pitch; every factual claim is relayed from the rule files. **You have creative freedom over the order and arrangement of the blocks — and where the CTA goes (it may repeat)** — as long as the header stays first, the footer last, you reuse existing blocks instead of inventing structural HTML, and every fact stays verbatim from the rule files.

6. **Check & flag.** Run the checklist at the end of the build rules. Flag any client-specific rendering risks (Outlook, Gmail, etc.).

7. **Return the email.** Output only the finished email as the structured result the pipeline expects: `subject`, `preheader`, and `html`. You do **not** assemble the Braze request — the pipeline takes your output, validates it against `test/validate.py`, shows a preview, and (on approval) builds the `/messages/send` body and sends it to the fixed test segment per `rules_braze_send.md`. Sender, app, and segment are set by the pipeline, not by you.

---

## Repository layout

```
halo-resources/
├── prompt_email_generation.md          ← this file (entry point / orchestrator)
├── brand-guidelines/
│   └── rules_brand.md                  ← brand identity, audience segments, copy rules,
│                                          membership, social proof, technical features
├── email-design-system/
│   ├── rules_email_style_guide.md      ← visual design standards
│   ├── rules_email_build.md            ← HTML/code standards
│   ├── rules_email_footer.md           ← stable footer/legal boilerplate
│   ├── sections/                       ← section_*.html (header, footer, tech specs, reviews, membership)
│   ├── components/                     ← component_*.html (button)
│   └── assets/
│       ├── shared-images/              ← img_*.webp  (reference copies; emails link to hosted URLs)
│       └── social-icons/               ← social_*.webp
├── developer-skills/
│   └── rules_braze_send.md             ← Braze /messages/send schema + safety
├── email-examples/                     ← sample_*.html
└── brief/
    └── brief_sample.md                 ← the brief template (copy per campaign → brief_<name>.md)
```

> No `template_*.html` files exist yet — `template_` is a reserved convention (step 4 picks one
> up automatically the moment a `template_` file is added).

> Folder location doesn't change a file's role — its **prefix** does. If a `rules_` file
> moves, it's still a binding rule. Resolve by prefix.

---

## Accuracy rule (facts come from the rule files; the brief sets the campaign)

This engine builds emails for one brand — Halo. Its identity, voice, product facts, social
proof, and membership terms are defined in the `rules_` files and must be relayed **exactly
as written** — never invented, never lifted from the example emails:

- **Brand voice & identity** → `brand-guidelines/rules_brand.md` → *# Brand Identity*
- **Audience segment definitions** → `brand-guidelines/rules_brand.md` → *# Segment Definitions*
- **Copy / voice + punctuation** → `brand-guidelines/rules_brand.md` → *# Email Copy Rules*
- **Product specs & features** → `brand-guidelines/rules_brand.md` → *# Technical Features*
- **Customer reviews & ratings** → `brand-guidelines/rules_brand.md` → *# Social Proof*
- **Membership / plan details** → `brand-guidelines/rules_brand.md` → *# Membership*
- **Footer, legal line, unsubscribe** → `email-design-system/rules_email_footer.md`

The **brief** sets what is specific to *this send*: the target segment, the campaign
occasion, the offer/discount and promo code, the hero image URL, the CTAs, and any headline/
subhead direction (left blank, those are auto-generated).

The only copy you generate is the **sales pitch** — the persuasive framing and wording that
ties the campaign together. Every factual claim inside it (a spec, a price, a review quote, a
membership term) must trace verbatim to a rule file or the brief. If a fact you'd want isn't
in either, leave it out — do not invent it or carry one over from an example email.

When a rule file offers **more than a section can hold** (multiple membership tiers, many
specs, several reviews), **freely choose the few that best fit this campaign and segment** and
relay those verbatim — curate, don't dump the whole file. Selecting *which* items to feature is
your call; what each one *says* is fixed by the file.

---

## Extending the system (no edit to this file required)

- **New standard?** Add a `rules_` file. Step 2 already says "read all `rules_` files."
- **New section?** Add a `section_*.html` file. Step 4 already pulls from `section_*.html`.
- **New primitive (button, card, etc.) used inside sections?** Add a `component_*.html` file.
- **New skeleton?** Add a `template_` file. Step 4 already starts from `template_`.
- **New reference email?** Add a `sample_` file. Step 3 already reviews all `sample_`.

Edit this prompt only to change the *flow itself* — not to register new files.
