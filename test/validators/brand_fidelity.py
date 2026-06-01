"""Brand fidelity: HTML must reflect what rules_*.md says (legal line, social URLs, etc.)."""

import re


def _normalize(s):
    return re.sub(r"\s+", " ", s).replace("\u00a0", " ").strip()


def _extract_urls(text):
    return re.findall(r"https?://[^\s`)>]+", text)


def check(ctx, strict=True):
    if not ctx.html or not ctx.rules_dir:
        return

    visible = _normalize(re.sub(r"<[^>]+>", " ", ctx.html))

    # ----- footer (rules_email_footer.md) -----
    footer = ctx.read_rule("rules_email_footer.md")
    if not footer:
        level = ctx.err if strict else ctx.warn
        level("brand.footer_source_missing", "rules_email_footer.md not found — footer checks skipped")
    else:
        m = re.search(r"\*\*Legal / company line:\*\*\s*\*([^*]+)\*", footer)
        if not m:
            level = ctx.err if strict else ctx.warn
            level(
                "brand.footer_source_unparseable",
                "could not extract legal line from rules_email_footer.md — check the file format",
            )
        else:
            legal = _normalize(m.group(1))
            if legal in visible:
                ctx.ok("brand.footer_legal_line_present")
            else:
                ctx.err(
                    "brand.footer_legal_line_present",
                    f"footer legal line not found in HTML — expected: {legal!r}",
                )

        if "unsubscribe" in ctx.html.lower():
            ctx.ok("brand.unsubscribe_present")
        else:
            ctx.err("brand.unsubscribe_present", "no 'unsubscribe' link/text in HTML")

        urls = _extract_urls(footer)
        social_urls = [u for u in urls if "cdn.braze" in u or "braze-images" in u]
        missing = [u for u in social_urls if u not in ctx.html]
        if social_urls and not missing:
            ctx.ok(f"brand.social_urls_match (n={len(social_urls)})")
        for u in missing:
            ctx.warn("brand.social_urls_match", f"URL from rules_email_footer.md not in HTML: {u}")

    # ----- brand (rules_brand.md) -----
    brand = ctx.read_rule("rules_brand.md")
    if not brand:
        level = ctx.err if strict else ctx.warn
        level("brand.brand_source_missing", "rules_brand.md not found — brand checks skipped")
        return

    logo_urls = [u for u in _extract_urls(brand) if "braze-images" in u or "cdn.braze" in u]
    missing = [u for u in logo_urls if u not in ctx.html]
    if logo_urls and not missing:
        ctx.ok("brand.logo_url_checked")
    for u in missing:
        ctx.warn("brand.logo_url_present", f"logo URL from rules_brand.md not in HTML: {u}")
