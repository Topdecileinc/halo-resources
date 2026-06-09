---
---
# Email Brief — [Campaign Name]

> This is the shape of a brief; the brief form (`brief/form.php`) mirrors it and fills it in
> for you. The pipeline reads the brief plus the linked resources to build the email. Anything
> left blank, the generator fills with a sensible default — the pipeline is automated, so
> there is no human to ask mid-run.

---

## 1. Campaign basics

> Confused about what to put here? See **README → Brief reference → §1**.

| Field | Your input |
|---|---|
| Product / subject | (what the email is about) |
| Campaign name | |
| Occasion / theme | (e.g. holiday, awareness month, flash sale, evergreen) |
| Send date | |
| Primary goal | (e.g. drive purchase, re-engage, announce feature) |

> Brand is fixed for this setup and lives in `rules_brand.md` — don't restate it here.

## 2. Audience

> Confused about what to put here? See **README → Brief reference → §2**.

| Field | Your input |
|---|---|
| Target segment | (which segment this email is for — see `rules_segment_definition.md`) |

> Segments are defined in `brand-brain/rules_segment_definition.md`. The chosen segment
> shapes what the email is about, its tone, and its offer emphasis. Name the segment here;
> the definition drives the rest.

## 3. Content

> Confused about what to put here? See **README → Brief reference → §3**.

| Field | Your input |
|---|---|
| Headline | (provide, or write "suggest") |
| Subhead | (provide, or write "suggest") |
| Key message or offer | |

> Body copy is **AI-generated** from the subhead, key message, segment, and brand voice —
> there's no body field to fill. Tone is fixed (warm and inviting) and lives in `rules_brand.md`.
> If you need to constrain the generated body, note it in §8 (Notes for the builder).

## 4. Hero image

> Confused about what to put here? See **README → Brief reference → §4**.

The hero image is **hosted** — provide its URL below and the email links to it directly. The
engine does not generate or source hero imagery. Use a normally-rendering format (PNG / JPG /
GIF), not `.webp`, which some clients won't display.

| Field | Your input |
|---|---|
| Hosted hero URL | (the live image URL the email will link to) |
| Alt text | (describe the image for accessibility + Outlook fallback) |

## 5. Pricing

> Confused about what to put here? See **README → Brief reference → §5**.

> Fill in only what applies. The goal is to make the **relationship** between numbers
> unambiguous — state what the final price is and how any discount produces it.

| Field | Your input |
|---|---|
| Show pricing? | (yes / no) |
| Original price | (the pre-discount/list price, if shown) |
| Sale / final price | (the price the customer actually pays) |
| Discount | (e.g. "$50 off" or "20% off" — must reconcile with the prices above) |
| Promo code | (if any) |

> Example of an unambiguous fill: Original $529, Sale/final $479, Discount "$50 off."
> The email should present these consistently (e.g. ~~$529~~ $479, $50 off) and never
> imply a discount stacks on top of an already-discounted price unless that's stated.

## 6. Call to action

> Confused about what to put here? See **README → Brief reference → §6**.

Up to three CTAs. Each needs a label and a destination. **Leave a row blank to omit that
CTA** — a blank CTA renders nothing.

| # | CTA label | Destination |
|---|---|---|
| 1 | (e.g. "Shop now") | (brand site / marketplace / other URL) |
| 2 | (optional) | |
| 3 | (optional) | |

## 7. Structure & starting point

> Confused about what to put here? See **README → Brief reference → §7**.

| Field | Your input |
|---|---|
| Start from a template? | (newsletter / promo / none — build fresh) |
| Sections to include | (optional — blank = minimal email; name e.g. `feature row` to add one. See the section vocabulary in the README) |
| Anything to exclude | |

## 8. Notes for the builder

> Confused about what to put here? See **README → Brief reference → §8**.

| Field | Your input |
|---|---|
| Notes | (anything specific to this send — leave blank if none) |

> Footer, legal line, and unsubscribe text are **not** set here — they're the same on
> every send and live in `email-design-system/rules_email_footer.md`. Only note an exception
> in "Notes for the builder" if this specific email needs different footer/legal text.

## 9. Sender (for the Braze send)

> Confused about what to put here? See **README → Brief reference → §9**.

**Set by the pipeline — you don't fill this in.** `brief/form.php` writes the fixed `app_id`
and `from` (from `config.php`) into every brief automatically.

| Field | Value |
|---|---|
| Braze `app_id` | (set from config.php) |
| `from` | (set from config.php, e.g. `Display Name <sender@example.com>`) |

> These are **not secrets**. The Braze API key and REST URL are **not** here — they live in
> `brief/config.php` on the server (see the README's "Setup — Configure keys").

## 10. Test targeting (segment)

> Confused about what to put here? See **README → Brief reference → §10**.

**Set by the pipeline — you don't fill this in.** Test sends target a designated test segment
in a non-production Braze workspace; its UUID is `braze_segment_id` in `config.php`, written
into every brief automatically.

| Field | Value |
|---|---|
| Segment ID (UUID) | (set from config.php) |
