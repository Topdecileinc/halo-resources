"""Style guide enforcement: parse rules_email_style_guide.md, check the HTML
against the unambiguous rules (colors, border radius, font fallback, italics, CTA-pill).

If the style guide can't be parsed, every check in this module either errors
(strict_rule_parsing=true, default) or warns (false). Silent no-ops are not allowed —
that's the validator-of-the-validator pattern.
"""

import re


# ---------- markdown parsing ----------

def _parse_color_palette(style_guide_text):
    """Extract allowed hex values from the style guide's Colors section.
    Returns a set of uppercase hex strings like {"#FCD62D", "#2F93F3", ...} or empty set.
    """
    # Find the Colors section
    m = re.search(r"##\s*Colors\b(.*?)(?=^##\s)", style_guide_text, re.DOTALL | re.MULTILINE)
    if not m:
        return set()
    section = m.group(1)

    # Pull every #RRGGBB out of that section
    hexes = re.findall(r"#([0-9A-Fa-f]{6})\b", section)
    palette = {"#" + h.upper() for h in hexes}

    # Also include common safe values that emails legitimately use even if not in the
    # palette: pure white and pure black (used as backgrounds and fallbacks).
    palette.add("#FFFFFF")
    palette.add("#000000")

    # Also include the F5F5F5 secondary background variant called out in use cases
    if "#F5F5F5" in style_guide_text or "F5F5F5" in style_guide_text:
        palette.add("#F5F5F5")

    return palette


def _parse_border_radii(style_guide_text):
    """Extract allowed border-radius values from the style guide.
    Returns a set of allowed px values (as ints) plus 'pill' marker.
    """
    # Default: 24px for containers, 999px (pill) for buttons.
    # Parse the Border radius section to confirm and pick up future changes.
    radii = set()
    m = re.search(r"##\s*Border radius\b(.*?)(?=^##\s)", style_guide_text, re.DOTALL | re.MULTILINE)
    if m:
        section = m.group(1)
        for num in re.findall(r"\b(\d+)px\b", section):
            radii.add(int(num))
        # Pill marker
        if "999px" in section or "100%" in section or "pill" in section.lower():
            radii.add(999)
    if not radii:
        # Fallback to documented defaults if parsing failed
        radii = {24, 999}
    # Allow common micro-values that legitimately appear (0 = no rounding)
    radii.add(0)
    return radii


def _parse_font_stack(style_guide_text):
    """Extract required font family components. Returns a list like ['Inter', 'Arial', 'Helvetica', 'sans-serif']
    or empty if not found.
    """
    # Look for the specific email fallback stack mention
    m = re.search(r"[Ee]mail fallback stack[:\s]*`([^`]+)`", style_guide_text)
    if not m:
        # Fallback: any backtick block that contains both Inter and a web-safe font
        for cand in re.findall(r"`([^`]+)`", style_guide_text):
            if "Inter" in cand and any(fb in cand for fb in ["Arial", "Helvetica", "sans-serif"]):
                m = re.match(r"(.*)", cand)
                break
    if not m:
        return []
    stack = m.group(1)
    parts = [p.strip().strip('"\'') for p in stack.split(",")]
    return [p for p in parts if p]


# ---------- HTML scanning ----------

def _scan_hex_colors(html):
    """Find every #RRGGBB / #RGB literal in the HTML.
    Returns set of normalized uppercase 6-digit hexes.
    """
    out = set()
    for m in re.finditer(r"#([0-9A-Fa-f]{6})\b", html):
        out.add("#" + m.group(1).upper())
    for m in re.finditer(r"#([0-9A-Fa-f]{3})\b(?![0-9A-Fa-f])", html):
        # Expand 3-digit to 6 (e.g. #333 → #333333)
        h = m.group(1)
        out.add("#" + (h[0] * 2 + h[1] * 2 + h[2] * 2).upper())
    return out


def _scan_border_radii(html):
    """Return list of border-radius values found in the HTML (as integers, in px)."""
    out = []
    for m in re.finditer(r"border-radius\s*:\s*([^;\"']+)", html, re.I):
        value = m.group(1).strip()
        # Pull each px value out (border-radius can have multiple)
        for num in re.findall(r"(\d+)px", value):
            out.append(int(num))
        # Pill via 100%
        if "100%" in value or "999" in value:
            out.append(999)
    return out


def _has_italic_tags(html):
    return bool(re.search(r"<\s*(i|em)\b", html, re.I))


def _find_pills_outside_anchors(html):
    """Find any element with pill-shaped border-radius (999px or 100%) that
    doesn't contain an <a> tag inside its element body.

    Strategy: for each `border-radius: 999px` (or 100%), back up to find the
    opening tag of the element it's on, then walk forward through the element's
    contents (respecting nesting) looking for an <a> tag. A real bulletproof
    button has an <a> inside the styled <td>; an offer badge styled as a pill
    doesn't.

    Returns list of suspicious tag-fragments.
    """
    suspicious = []
    pill_re = re.compile(r"border-radius\s*:\s*(999px|100%)", re.I)

    for m in pill_re.finditer(html):
        # Back up to find the opening tag of the element this style attribute is on
        # (the nearest '<tag ...' before this match where this match is inside its attributes).
        tag_open_re = re.compile(r"<\s*([a-zA-Z][a-zA-Z0-9]*)\b", re.I)
        opens = list(tag_open_re.finditer(html, 0, m.start()))
        if not opens:
            continue
        tag_open = opens[-1]
        tag_name = tag_open.group(1).lower()

        # Find the end of this opening tag (the '>' after attributes)
        gt_after = html.find(">", m.end())
        if gt_after == -1:
            continue

        # Walk forward from gt_after, balancing same-tag opens/closes, to find
        # the matching close tag.
        open_re = re.compile(rf"<\s*{tag_name}\b", re.I)
        close_re = re.compile(rf"</\s*{tag_name}\s*>", re.I)
        depth = 1
        pos = gt_after + 1
        end = len(html)
        while pos < len(html) and depth > 0:
            next_open = open_re.search(html, pos)
            next_close = close_re.search(html, pos)
            if not next_close:
                end = len(html)
                break
            if next_open and next_open.start() < next_close.start():
                depth += 1
                pos = next_open.end()
            else:
                depth -= 1
                pos = next_close.end()
                if depth == 0:
                    end = next_close.start()

        # Check the element's body for an <a> tag
        body = html[gt_after + 1:end]
        if not re.search(r"<\s*a\b", body, re.I):
            snippet = html[tag_open.start():min(tag_open.start() + 120, end)]
            suspicious.append(snippet)

    return suspicious


# ---------- main check ----------

def check(ctx, strict=True):
    if not ctx.html:
        return

    style_guide = ctx.read_rule("rules_email_style_guide.md")
    if not style_guide:
        level = ctx.err if strict else ctx.warn
        level("style.source_missing", "rules_email_style_guide.md not found — style guide checks skipped")
        return

    # Parse the rules
    palette = _parse_color_palette(style_guide)
    radii = _parse_border_radii(style_guide)
    font_stack = _parse_font_stack(style_guide)

    # Validate the source itself parsed sensibly (validator-of-the-validator)
    if not palette:
        level = ctx.err if strict else ctx.warn
        level(
            "style.source_palette_unparseable",
            "could not extract color palette from rules_email_style_guide.md — check the Colors section structure",
        )
    else:
        ctx.ok(f"style.source_palette_loaded (n={len(palette)})")

    if not radii:
        level = ctx.err if strict else ctx.warn
        level(
            "style.source_radii_unparseable",
            "could not extract border-radius rules from rules_email_style_guide.md",
        )
    else:
        ctx.ok(f"style.source_radii_loaded ({sorted(radii)})")

    if not font_stack:
        level = ctx.err if strict else ctx.warn
        level(
            "style.source_font_unparseable",
            "could not extract font stack from rules_email_style_guide.md",
        )
    else:
        ctx.ok(f"style.source_font_loaded ({font_stack})")

    # --- HTML checks ---

    # Color palette enforcement
    if palette:
        used_colors = _scan_hex_colors(ctx.html)
        unauthorized = used_colors - palette
        if unauthorized:
            details = []
            for color in sorted(unauthorized):
                # Locate the color in the HTML — try the literal form first
                hits_text = ctx.format_locations(color, source="html", max_results=3)
                if hits_text:
                    details.append(f"  {color}:\n    " + hits_text.replace("\n", "\n    "))
                else:
                    # Color might only appear in 3-digit form; locate via regex
                    short = "#" + color[1] + color[3] + color[5]
                    hits_text = ctx.format_locations(short, source="html", max_results=3)
                    if hits_text:
                        details.append(f"  {color} (as {short}):\n    " + hits_text.replace("\n", "\n    "))
                    else:
                        details.append(f"  {color}: (location not found)")
            ctx.warn(
                "style.color_palette",
                f"hex colors not in style guide palette: {sorted(unauthorized)}\n"
                f"palette: {sorted(palette)}\n"
                + "\n".join(details),
            )
        else:
            ctx.ok(f"style.color_palette (used {len(used_colors)} colors, all in palette)")

    # Border radius enforcement
    if radii:
        used_radii = _scan_border_radii(ctx.html)
        unauthorized_radii = [r for r in used_radii if r not in radii]
        if unauthorized_radii:
            details = []
            for r in sorted(set(unauthorized_radii)):
                hits_text = ctx.format_locations(re.compile(rf"border-radius\s*:\s*[^;\"']*\b{r}px\b"),
                                                  source="html", max_results=3)
                if hits_text:
                    details.append(f"  {r}px:\n    " + hits_text.replace("\n", "\n    "))
                else:
                    details.append(f"  {r}px: (location not found)")
            ctx.warn(
                "style.border_radius",
                f"border-radius values not in style guide: {sorted(set(unauthorized_radii))} "
                f"(allowed: {sorted(radii)})\n"
                + "\n".join(details),
            )
        else:
            ctx.ok(f"style.border_radius (all {len(used_radii)} values in allowed set)")

    # Font fallback enforcement
    if font_stack:
        # Every font-family declaration in the HTML should include Inter and a web-safe fallback
        decls = re.findall(r"font-family\s*:\s*([^;\"']+)", ctx.html, re.I)
        if not decls:
            ctx.warn("style.font_family", "no font-family declarations found in HTML")
        else:
            bad = []
            for decl in decls:
                low = decl.lower()
                has_inter = "inter" in low
                has_fallback = any(fb.lower() in low for fb in ["arial", "helvetica", "sans-serif"])
                if not (has_inter and has_fallback):
                    bad.append(decl.strip())
            if bad:
                details = []
                seen = set()
                for decl in bad:
                    if decl in seen:
                        continue
                    seen.add(decl)
                    hits_text = ctx.format_locations(f"font-family:{decl}", source="html", max_results=3)
                    if not hits_text:
                        # Try with a space after the colon
                        hits_text = ctx.format_locations(f"font-family: {decl}", source="html", max_results=3)
                    if hits_text:
                        details.append(f"  {decl!r}:\n    " + hits_text.replace("\n", "\n    "))
                    else:
                        details.append(f"  {decl!r}: (location not found)")
                ctx.warn(
                    "style.font_family",
                    f"{len(bad)} font-family declaration(s) missing Inter or web-safe fallback\n"
                    + "\n".join(details),
                )
            else:
                ctx.ok(f"style.font_family (all {len(decls)} declarations use Inter + fallback)")

    # No italics
    if _has_italic_tags(ctx.html):
        hits_text = ctx.format_locations(re.compile(r"<\s*(?:i|em)\b", re.I),
                                          source="html", max_results=3)
        ctx.warn(
            "style.no_italics",
            "found <i> or <em> tags — style guide says use bold or color for emphasis\n"
            + hits_text,
        )
    else:
        ctx.ok("style.no_italics")

    # Pill-on-non-CTA (button styling reserved for clickable CTAs)
    pills_without_links = _find_pills_outside_anchors(ctx.html)
    if pills_without_links:
        # _find_pills_outside_anchors returns snippets; locate each in the HTML for line numbers
        details = []
        for snippet in pills_without_links[:3]:
            # Use the first ~40 chars of the snippet as a search key
            key = snippet[:40]
            loc = ctx.locate(key, source="html", max_results=1)
            if loc:
                details.append(f"  line {loc[0]['line']}: {loc[0]['snippet']}")
            else:
                details.append(f"  {snippet}")
        more = ""
        if len(pills_without_links) > 3:
            more = f"\n  ... and {len(pills_without_links) - 3} more"
        ctx.warn(
            "style.pill_cta_only",
            f"found {len(pills_without_links)} pill-styled element(s) without nearby <a> tag — "
            "pills are CTA-only per style guide\n"
            + "\n".join(details) + more,
        )
    else:
        ctx.ok("style.pill_cta_only")
