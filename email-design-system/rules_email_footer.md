---
---
# Email Footer & Legal

> Binding reference (prefix: `rules_`): the standing footer, legal line, and unsubscribe
> text that appear on **every** email. This is stable boilerplate — it does not change per
> campaign, so it lives here rather than in the brief. A brief may override only by an
> explicit note for a specific send.
>
> This is the one place to update company name, address, or unsubscribe wording — change
> it here and every email picks it up.

---

## Footer content

- **Legal / company line:** *Protect Animals with Satellites LLC d/b/a Halo Collar | 55 S.E. 2nd Avenue #15R | Delray Beach, FL 33444*
- **Unsubscribe line:** *No longer want to receive these emails? Unsubscribe.*

## Social icons (hosted)

The footer links these hosted icon URLs directly. Reference copies live in
`email-design-system/assets/social-icons/` (`social_*.webp`) for preview, but the email uses
the hosted URLs below:

| Icon | Hosted URL |
|---|---|
| Facebook | `https://cdn.braze.eu/appboy/communication/assets/image_assets/images/6406a8b8975d646ecc05279f/original.png?1678158008` |
| Instagram | `https://cdn.braze.eu/appboy/communication/assets/image_assets/images/6406a8f3bfd0642169496f41/original.png?1678158067` |
| TikTok | `https://cdn.braze.eu/appboy/communication/assets/image_assets/images/6406a8fffa80e43ae0d7e719/original.png?1678158079` |
| YouTube | `https://cdn.braze.eu/appboy/communication/assets/image_assets/images/6406a8ffbfd064218849698b/original.png?1678158079` |

## Placement & styling

- Footer sits at the bottom of every email, after the main content.
- Order: social icons → legal/company line → unsubscribe link.
- Use the fine-print text style from the style guide (`rules_email_style_guide.md`): small size, `#9D9D9D`.
- The unsubscribe link must be present and functional in every send (compliance requirement).
