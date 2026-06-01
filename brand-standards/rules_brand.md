---
---
# Brand Identity

> Binding reference (prefix: `rules_`): the standing brand identity for this setup. The
> brand does not change between campaigns, so it lives here rather than in the brief.
> This keeps the engine itself faceless — a different brand would supply its own
> `rules_brand.md`; this file is the one place that names the brand for this project.

---

## Brand

- **Brand name:** Halo Collar (use "Halo Collar"; "Halo" is acceptable as shorthand once context is established).
- **Brand site / default CTA destination:** https://www.halocollar.com/
- **Logo asset:** hosted at `https://braze-images.com/appboy/communication/assets/image_assets/images/6408dc0aee82fd0a9a02e8ca/original.png?1678302218` (the email links to this URL directly). Reference copy: `img_header.webp` in `design-libraries/assets/shared-images/`. Logo treatment per the style guide's Logos section.

## Voice (always)

- **Warm and inviting** — this is the standing tone for every email, not a per-campaign choice. Encouraging, plain-spoken, confident but not hype-y.
- See `email-examples/` (`sample_*.html`) for the established tone in practice.

## What still comes from the brief

The brand is fixed; the *campaign* is not. The brief still owns:

- **Product / subject** of the specific email (products can vary).
- **Pricing and offers** for the send.
- **Headline, body copy, CTA label, and destination** (defaults to the brand site above if unspecified).

> Footer legal entity, address, and unsubscribe wording are separate and live in
> `rules_email_footer.md`.
