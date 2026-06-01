---
---
# Halo Email Style Guide

> Source: Halo Email Design System (Figma), "Style Guide" frame. Version dated **April 2026**.
> This document captures the visual standards for Halo email design: color, typography,
> text/background combinations, buttons, and logo usage. Values are taken directly from the
> Figma design tokens.

---

## Colors

### Core palette

| Token | Name | Hex |
|---|---|---|
| `--halo-yellow` | Halo Yellow | `#FCD62D` |
| `--halo-blue` | Halo Blue | `#2F93F3` |
| `--gray-bg` | Gray 01 (background) | `#F2F4F4` |
| `--dark-gray-bg` | Gray 02 (dark background) | `#434343` |
| `--text` | Text (primary) | `#333333` |
| `--text-fine-print` | Text, fine print | `#9D9D9D` |
| — | Gray 900 (near-black headings) | `#1B1B1B` |

### Use cases

- **Text priority:** `#333333` for primary body and headline text.
- **Text fine print:** `#9D9D9D` for legal/disclaimer/fine print.
- **Background priority:** White is the default background.
- **Secondary background:** `#F5F5F5` for alternating/secondary panels.

> Note: the design uses two near-identical light grays — `#F2F4F4` (Gray 01 token) for component
> backgrounds and `#F5F5F5` (named "Secondary Background" in use cases). Treat them as
> interchangeable light-panel grays unless a spec says otherwise.

---

## Border radius

- **Default corner radius is 24px** for containers, content cards, panels, hero/image blocks, and other boxed elements. Use it consistently so rounded corners feel uniform across the email.
- **Exception — buttons stay fully-rounded pills.** CTA buttons keep their pill shape (border-radius ~100px / `999px`), not 24px. The 24px rule is for boxes; the pill rule (see Buttons) governs CTAs.

> Outlook note: older Outlook renders rounded corners as square. Treat the 24px radius as
> a progressive enhancement, and don't rely on it to convey meaning (see the build rules'
> rendering-risk flags).

---

## Text & background combinations

Approved foreground/background pairings, with guardrails:

| Background | Eyebrow (small bold) | Headline | Body copy |
|---|---|---|---|
| **Yellow** `#FCD62D` | White | Dark gray `#333` | Dark gray `#333` |
| **Gray 01** `#F2F4F4` | Halo Blue `#2F93F3` | Dark gray `#333` *(or Halo Blue for emphasis)* | Dark gray `#333` |
| **Blue** `#2F93F3` | Dark gray `#333` | White | White — short runs only |
| **Dark Gray** `#434343` | Halo Yellow `#FCD62D` | White | White — short runs only |

**Guardrail:** Don't use white for longer areas of small text, and don't pair white text with blue buttons. Reserve white text for short runs on blue or dark-gray backgrounds.

---

## Typography

**Typeface:** Inter (open-source, by Rasmus Andersson).
Download: https://fonts.google.com/specimen/Inter · More info: https://rsms.me/inter/
**Email fallback stack:** `Inter, Arial, Helvetica, sans-serif` (Outlook will fall back to Arial).

### Desktop scale

| Level | Weight | Size | Line height |
|---|---|---|---|
| H1 | Bold (700) | 34px | 100% |
| H2 | Bold (700) | 30px | 100% |
| H3 | Bold (700) | 24px | 100% |
| P1 (body) | Light (300) | 18px | 28px |
| P2 (fine print) | Regular (400) | 12px | 24px |

### Mobile scale

| Level | Weight | Size | Line height |
|---|---|---|---|
| H1 | Bold (700) | 20px | 1.2 |
| H2 | Bold (700) | 18px | 1.2 |
| H3 | Bold (700) | 16px | 1.2 |
| P1 (body) | Regular (400) | 14px | 1.4 |
| P2 (fine print) | Regular (400) | 10px | 1.4 |

### Typography rules

- **Capitalization:** Headlines in **sentence case** — no all-caps headlines. Small "eyebrows" above a headline may be all caps when needed. Initial Caps are acceptable for short headers and labeled areas.
- **Alignment:** Left-align text when possible; centered is acceptable for short runs.
- **Emphasis:** Use **bold** or a **color** for emphasis instead of italics.

---

## Buttons

Pill-shaped buttons, fully rounded (border-radius ~100px), Inter Semi Bold ~18px, centered label, roughly 16px vertical / 55px horizontal padding (~51px tall).

| Variant | Fill | Label color | Use on |
|---|---|---|---|
| Yellow | `#FCD62D` | Dark gray `#333` | Light, blue, dark backgrounds (primary CTA) |
| Blue | `#2F93F3` | White | Light / yellow backgrounds |
| White | White | Dark gray `#333` | Yellow, blue, dark backgrounds |

**Guardrail:** Avoid white text on blue buttons (per the text-combination rules).

**Buttons are for CTAs only.** Pill / button styling (the rounded fill of any variant
above) is reserved exclusively for actual clickable calls to action. No other element may
reuse it. In particular, offer/discount badges, eyebrows, and labels must **not** be
rendered as a filled pill — sitting a pill-shaped offer badge next to a pill-shaped CTA
makes the badge look like a second button and dilutes the real one.

### Offer / discount callouts (not a button)

Present sale messaging the way the `sample_` emails do — through **type weight and color,
not a filled pill**:

- State the discount as **bold colored text** (Halo Yellow or the body dark gray for emphasis), not inside a rounded chip.
- Show price as inline text: struck-through original next to the bold sale price (e.g. ~~$529~~ **$479**).
- It must be visually distinct enough from the CTA that there is no confusion about what is clickable. Different shape (no pill), and ideally separated by spacing from the button.

---

## Logos

Use the horizontal Halo logo (paw + wordmark). **Always left-aligned** in the email header
(not centered). Color treatment depends on background:

| Background | Paw mark | Wordmark |
|---|---|---|
| Light | Halo Yellow | Gray 02 (`#434343`) |
| Dark | Halo Yellow | White |
| Halo Yellow | White | Gray 02 (`#434343`) |

---

## Quick reference for email coding

When translating this guide into HTML email:

- Body text: Inter/Arial fallback, `#333`, 18px desktop / 14px mobile.
- Fine print: `#9D9D9D`, 12px desktop / 10px mobile.
- Primary CTA: yellow pill `#FCD62D`, dark `#333` label.
- Section panels: white default, `#F2F4F4`/`#F5F5F5` for secondary.
- Headlines: Inter Bold, sentence case, left-aligned where possible.
