---
---
# Email Generation — Main Prompt

> **What this is:** The single entry point and orchestrator for generating a marketing
> email from this repository. It is a **brand-neutral engine** — what the email is about
> (brand, product, pricing, voice) comes from the brief, not from this file. It defines
> how the repo is organized and the flow for using it. It is the **only** file with the
> `prompt_` prefix — everything else is a rule, a reference, an input, an asset, or an
> example that this file orchestrates.
>
> **Design principle:** This file references other files *by prefix and convention*, not
> by hard-coded list. Adding a new rule, component, or example means dropping in a
> correctly-prefixed file — **you should not need to edit this prompt to do it.**
>
> **How to run it:** Paste this file, attach a filled-in brief (a `brief_` file) with the
> hero image, and produce the email. Hero images are **uploaded** via the brief — the
> pipeline does not generate them.

---

## The prefix system (how to read this repo)

Every file announces its role with a prefix: lowercase, underscores, no spaces or special
characters. To work with the repo, resolve files **by prefix**, not by memorizing names.

| Prefix | Role | How the flow uses it |
|---|---|---|
| `prompt_` | **The entry point.** This file only. | Start here. Defines the flow. |
| `rules_` | **Binding reference docs.** Design + build standards. | Obey all of them. Read every `rules_` file before building. |
| `brief_` | **Per-campaign input** you fill in and attach. | Read the one attached to this run for what this email needs. |
| `template_` | **Full email skeletons** to start from. | If the brief names one, start from it. |
| `section_` | **Composed blocks** that occupy a vertical slice of the email (header, footer, hero, feature row, etc.). | Brief §7 names these. Always include the header and footer sections; assemble the middle from the others. |
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

1. **Read the brief.** Open the attached `brief_` file. Note the uploaded hero image, theme, audience, copy, pricing, and CTA. Ask about anything critical left blank.

2. **Load every rule.** Read all `rules_` files — they are binding. The style guide governs visual values (color, typography, buttons, logos); the copy rules govern voice and punctuation (e.g. no em dashes); the build rules govern HTML structure, inline CSS, Outlook handling, image markup, and the pre-delivery checklist. Never invent a value a `rules_` file already defines.

3. **Study the examples.** Review the `sample_` emails for tone, voice, and structural patterns that have actually shipped. Match their feel; don't copy their content.

4. **Pick a starting point** — three cases, in order:
   - **Brief names a template** → start from the matching `template_` file.
   - **No template, but the brief specifies a layout / sections** → assemble those sections from `section_*.html` files (dropping in `component_*.html` primitives where the sections call for them).
   - **No template and no layout given** → derive the structure by amalgamating the `sample_` emails: identify the *common skeleton* they share (header section → hero → headline/subhead → value or feature section → CTA → footer section) and their recurring patterns, then build a new layout that follows those proven patterns, assembled from `section_*.html` files (with `component_*.html` primitives inside them where needed). Synthesize the shared structure — do **not** Frankenstein disparate sections from different samples into something incoherent. Structure/tone come from the samples; all visual values still come from the style guide; all content still comes from the brief.

5. **Build.** Place the uploaded hero image from the brief. Pull the logo and icons from the `img_` and `social_` assets. Apply the style guide's design values and the build rules' code conventions throughout.

6. **Check & flag.** Run the checklist at the end of the build rules. Flag any client-specific rendering risks (Outlook, Gmail, etc.).

7. **Generate the test send body (if applicable).** If the brief's §9 (Sender) and §10 (Test targeting) are filled in, also produce a `send_test_body.json` alongside the HTML, following the schema in `rules_braze_send.md`. The JSON shape is `broadcast: true` + `segment_id` + the engine-generated `messages.email` block; never include `audience` or `campaign_id`. If §9 or §10 is missing or incomplete, skip this step and note it in the reply.

---

## Repository layout

```
halo-resources/
├── prompt_email_generation.md          ← this file (entry point / orchestrator)
├── brief/
│   └── brief_sample.md                 ← copy per campaign → brief_<name>.md
├── brand-brain/
│   ├── rules_brand.md                  ← brand identity (name, site, voice)
│   └── rules_segment_definition.md     ← audience segment definitions
├── product-brain/
│   ├── rules_technical_features.md     ← product specs & features
│   ├── rules_social_proof.md           ← customer reviews & ratings
│   └── rules_membership.md             ← membership / plan facts
├── email-design-system/
│   ├── rules_email_style_guide.md      ← visual design standards
│   ├── rules_email_copy.md             ← copy/voice + punctuation rules
│   ├── rules_email_build.md            ← HTML/code standards
│   ├── rules_email_footer.md           ← stable footer/legal boilerplate
│   ├── sections/                       ← section_*.html (header, footer, ...)
│   ├── components/                     ← component_*.html (button, ...)
│   ├── templates/                      ← template_*.html (when built)
│   └── assets/
│       ├── shared-images/              ← img_*.webp
│       └── social-icons/               ← social_*.webp
├── braze-deployment/
│   └── rules_braze_send.md             ← Braze /messages/send schema + safety
└── email-examples/                     ← sample_*.html
```

> Folder location doesn't change a file's role — its **prefix** does. If a `rules_` file
> moves, it's still a binding rule. Resolve by prefix.

---

## Accuracy rule (the engine is faceless — the brief defines the brand)

This prompt is a brand-neutral email-building engine. It does **not** hold product names,
prices, brand voice, or legal text — those are campaign-specific and come from the brief
(and any resource files the brief points to).

- Never invent product names, prices, offers, claims, or brand details.
- Use only the facts supplied by the attached `brief_` file and the prefixed resources.
- If a needed fact (product name, price, legal/footer text, brand) is missing from the brief, ask for it — do not assume or carry one over from another campaign.

Everything about *how an email looks and is coded* lives in the `rules_` files; everything
about *what this specific email says* lives in the brief.

---

## Extending the system (no edit to this file required)

- **New standard?** Add a `rules_` file. Step 2 already says "read all `rules_` files."
- **New section?** Add a `section_*.html` file. Step 4 already pulls from `section_*.html`.
- **New primitive (button, card, etc.) used inside sections?** Add a `component_*.html` file.
- **New skeleton?** Add a `template_` file. Step 4 already starts from `template_`.
- **New reference email?** Add a `sample_` file. Step 3 already reviews all `sample_`.

Edit this prompt only to change the *flow itself* — not to register new files.
