# Style guide bundle — installation

This bundle upgrades your Jekyll site with the design-system visual primitives
needed to render `rules_email_style_guide.md` like the Halo UI System reference.

## What's in the bundle

```
style-guide-bundle/
├── _layouts/
│   └── default.html                          (REPLACES your current default.html)
├── _includes/                                (NEW — copy all into your repo's _includes/)
│   ├── section.html
│   ├── subsection.html
│   ├── color-grid-start.html / color-grid-end.html
│   ├── color-card.html
│   ├── type-stack-grid-start.html / type-stack-grid-end.html
│   ├── type-stack.html
│   ├── type-role.html
│   ├── width-bar.html
│   ├── spacing-scale.html
│   ├── surface-grid-start.html / surface-grid-end.html
│   ├── surface-card.html
│   ├── comp-rows-start.html / comp-rows-end.html
│   ├── comp-row.html
│   └── forbidden-start.html / forbidden-end.html
└── email-design-system/
    └── rules_email_style_guide.md            (REPLACES your current style guide)
```

## Installation steps

1. **Back up your current files first.** If anything goes wrong, you'll want to roll back:
   - `_layouts/default.html`
   - `email-design-system/rules_email_style_guide.md`

2. **Copy `_layouts/default.html` into your `_layouts/` folder**, replacing the existing one.

3. **Copy all files from `_includes/` into your repo's `_includes/` folder**, alongside the existing `nav.html` (which you can keep or delete — it's no longer referenced by the layout).

4. **Replace your current `email-design-system/rules_email_style_guide.md`** with the new one from the bundle.

5. **Commit and push.** GitHub Pages rebuilds in 60-90 seconds.

6. **Hard-refresh your browser** on the style guide page (`Cmd+Shift+R` / `Ctrl+Shift+R`).

## What you'll see

The style guide page will now render with:

- Section eyebrows and large titles like the Halo UI System reference
- Color cards in a responsive grid — saturated for brand colors, with hex pills, token names, and usage notes
- Type stack cards showing the actual font stack with large preview text
- Type role specimens with real-size samples and spec sheets (size/weight/leading/tracking)
- Container width bar
- Spacing scale visualized as proportional dark squares
- Surface token cards in a compact grid
- Component review rows with live-rendered buttons and badges
- A red "Never" guardrails box at the bottom

All driven by markdown — no double-source-of-truth, no HTML to maintain by hand.

## Using the primitives in other pages

Any of these visual primitives can be used in any markdown page. Just include them:

```markdown
{% include section.html eyebrow="MY SECTION" title="My title" lead="..." %}

{% include color-grid-start.html %}
{% include color-card.html name="My color" hex="#123456" usage="..." variant="saturated" %}
{% include color-grid-end.html %}
```

Every include has documentation in its file (look at the `{%- comment -%}` block at the top).

## If something looks wrong

- **TOC missing for the visual sections?** The JavaScript scans for both regular markdown H2/H3 AND the `.halo-section__title` / `.halo-subsection__title` classes. Hard-refresh; the JS may be cached.
- **Spacing scale squares look wrong?** Below 32px, squares render at 32px minimum so 4px and 8px stay visible. The actual value is shown in the label below.
- **Component demos missing?** Check that the `{% capture %}` blocks come BEFORE the `{% include comp-row.html %}` call. Liquid evaluates in order.
- **Colors look off?** All visual primitive colors come from the CSS variables at the top of `_layouts/default.html`. Edit those if you want to recalibrate.

## Rollback

If the new design doesn't work for you, revert these three things:
- `_layouts/default.html`
- `email-design-system/rules_email_style_guide.md`
- Optionally delete the new files in `_includes/` (leaving them won't break anything — they're just unused)
