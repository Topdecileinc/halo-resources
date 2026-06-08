"""HTML hygiene: well-formedness, email-safe CSS, image attrs, copy rules."""

import re
from html.parser import HTMLParser


class _WellFormedCheck(HTMLParser):
    """Lightweight HTML5-tolerant well-formedness check (stdlib only)."""

    VOID = {
        "area", "base", "br", "col", "embed", "hr", "img",
        "input", "link", "meta", "param", "source", "track", "wbr",
    }

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.stack = []
        self.unclosed = []
        self.unexpected_close = []

    def handle_starttag(self, tag, attrs):
        if tag not in self.VOID:
            self.stack.append(tag)

    def handle_endtag(self, tag):
        if self.stack and self.stack[-1] == tag:
            self.stack.pop()
        elif tag in self.stack:
            while self.stack and self.stack[-1] != tag:
                self.unclosed.append(self.stack.pop())
            if self.stack:
                self.stack.pop()
        else:
            self.unexpected_close.append(tag)


def _check_well_formed(ctx, html):
    parser = _WellFormedCheck()
    try:
        parser.feed(html)
    except Exception as e:
        ctx.err("html.parse", f"HTML parser raised: {e}")
        return
    parser.close()

    leftover = parser.unclosed + parser.stack
    if leftover:
        ctx.warn(
            "html.well_formed",
            f"unclosed tags: {leftover[:10]}{'…' if len(leftover) > 10 else ''}",
        )
    else:
        ctx.ok("html.well_formed")

    if parser.unexpected_close:
        ctx.warn(
            "html.unexpected_closing_tags",
            f"unexpected closing tags: {parser.unexpected_close[:10]}",
        )


def check(ctx):
    if not ctx.html:
        return

    html = ctx.html
    _check_well_formed(ctx, html)

    # Em dashes in visible copy
    visible = re.sub(r"<[^>]+>", " ", html)
    if "\u2014" in visible:
        n = visible.count("\u2014")
        # Locate em dashes in the actual HTML (not the stripped-visible) for accurate line numbers
        hits_text = ctx.format_locations("\u2014", source="html", max_results=3)
        ctx.err(
            "html.no_em_dashes",
            f"found {n} em dash(es) in visible copy — rules_email_copy.md forbids them\n" + hits_text,
        )
    else:
        ctx.ok("html.no_em_dashes")

    # Image checks
    imgs = re.findall(r"<img\b[^>]*>", html, re.I)
    if not imgs:
        ctx.warn("html.images_present", "no <img> tags found — is that intended?")

    for i, tag in enumerate(imgs):
        tag_id = f"img[{i}]"
        if not re.search(r"\balt\s*=", tag, re.I):
            ctx.err("html.img_alt", f"{tag_id} missing alt attribute")
        if not re.search(r"\bwidth\s*=", tag, re.I):
            ctx.err("html.img_width", f"{tag_id} missing width attribute")
        if not re.search(r"\bheight\s*=", tag, re.I):
            ctx.err("html.img_height", f"{tag_id} missing height attribute")
        if "display:block" not in tag.lower().replace(" ", ""):
            ctx.warn("html.img_display_block", f"{tag_id} missing style=\"display:block\"")

        m = re.search(r"\bsrc\s*=\s*['\"]([^'\"]+)['\"]", tag, re.I)
        if not m:
            ctx.err("html.img_src", f"{tag_id} has no src attribute")
        else:
            src = m.group(1)
            if not src.lower().startswith(("http://", "https://")):
                ctx.err(
                    "html.img_src_absolute",
                    f"{tag_id} src is not absolute: {src!r} — hosted URLs required",
                )

    if imgs:
        ctx.ok(f"html.img_attrs_checked (n={len(imgs)})")

    # No flex / grid
    if re.search(r"display\s*:\s*(flex|grid)\b", html, re.I):
        ctx.err("html.no_flex_grid", "found display:flex or display:grid — not email-safe")
    else:
        ctx.ok("html.no_flex_grid")

    # Table-based heuristic
    n_divs = len(re.findall(r"<div\b", html, re.I))
    n_tables = len(re.findall(r"<table\b", html, re.I))
    if n_divs > n_tables * 2 and n_tables < 3:
        ctx.warn(
            "html.table_based",
            f"many <div>s ({n_divs}) vs <table>s ({n_tables}) — emails should be table-based",
        )
    else:
        ctx.ok("html.table_based")

    # MSO conditional comments
    if "<!--[if mso]>" in html or "<!--[if !mso]>" in html:
        ctx.ok("html.mso_conditionals_present")
    else:
        ctx.warn(
            "html.mso_conditionals_present",
            "no MSO conditional comments — Outlook-specific fixes may be missing",
        )

    # Font fallback — the acceptable web-safe fonts are read from the style guide
    # md (its "Email fallback stack"), not hardcoded, so this tracks the rule file.
    sg = ctx.read_rule("rules_email_style_guide.md") or ""
    m = re.search(r"[Ee]mail fallback stack[:\s]*`([^`]+)`", sg)
    if m:
        fallbacks = [p.strip().strip('"\'') for p in m.group(1).split(",")
                     if p.strip() and p.strip().lower() != "inter"]
    else:
        fallbacks = ["Arial", "Helvetica", "sans-serif"]   # only if the md can't be parsed
    pat = "|".join(re.escape(f) for f in fallbacks) or "Arial|Helvetica|sans-serif"
    if re.search(r"font-family\s*:[^;'\"]*\b(" + pat + r")\b", html, re.I):
        ctx.ok("html.font_fallback")
    else:
        ctx.warn("html.font_fallback", "no web-safe font fallback (" + ", ".join(fallbacks) + ") detected")
