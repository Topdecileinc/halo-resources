---
---
# Halo styled docs — installation

This bundle adds a custom layout to your GitHub Pages site so every
markdown file renders with the Halo header + left nav + styled content
area, matching the index page you already have.

## What's in the bundle

| File | Where it goes in your repo | What it does |
|---|---|---|
| `_layouts/default.html` | `_layouts/default.html` (repo root) | The page template every markdown file is wrapped in. |
| `_includes/nav.html` | `_includes/nav.html` (repo root) | The left navigation. Edit this one file to change nav items everywhere. |
| `_config.yml` | `_config.yml` (repo root, **replaces** your current one) | Tells Jekyll to use the default layout for every markdown page. |

The folders `_layouts` and `_includes` have leading underscores — that's
Jekyll convention and is required for them to be picked up.

## Installation

1. Create `_layouts/` and `_includes/` folders at the root of your repo.
2. Copy `default.html` into `_layouts/`.
3. Copy `nav.html` into `_includes/`.
4. Replace your existing `_config.yml` with the one in this bundle.
5. Copy `add_frontmatter.py` to the repo root, then run it once:
   ```bash
   python3 add_frontmatter.py
   ```
   This adds a tiny `---\n---` block to every markdown file. Jekyll requires this to render the file as a styled page (otherwise it serves the raw markdown). The script is idempotent — re-running it skips files that already have it.
6. Delete `add_frontmatter.py` from the repo (no need to keep it).
7. Commit and push. GitHub Pages rebuilds in 60-90 seconds.

After the rebuild, visit any markdown file's URL on your site. For example:
- `https://topdecileinc.github.io/halo-resources/README.html`
- `https://topdecileinc.github.io/halo-resources/brand-standards/rules_brand.html`

Note that GitHub Pages serves `.md` files as `.html` URLs. The `.md` extension
in your repo, the `.html` extension on the live site.

## Adding new doc pages

Just create a new `.md` file anywhere in the repo. It automatically gets the
layout, the header, and the nav. No front matter required.

If you want to set a custom page title (instead of using the site default):

```
---
title: My Custom Page Title
---

# My Page

Content here.
```

## Adding items to the left nav

Edit `_includes/nav.html`. Copy one of the existing `<li>` blocks and adjust
the link and label. The `{% if page.path == ... %}class="current"{% endif %}`
bit highlights the current page automatically.

## Custom intro text on a page (optional flair)

If you want an "eyebrow" label above a page's H1 (matching the style of the
Halo UI System pages), add an `eyebrow` to the front matter:

```
---
title: Brand identity
eyebrow: Brand standards
---
```

The layout will render it as small uppercase text above the H1.

## What it looks like

- **Header**: same as the index page (sticky, frosted-glass, Halo logo on left, "Email Resources" tag on right).
- **Left nav**: 260px wide, sticky as you scroll, grouped by section, current page highlighted.
- **Content area**: max 760px wide for readability, Inter font, generous whitespace.
- **Tables, code blocks, blockquotes**: styled to feel like the Halo UI System docs.
- **Mobile**: nav stacks on top, content fills the width.

## Troubleshooting

**Pages still showing the old Cayman theme?**
Hard-refresh your browser (`Cmd+Shift+R` / `Ctrl+Shift+R`). GitHub Pages serves cached versions for a few minutes after a deploy.

**A page is missing from the nav?**
Add it to `_includes/nav.html`. The nav is shared by every page — there's no per-page nav configuration.

**Build failed?**
Check the Actions tab on GitHub. The most likely cause is a YAML syntax error in `_config.yml` — make sure indentation uses spaces (not tabs) and quotes around strings.
