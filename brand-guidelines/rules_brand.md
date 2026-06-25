---
---
> **What this file is — Brand Guidelines.** The single home for everything *brand-level and
> standing* — the things that stay the same across campaigns (what changes per send lives in the
> brief). Use this as a **routing guide**: when you're not sure where something belongs, find the
> row it matches and put it in that section.
>
> | Put it in… | …when it's |
> |---|---|
> | **Brand Identity** | who we are and how we always sound: name, site, logo, voice/tone |
> | **Segment Definitions** | an audience we email and what to emphasize for them |
> | **Email Copy Rules** | a rule for *how* copy is written (punctuation, casing) — not what it says |
> | **Membership** | a standing fact about the Pack Membership Plan (what it's for, price, timing) |
> | **Social Proof** | a real customer review or the aggregate star rating |
> | **Technical Features** | a product spec, number, capability, or the Trustpilot score |
>
> Everything here is binding and relayed **verbatim** — the engine never invents, alters, or pulls
> facts from the example emails.

# Brand Identity

> **What goes here:** who Halo is and how it always sounds — the brand name, the site / default
> CTA URL, the logo, and the standing voice/tone used in *every* email. Put anything that's true of
> the brand regardless of campaign here, and the rest of the engine reads it from this one place.
> (How to *execute* copy — punctuation, casing — is separate: see *Email Copy Rules*. What changes
> per send lives in the brief.)

---

## Brand

- **Brand name:** Halo Collar (use "Halo Collar"; "Halo" is acceptable as shorthand once context is established).
- **Brand site / default CTA destination:** https://www.halocollar.com/
- **Logo asset:** hosted at `https://braze-images.com/appboy/communication/assets/image_assets/images/6408dc0aee82fd0a9a02e8ca/original.png?1678302218` (the email links to this URL directly). Reference copy: `img_header.webp` in `email-design-system/assets/shared-images/`. Logo treatment per the style guide's Logos section.

## Voice (always)

- **Warm and inviting** — this is the standing tone for every email, not a per-campaign choice. Encouraging, plain-spoken, confident but not hype-y.
- See the `sample_*.html` inside each `email-examples/<name>/` folder for the established tone in practice.

## What still comes from the brief

The brand is fixed; the *campaign* is not. The brief still owns:

- **Product / subject** of the specific email (products can vary).
- **Pricing and offers** for the send.
- **Headline, body copy, CTA label, and destination** (defaults to the brand site above if unspecified).

> Footer legal entity, address, and unsubscribe wording are separate and live in
> `rules_email_footer.md`.

---
---
# Segment Definitions

> **What goes here:** each audience we email and what an email to them should emphasize (angle,
> tone, offer focus). Define, rename, or refine an audience here — this is the **source of truth**
> the brief and the form's segment dropdown both read. Keep each one tight: *who they are* and
> *what to emphasize*. (This is who we're talking to; the voice we always use is in *Brand
> Identity*; the specifics of one send come from the brief.)

---

## How segments are used

1. A brief's Audience section names one segment (e.g. "Acquisition").
2. The build reads that segment's definition below.
3. The definition shapes the message angle, tone, and what the CTA/offer pushes — while the
   visual design still comes from the style guide and the specific copy/offer still comes
   from the brief.

If a brief names a segment that isn't defined here, ask for a definition (or which existing
segment it maps to) rather than guessing.

---

## Segments

### Acquisition
- **Who they are:** People we're targeting to buy the product but who don't currently own one.
- **Emphasis:** Introduce the product and its core value; build trust and credibility; address first-time hesitation; keep the CTA inviting rather than high-pressure. Social proof and the core "why" matter more than deep feature detail.

### Warm leads
- **Who they are:** Engaged recently (browsed, clicked, signed up) but haven't purchased.
- **Emphasis:** Reinforce benefits, answer the likely objection, and nudge toward purchase. A clear, confident CTA; an offer or urgency can help.

### New customers
- **Who they are:** Recently purchased; getting started.
- **Emphasis:** Onboarding and setup, how to get value quickly, support resources. Reassure they made the right choice.

### Active / existing customers
- **Who they are:** Current, engaged owners.
- **Emphasis:** Deepen engagement — accessories, upgrades, advanced use, referrals. Speak to people who already know the product.

### Lapsed
- **Who they are:** Bought or engaged once, now gone quiet.
- **Emphasis:** Win-back. Remind them of the value, reintroduce what's new, and consider a re-engagement offer.

### Gold / premium members
- **Who they are:** Top-tier or premium-plan members.
- **Emphasis:** Recognition, exclusivity, and premium perks. Tone acknowledges their status.

> The segments above are starting definitions — edit them to match how the team actually
> segments. One segment per heading; keep "who" and "emphasis" the two anchors.

# Email Copy Rules

> **What goes here:** the rules for *how* copy is written — punctuation, casing, sentence
> mechanics — applied to all generated text (headlines, body, CTAs, alt text). Put a "how to
> phrase it" rule here. The brand *voice itself* (warm, plain-spoken) is defined once in *Brand
> Identity* — this section is only the mechanical do/don'ts that execute it. (The style guide
> governs how text *looks*; this governs how it *reads*.)

---

## Punctuation

- **No em dashes (—).** Do not use em dashes in any email copy. Rewrite with a comma, a colon, parentheses, or two sentences instead.
  - Instead of: *Halo Collar 5 — for Dad and pup*
  - Use: *Halo Collar 5 for Dad and pup* / *Halo Collar 5: for Dad and pup*
- En dashes (–) are fine for numeric ranges only (e.g. "9–5"). Hyphens for compound words as normal.

## Mechanics

- Match the brand voice in *Brand Identity* above and the segment emphasis in *Segment Definitions* above.
- Sentence case for headlines (per the style guide); no ALL-CAPS shouting.
- Keep copy tight: lead with the benefit, keep sentences short, avoid hype words.
- Use the exact product name, prices, and claims from the brief — never invent or alter them.

---

> Add new copy conventions here as they come up. One rule per point; keep each tied to a
> concrete do/don't so it's unambiguous at build time.





# Membership

> **What goes here:** standing facts about Halo's Pack Membership Plan that an email may state —
> what it's required for, its pricing, and when it's chosen. Put membership/plan facts here and
> relay them verbatim; don't invent plan names, tiers, prices, or benefits. (A *campaign's* offer
> or discount is not a membership fact — that comes from the brief.)
>
> **Source:** the "Plans" panel of the Halo Collar 5 product page (image provided 2026-06-09).

---

## Pack Membership Plan

*Source: product-page "Plans" panel (verbatim).*

- **Required.** "A Pack Membership Plan is required to activate and maintain GPS services, cellular data (just like your cell phone), and create, edit, and use wireless dog fences."
- **Pricing:** "Plans start at $9.99/mo."
- **When chosen:** "Select your Pack Membership Plan after purchase."



# Social Proof

> **What goes here:** real customer reviews and the aggregate star rating that an email may quote
> as social proof. Put a customer quote, name, rating, or the overall rating here — verbatim, or
> trimmed faithfully without changing meaning. Don't invent reviews, names, ratings, or counts, or
> attribute a quote to the wrong person. (The product's Trustpilot score is a *spec*, not a review —
> it lives in *Technical Features*; keep the two ratings separate.)
>
> **Source:** scraped from https://www.halocollar.com/reviews/ on 2026-06-09, from the
> page's embedded review structured data (schema.org `Review` / `AggregateRating`). Every
> review below is the exact `reviewBody`, author first name, and star rating as published in
> that data.

---

## Aggregate rating

*Source: `AggregateRating` in the page's structured data.*

- **4.3 out of 5** (scale 1–5).
- The structured data did **not** include a total review count, so do not state one from this source. (The Halo Collar 5 product card separately shows a Trustpilot score of 4.5 / 1,512 reviews — see the *Technical Features* section. The two figures come from different places; keep them straight and don't blend them.)

---

## Customer reviews (verbatim)

*Source: the seven `Review` items in the page's structured data. Only first names are given by the source.*

### 1. Andrea — 5 / 5
> "I can put the collars on and know that they’re safe, and that we don’t have to stress out about anything."

### 2. Deana — 5 / 5
> "He has constant protection, and I always know where he’s at. It’s worked out phenomenal for us."

### 3. Scott — 5 / 5
> "Halo was a start to the commitment to give my dogs the absolute best lives they could possibly live, every day."

### 4. Jackie — 5 / 5
> "I said, ‘There has to be a better way, there has to be something that won’t shock him coming back [towards the house].’"

### 5. Doug — 5 / 5
> "In the beginning I thought, ‘Well, this won’t work.’ Well, it does. It works. It does exactly what they claim it can do."

### 6. Bethany — 5 / 5
> "I felt so much better that she could be outside where she wanted to be."

### 7. Tammy — 5 / 5
> "Romeo would get out of our fenced yard 3–5 times a week. I decided I really needed to do something drastic, and that was the Halo Collar."




# Technical Features

> **What goes here:** the product's technical specifications and capabilities — every spec,
> number, model difference, and the Trustpilot score. Put a hard product fact here, verbatim and
> with its source; don't invent, round, or embellish any number or claim. (Membership pricing is
> not a spec — see *Membership*; customer quotes are not specs — see *Social Proof*.)
>
> **Sources (provided product images, 2026-06-09):**
> 1. "Compare Halo Collar Models" comparison table (Halo Collar 5 vs Halo Collar 4)
> 2. "The most advanced tech available in a GPS dog collar" feature list
> 3. "Halo Collar 5 GPS Dog Fence" product-page card
>
> Every fact below cites which of these it came from.

---

## Model comparison: Halo Collar 5 vs Halo Collar 4

*Source: image 1 (comparison table).*

| Feature | Halo Collar 5 | Halo Collar 4 |
|---|---|---|
| Design & materials | Solid plastic enclosure; protective fabric case | Solid plastic enclosure; protective fabric case |
| Colors | 6 colors | 5 colors |
| Sizing | One size, 8"–30" neck | One size, 8"–30" neck |
| Battery life | Up to 48 hours | Up to 40 hours |
| Full-charge time | 1 hour | 2 hours |
| Magnetic charging | Yes | Yes |
| Sound, vibration and static feedback | Yes | Yes |
| Waterproof rating | IP67 | IP67 |
| Dedicated Wi-Fi and Bluetooth chips | Yes | No |

> Note on the last row: the table shows a yellow (active) checkmark for Halo Collar 5 and a
> greyed-out checkmark for Halo Collar 4. I read the greyed checkmark as "not on the Halo
> Collar 4." Worth confirming on the site before leaning on it.

---

## Halo Collar 5: product-page details

*Source: image 3 (product card).*

- **Product name:** Halo Collar 5 GPS Dog Fence
- **Positioning line (verbatim):** "Precision+ GPS accuracy, rapid charging, and 20 location updates per second — built to keep your dog safe."
- **Location updates:** 20 per second
- **Headline feature icons:**
  - Dual-frequency GPS
  - AlwaysOn™ tracking
  - Up to 48-hr battery
  - Fits most dogs
  - iOS & Android app
  - 1-year warranty
- **Delivery:** 8–12 business days
- **Reviews:** 4.5 out of 5 on Trustpilot, 1,512 reviews. *(Shown on the card; review counts change over time, so treat as point-in-time, not a fixed claim.)*

### Colors (Halo Collar 5)
*Source: image 3 (product card).*

- **Standard:** 4 swatches shown (yellow, navy, pink, grey). The card does not label these names.
- **Trail Series:** 3 swatches, including **Ranger** (the selected swatch, badged **NEW**), plus an orange and a camo/tan option (names not shown on the card).
- **Discrepancy to resolve:** image 1 lists 6 colors for the Halo Collar 5; image 3's card shows 7 (4 standard + 3 Trail Series). Both are reported as-is; do not state a single color count until the live list is confirmed.

---

## Advanced technology (Halo Collar 5)

*Source: image 2 (advanced-tech feature list).*

- Rugged collar construction and IP67 waterproof rating; "perfect for any weather"
- Connects to **6 GNSS satellite constellations** with **over 150 satellites**
- Up to **40+ hour** battery life and magnetic charging
- "Perfect fit system" ensures a comfortable fit for your dog regardless of breed

> Battery wording across sources: image 1 and image 3 say "up to 48 hours"; image 2 says
> "40+ hour." Use "up to 48 hours" for the Halo Collar 5 (the two product-specific sources
> agree); "40+" appears to be conservative/general phrasing.
