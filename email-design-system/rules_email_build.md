---
---
# Email Build Rules

> Reference module (prefix: `rules_`): the technical conventions for coding a marketing
> email into production HTML. The main prompt points here; it does not restate these
> rules. For visual values (color, type, buttons, logos) see the style guide
> (`rules_email_style_guide.md`) — this file is about *code*, not *design*.

---

## Output format

- **Table-based HTML with inline CSS.** Standalone — no ESP-specific merge tags or templating syntax unless the brief specifies them.
- **Width:** max 600px content width, centered. Apply full-width background colors via an outer wrapper table.
- **Structure:** nested `<table role="presentation" cellpadding="0" cellspacing="0" border="0">`. No `<div>` layouts, no flexbox, no grid.

## Subject line & preheader

Every build must produce a **subject line** and a **preheader** (preview text) as part of its
output. The pipeline returns them as the `subject` and `preheader` fields alongside the
`html`, so they can be read and copied without digging through the markup.

- Keep them clearly distinct and easy to copy. For example:
  - **Subject:** Give them both the gift they love
  - **Preheader:** Halo Collar 5, now $50 off for Father's Day
- Also include the preheader in the HTML as hidden preview text (a visually-hidden span right after `<body>`), and keep the `<title>` aligned with the subject.
- Subject and preheader follow the copy rules (`rules_email_copy.md`) — warm and inviting, no em dashes. Keep subject ~50 characters or less where possible; preheader complements the subject rather than repeating it.

## CSS

- Inline styles on every element that needs styling.
- A `<style>` block in `<head>` only for media queries and pseudo-classes (hover states), using `!important` where needed for Gmail.

## Fonts

- Inter with a web-safe fallback: `Inter, Arial, Helvetica, sans-serif`.
- Include Inter via `<link>` for clients that support it; Outlook falls back to Arial automatically.

## Outlook handling

- Include MSO conditional comments for Outlook-specific fixes (button bulletproofing, ghost tables for spacing) using `<!--[if mso]>...<![endif]-->`.

## Images — hosted URLs

Images are **hosted**; the email references them by absolute URL so they render in a
recipient's inbox. There is no local `assets/` swap step anymore.

- Every `<img>` `src` is the absolute hosted URL for that asset:
  - **Logo header** — the hosted logo URL in `rules_brand.md`.
  - **Social icons** — the four hosted URLs in `rules_email_footer.md` (Facebook, Instagram, TikTok, YouTube).
  - **Hero** — the hosted hero URL supplied in the brief (§4). The brief also attaches a reference copy of the image so content can be written to match the visual, but the email links to the hosted URL, not the upload.
- Always include `alt` text, explicit `width` and `height` attributes, and `style="display:block;"` on every image.
- Use the hosted URLs exactly as given; do not rewrite, shorten, or strip query strings (the `?` cache token is part of the URL).
- If any image's hosted URL is missing from the brief or the rules files, ask for it rather than substituting a placeholder or a local path.

## Sections and components

Build emails from two kinds of reusable HTML in `email-design-system/`:

- **Sections** (`email-design-system/sections/`) — composed blocks that occupy a vertical slice of the email. These are what brief §7 names.
- **Components** (`email-design-system/components/`) — primitives used *inside* sections (button, card, etc.). Brief §7 does NOT name these directly.

Both are partial HTML — table rows meant to drop **inside** the 600px container table, in order.

### Sections (currently formalized)

- **`section_header.html`** — the logo header. Always the first row in the container; always included.
- **`section_footer.html`** — social icons + legal/address line + unsubscribe. Always the last rows; always included.

### Components (currently formalized)

- **`component_button.html`** — bulletproof pill CTA (Outlook-safe). Use for every button; fill its color placeholders from the style guide's button variants. Keep the MSO width in sync with the visible button's padding. **CTA-only:** never use this component (or its pill styling) for offer badges, eyebrows, or other non-clickable labels — see the style guide's "Offer / discount callouts" for how those render instead.

### Placeholders and rules

- Sections and components contain `[BRACKETED]` placeholders (e.g. `[IMG_LOGO]`, `[BRAND_URL]`, `[LEGAL_COMPANY_LINE]`). Fill them from: the brief (brand, hero URL, CTA URLs), the **hosted image URLs** (logo in `rules_brand.md`, social icons in `rules_email_footer.md`), and `rules_email_footer.md` (footer/legal wording). Image `src` placeholders take the absolute hosted URL, not a local path.
- **Every `section_*.html` file must include `class="section-<name>"` on its root element** (e.g. `section_header.html` → `class="section-header"`). **Every `component_*.html` file must include `class="component-<name>"`** (e.g. `component_button.html` → `class="component-button"`). These classes mark reused blocks in the rendered HTML so the validator can verify them against the source files. Don't drop the class when filling placeholders.
- Don't restyle a section or component's structure per email. If a visual value needs to change, change it in the file or the style guide — not ad hoc in one send.
- The campaign-specific middle (hero, offer cards) is built fresh per email or from a `template_` file when no `section_*.html` exists for it yet. Formalized opt-in sections (tech specs, reviews, membership) use their `section_*.html` files. As new patterns stabilize, promote them to `section_*.html` files. **Fresh campaign HTML doesn't need a section/component class** — only files-as-sources get classes.

Standard assembly order inside the container:

```
section_header  →  [campaign body: hero, offers, content sections]  →  section_footer
```

## Dark mode

- Include `@media (prefers-color-scheme: dark)` styles only if the design has a dark variant.

## Target clients

Code must render reliably in: Gmail (web + mobile), Apple Mail, Outlook (2016+ and 365 web), iOS Mail, Yahoo.

## Always flag rendering risks

Call out any element that won't render reliably, for example:

- Text overlaid on a background image (Outlook drops background images).
- Custom fonts in Outlook (always falls back to web-safe).
- Rounded corners in older Outlook (renders square — relevant for pill buttons).
- Background gradients in Outlook (provide a solid fallback color).

---

## Pre-delivery checklist

- [ ] Subject and preheader are produced as the `subject` and `preheader` output fields and follow the copy rules.
- [ ] Logo is left-aligned in the header.

- [ ] HTML is table-based, ≤600px, inline CSS, with MSO conditionals.
- [ ] Header, footer, and all CTAs use the `section_*.html` and `component_*.html` files; all `[BRACKETED]` placeholders filled, button MSO widths synced.
- [ ] Colors, type, and buttons match the style guide (`rules_email_style_guide.md`).
- [ ] Only actual CTAs use button/pill styling; offer badges and labels use the distinct callout treatment, not a pill.
- [ ] Every `<img>` uses its absolute hosted URL (logo from `rules_brand.md`; social icons from `rules_email_footer.md`; hero from the brief), copied exactly including query strings.
- [ ] All images have alt text, width, height, and `display:block;`.
- [ ] Product name, pricing, and any claims match the brief exactly — nothing invented.
- [ ] Footer, legal line, and unsubscribe text match `rules_email_footer.md` (unless the brief notes an exception).
- [ ] Rendering risks flagged for the target clients above.
