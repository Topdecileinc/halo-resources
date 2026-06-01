"""Brief reconciliation: JSON and HTML must reflect what the brief actually said."""

import re


def _to_num(s):
    if not s:
        return None
    m = re.search(r"\d+(?:\.\d+)?", s.replace(",", ""))
    return float(m.group(0)) if m else None


def _normalize_compare(s):
    return re.sub(r"\s+", " ", s).strip().lower()


def check(ctx):
    if not ctx.brief:
        return

    email = (ctx.body or {}).get("messages", {}).get("email", {}) or {}
    visible = re.sub(r"<[^>]+>", " ", ctx.html or "")

    # JSON reconciliation only if we have a parsed body
    if ctx.body:
        # app_id
        brief_app = ctx.brief_field(9, "Braze `app_id`")
        json_app = email.get("app_id", "")
        if brief_app:
            if brief_app == json_app:
                ctx.ok("brief.app_id_match")
            else:
                ctx.err(
                    "brief.app_id_match",
                    f"app_id mismatch — brief={brief_app!r}, json={json_app!r}",
                )

        # from (forgiving on whitespace)
        brief_from = ctx.brief_field(9, "`from`")
        json_from = email.get("from", "")
        if brief_from:
            if brief_from.replace(" ", "") == json_from.replace(" ", ""):
                ctx.ok("brief.from_match")
            else:
                ctx.warn(
                    "brief.from_match",
                    f"from differs — brief={brief_from!r}, json={json_from!r}",
                )

        # segment_id
        brief_seg = ctx.brief_field(10, "Segment ID (UUID)")
        json_seg = ctx.body.get("segment_id", "")
        if brief_seg:
            if brief_seg == json_seg:
                ctx.ok("brief.segment_id_match")
            else:
                ctx.err(
                    "brief.segment_id_match",
                    f"segment_id mismatch — brief={brief_seg!r}, json={json_seg!r}",
                )

    # §5 pricing reconciliation
    show = (ctx.brief_field(5, "Show pricing?") or "").lower()
    if show.startswith("y"):
        orig = ctx.brief_field(5, "Original price") or ""
        sale = ctx.brief_field(5, "Sale / final price") or ""
        disc = ctx.brief_field(5, "Discount") or ""

        no, ns, nd = _to_num(orig), _to_num(sale), _to_num(disc)
        if no and ns and nd:
            if abs((no - ns) - nd) < 0.01:
                ctx.ok("brief.pricing_math_reconciles")
            else:
                ctx.err(
                    "brief.pricing_math_reconciles",
                    f"pricing math off: {orig} - {sale} != {disc}",
                )

        if ctx.html:
            for label, val in (("original", orig), ("sale", sale)):
                if val:
                    nm = re.search(r"\d+", val)
                    if nm and nm.group(0) in visible:
                        ctx.ok(f"brief.price_visible.{label}")
                    else:
                        ctx.warn(
                            f"brief.price_visible.{label}",
                            f"{label} price {val!r} not found in HTML",
                        )

    # Headline visible in HTML (if not "suggest")
    headline = ctx.brief_field(3, "Headline")
    if headline and headline.lower() != "suggest" and ctx.html:
        if _normalize_compare(headline) in _normalize_compare(visible):
            ctx.ok("brief.headline_visible")
        else:
            ctx.warn(
                "brief.headline_visible",
                f"headline from brief not found in HTML: {headline!r}",
            )
