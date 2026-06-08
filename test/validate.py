#!/usr/bin/env python3
"""
test/validate.py

Walks every campaign folder under emails/ and validates the email package against
the rules (brand-brain/, email-design-system/, braze-deployment/) and the
sections/components in email-design-system/.

Each campaign folder must contain exactly:
  - one .html file (the email)
  - one .json file (the Braze send body)
  - one .md   file (the brief)

Filenames don't matter — found by extension.

Run from anywhere — paths resolve relative to this script's location:

    python3 test/validate.py        # from repo root
    python3 validate.py             # from inside test/

Other commands:
    python3 validate.py --list-checks    # show every check the toolkit runs

Exits 0 if no errors, 1 if any campaign has errors.
"""

import argparse
import configparser
import sys
from pathlib import Path

SCRIPT_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(SCRIPT_DIR))

from validators.context import Context
from validators import (
    json_body,
    html_hygiene,
    brand_fidelity,
    brief_reconciliation,
    style_guide,
    block_fidelity,
    w3c,
)


# ---------- config ----------
def load_config():
    cfg_path = SCRIPT_DIR / "config.ini"
    cp = configparser.ConfigParser()
    if cfg_path.exists():
        cp.read(cfg_path, encoding="utf-8")
    else:
        print(f"NOTE: config.ini not found at {cfg_path}, using defaults")

    def resolve(p, base=SCRIPT_DIR):
        path = Path(p).expanduser()
        if not path.is_absolute():
            path = (base / path).resolve()
        return path

    # Resolve repo_root first; other paths default to inside it
    repo_root = resolve(cp.get("paths", "repo_root", fallback=".."))

    # Rules can live across several layered folders. Accept a comma-separated
    # `rules_dirs`; fall back to a legacy single `rules_dir` if that's all that's set.
    rules_spec = cp.get("paths", "rules_dirs",
                        fallback=cp.get("paths", "rules_dir",
                                        fallback="brand-brain, email-design-system, braze-deployment"))
    rules_dirs = [resolve(d.strip(), base=repo_root) for d in rules_spec.split(",") if d.strip()]

    return {
        "repo_root":      repo_root,
        "emails_dir":     resolve(cp.get("paths", "emails_dir", fallback="./emails")),
        "rules_dirs":     rules_dirs,
        "sections_dir":   resolve(cp.get("paths", "sections_dir", fallback="email-design-system/sections"), base=repo_root),
        "components_dir": resolve(cp.get("paths", "components_dir", fallback="email-design-system/components"), base=repo_root),
        "fail_fast":            cp.getboolean("behavior", "fail_fast", fallback=False),
        "verbose_passed":       cp.getboolean("behavior", "verbose_passed", fallback=True),
        "strict_rule_parsing":  cp.getboolean("behavior", "strict_rule_parsing", fallback=True),
        "w3c_enabled":          cp.getboolean("w3c", "enabled", fallback=False),
        "w3c_use_web_service":  cp.getboolean("w3c", "use_web_service", fallback=True),
        "vnu_jar_path":         cp.get("w3c", "vnu_jar_path", fallback="").strip(),
    }


# ---------- check catalog (for --list-checks) ----------
CHECK_CATALOG = {
    "JSON send body": [
        ("json.parse",                    "JSON is valid and parseable"),
        ("json.required.broadcast",       "top-level field 'broadcast' is present"),
        ("json.required.segment_id",      "top-level field 'segment_id' is present"),
        ("json.required.messages",        "top-level field 'messages' is present"),
        ("json.broadcast_true",           "broadcast is boolean true (required for segment sends)"),
        ("json.segment_id_uuid",          "segment_id is a valid UUID"),
        ("json.forbidden.absent.audience",        "audience field is absent"),
        ("json.forbidden.absent.campaign_id",     "campaign_id field is absent"),
        ("json.forbidden.absent.external_user_ids", "external_user_ids field is absent"),
        ("json.app_id_uuid",              "messages.email.app_id is a valid UUID"),
        ("json.from_format",              "messages.email.from matches 'Display Name <[email protected]>'"),
        ("json.from_no_mask",             "from does NOT contain a chat-rendered '[email protected]' mask"),
        ("json.subject_present",          "subject is present and non-empty"),
        ("json.subject_length",           "subject is under ~100 chars (warning if over)"),
        ("json.preheader_present",        "preheader is present (warning if missing)"),
        ("json.body_present",             "body is present and non-empty"),
        ("json.body_is_html",             "body starts with <!DOCTYPE or <html>"),
        ("json.body_length",              "body is at least 500 chars (warning if shorter)"),
    ],
    "HTML hygiene": [
        ("html.well_formed",              "all tags are balanced and properly closed"),
        ("html.no_em_dashes",             "no em dashes (\u2014) in visible copy"),
        ("html.images_present",           "at least one <img> tag is present"),
        ("html.img_alt",                  "every <img> has an alt attribute"),
        ("html.img_width",                "every <img> has a width attribute"),
        ("html.img_height",               "every <img> has a height attribute"),
        ("html.img_display_block",        "every <img> has style=\"display:block\""),
        ("html.img_src",                  "every <img> has a src attribute"),
        ("html.img_src_absolute",         "every <img> src is an absolute https:// URL"),
        ("html.no_flex_grid",             "no display:flex or display:grid (not email-safe)"),
        ("html.table_based",              "structure is table-based, not div-based (heuristic)"),
        ("html.mso_conditionals_present", "MSO conditional comments present for Outlook"),
        ("html.font_fallback",            "web-safe font fallback (Arial/Helvetica) declared"),
    ],
    "Brand fidelity (vs rules_*.md)": [
        ("brand.footer_source_missing",    "(error if rules_email_footer.md missing)"),
        ("brand.footer_source_unparseable","(error if legal line can't be extracted)"),
        ("brand.footer_legal_line_present","exact legal line from rules_email_footer.md is in HTML"),
        ("brand.unsubscribe_present",      "'unsubscribe' text/link is in HTML"),
        ("brand.social_urls_match",        "every social icon URL from rules_email_footer.md is in HTML"),
        ("brand.brand_source_missing",     "(error if rules_brand.md missing)"),
        ("brand.logo_url_checked",         "logo URL from rules_brand.md is in HTML"),
    ],
    "Style guide enforcement (vs rules_email_style_guide.md)": [
        ("style.source_missing",                "(error if rules_email_style_guide.md missing)"),
        ("style.source_palette_unparseable",    "(error if color palette can't be extracted)"),
        ("style.source_radii_unparseable",      "(error if border-radius rules can't be extracted)"),
        ("style.source_font_unparseable",       "(error if font stack can't be extracted)"),
        ("style.source_palette_loaded",         "color palette extracted from style guide"),
        ("style.source_radii_loaded",           "border-radius values extracted from style guide"),
        ("style.source_font_loaded",            "font stack extracted from style guide"),
        ("style.color_palette",                 "every hex color in HTML is in the style guide palette"),
        ("style.border_radius",                 "every border-radius in HTML is allowed by the style guide"),
        ("style.font_family",                   "every font-family includes Inter + a web-safe fallback"),
        ("style.no_italics",                    "no <i> or <em> tags (style guide forbids italics for emphasis)"),
        ("style.pill_cta_only",                 "pill-styled elements (border-radius:999px/100%) appear only with <a> tags"),
    ],
    "Block fidelity (sections + components)": [
        ("block.sections_loaded",           "section_*.html files loaded from sections_dir"),
        ("block.components_loaded",         "component_*.html files loaded from components_dir"),
        ("block.source_unreadable",         "(error if a source file can't be read)"),
        ("block.source_missing_class",      "(error if a source file lacks its required class)"),
        ("block.source_wrong_class_kind",   "(error if class kind doesn't match folder)"),
        ("block.source_name_mismatch",      "(warning if class name doesn't match filename)"),
        ("block.source_empty",              "(warning if a source file has no HTML tags)"),
        ("block.labeled_blocks_found",      "rendered HTML contains labeled section/component blocks"),
        ("block.header_present",            "section-header appears exactly once in rendered HTML"),
        ("block.header_first",              "section-header is the first labeled section"),
        ("block.footer_present",            "section-footer appears exactly once"),
        ("block.footer_last",               "section-footer is the last labeled section"),
        ("block.section_match.<name>",      "rendered section matches its source's structural signature"),
        ("block.section_drift.<name>",      "(warning if rendered section's structure differs from source)"),
        ("block.section_unknown",           "(warning if class references a section file that doesn't exist)"),
        ("block.component_match.<name>",    "rendered component matches its source"),
        ("block.component_drift.<name>",    "(warning if rendered component drifts from source)"),
        ("block.component_unknown",         "(warning if class references a component file that doesn't exist)"),
    ],
    "Brief reconciliation (no making things up)": [
        ("brief.app_id_match",            "JSON app_id matches what brief §9 says"),
        ("brief.from_match",              "JSON from matches what brief §9 says (catches mask leaks)"),
        ("brief.segment_id_match",        "JSON segment_id matches what brief §10 says"),
        ("brief.pricing_math_reconciles", "brief §5 pricing: Original - Sale = Discount"),
        ("brief.price_visible.original",  "brief's original price appears in the HTML"),
        ("brief.price_visible.sale",      "brief's sale price appears in the HTML"),
        ("brief.headline_visible",        "brief's headline appears in the HTML (if not 'suggest')"),
    ],
    "W3C HTML validation (optional)": [
        ("w3c.tool_present",              "an external validator is available"),
        ("w3c.validate",                  "HTML passes W3C HTML5 validation (email-relaxed)"),
        ("w3c.unavailable",               "(warning if web service unreachable and no local tool)"),
    ],
}


def print_check_catalog():
    print("\n  Every check the toolkit performs, grouped by validator:\n")
    for group, checks in CHECK_CATALOG.items():
        print(f"  === {group} ===")
        for check_id, desc in checks:
            print(f"    {check_id:<46}  {desc}")
        print()
    total = sum(len(c) for c in CHECK_CATALOG.values())
    print(f"  Total: {total} checks across {len(CHECK_CATALOG)} groups.\n")


# ---------- campaign discovery (find by extension) ----------
def find_campaign_files(folder):
    by_ext = {".html": [], ".json": [], ".md": []}
    for p in folder.iterdir():
        if p.is_dir():
            continue
        ext = p.suffix.lower()
        if ext in by_ext:
            by_ext[ext].append(p)
        elif p.name.startswith("."):
            continue

    problems = []
    for ext, label in ((".html", "HTML file"), (".json", "JSON file"), (".md", "Markdown brief")):
        n = len(by_ext[ext])
        if n == 0:
            problems.append(f"missing {label} ({ext})")
        elif n > 1:
            names = ", ".join(p.name for p in by_ext[ext])
            problems.append(f"ambiguous: {n} {ext} files found ({names}) — must be exactly one")

    return (
        by_ext[".html"][0] if by_ext[".html"] else None,
        by_ext[".json"][0] if by_ext[".json"] else None,
        by_ext[".md"][0] if by_ext[".md"] else None,
        problems,
    )


def discover_campaigns(emails_dir):
    if not emails_dir.exists():
        return []
    out = []
    for sub in sorted(p for p in emails_dir.iterdir() if p.is_dir()):
        html_p, json_p, brief_p, problems = find_campaign_files(sub)
        out.append((sub.name, html_p, json_p, brief_p, problems))
    return out


# ---------- per-campaign validation ----------
def validate_campaign(campaign, html_path, json_path, brief_path, cfg):
    ctx = Context(
        campaign_name=campaign,
        html_path=html_path,
        json_path=json_path,
        brief_path=brief_path,
        rules_dirs=[d for d in cfg["rules_dirs"] if d.exists()] or None,
    )
    strict = cfg["strict_rule_parsing"]

    for group_name, fn, kwargs in [
        ("JSON send body",          json_body.check,            {}),
        ("HTML hygiene",            html_hygiene.check,         {}),
        ("Brand fidelity",          brand_fidelity.check,       {"strict": strict}),
        ("Style guide enforcement", style_guide.check,          {"strict": strict}),
        ("Block fidelity",          block_fidelity.check,       {
            "sections_dir": cfg["sections_dir"],
            "components_dir": cfg["components_dir"],
            "strict": strict,
        }),
        ("Brief reconciliation",    brief_reconciliation.check, {}),
    ]:
        try:
            fn(ctx, **kwargs)
        except Exception as e:
            ctx.err(f"{group_name}.crash", f"{e!r}")

    if cfg["w3c_enabled"]:
        try:
            w3c.check(ctx, vnu_jar_config=cfg["vnu_jar_path"],
                      use_web_service=cfg["w3c_use_web_service"])
        except Exception as e:
            ctx.err("w3c.crash", f"{e!r}")

    return ctx


# ---------- reporting ----------
def print_campaign_report(ctx, verbose_passed):
    p, w, e = len(ctx.passed), len(ctx.warnings), len(ctx.errors)
    status = "FAIL" if e else ("WARN" if w else "PASS")
    print(f"\n  [{status}] {ctx.campaign}  —  {p} passed, {w} warnings, {e} errors")
    if verbose_passed:
        for chk in ctx.passed:
            print(f"      [OK]   {chk}")
    for chk, msg in ctx.warnings:
        msg_lines = msg.splitlines()
        print(f"      [WARN] {chk}: {msg_lines[0]}")
        for line in msg_lines[1:]:
            print(f"             {line}")
    for chk, msg in ctx.errors:
        msg_lines = msg.splitlines()
        print(f"      [ERR]  {chk}: {msg_lines[0]}")
        for line in msg_lines[1:]:
            print(f"             {line}")


def print_summary_table(results, skipped_with_problems):
    if not results and not skipped_with_problems:
        return
    print()
    print(f"  {'Campaign':<40} {'Pass':>6} {'Warn':>6} {'Err':>6}  Status")
    print(f"  {'-' * 40} {'-' * 6} {'-' * 6} {'-' * 6}  {'-' * 8}")
    for ctx in results:
        p, w, e = len(ctx.passed), len(ctx.warnings), len(ctx.errors)
        status = "FAIL" if e else ("WARN" if w else "PASS")
        name = ctx.campaign[:40]
        print(f"  {name:<40} {p:>6} {w:>6} {e:>6}  {status}")
    for name, _ in skipped_with_problems:
        n = name[:40]
        print(f"  {n:<40} {'-':>6} {'-':>6} {'-':>6}  FAIL (folder)")


# ---------- main ----------
def main():
    ap = argparse.ArgumentParser(description="Validate email build packages.")
    ap.add_argument("--list-checks", action="store_true",
                    help="Show every check the toolkit can run and exit.")
    ap.add_argument("--emails", metavar="DIR",
                    help="Validate this emails dir instead of the one in config.ini "
                         "(absolute, or relative to the current working directory). "
                         "Used by the generation pipeline to gate one campaign at a time.")
    args = ap.parse_args()

    if args.list_checks:
        print_check_catalog()
        sys.exit(0)

    cfg = load_config()
    if args.emails:
        cfg["emails_dir"] = Path(args.emails).expanduser().resolve()

    print(f"Repo root:          {cfg['repo_root']}")
    print(f"Validating emails:  {cfg['emails_dir']}")
    print(f"Rules:              {', '.join(str(d) for d in cfg['rules_dirs'])}")
    print(f"Sections:           {cfg['sections_dir']}")
    print(f"Components:         {cfg['components_dir']}")

    if not any(d.exists() for d in cfg["rules_dirs"]):
        print(f"WARNING: no rules dirs found — brand/style/footer checks may degrade")
    if not cfg["w3c_enabled"]:
        print(f"Note: W3C validation disabled in config.ini")

    if not cfg["emails_dir"].exists():
        print(f"\nNo emails directory at {cfg['emails_dir']}.")
        print(f"Create it and add campaign subfolders.")
        sys.exit(0)

    campaigns = discover_campaigns(cfg["emails_dir"])
    if not campaigns:
        print(f"\nNo campaign folders found.")
        sys.exit(0)

    results = []
    skipped_problems = []
    skipped_empty = []

    EMPTY_FOLDER = [
        "missing HTML file (.html)",
        "missing JSON file (.json)",
        "missing Markdown brief (.md)",
    ]

    for name, html_p, json_p, brief_p, problems in campaigns:
        if problems == EMPTY_FOLDER:
            skipped_empty.append(name)
            continue

        if problems:
            print(f"\n  [FAIL] {name}  —  folder structure")
            for prob in problems:
                print(f"      [ERR]  campaign.folder: {prob}")
            skipped_problems.append((name, problems))
            if cfg["fail_fast"]:
                break
            continue

        ctx = validate_campaign(name, html_p, json_p, brief_p, cfg)
        results.append(ctx)
        print_campaign_report(ctx, verbose_passed=cfg["verbose_passed"])

        if cfg["fail_fast"] and ctx.errors:
            print(f"\nfail_fast=true — stopping.")
            break

    # Summary
    print(f"\n{'=' * 70}")
    print(f"  Summary")
    print(f"{'=' * 70}")
    print_summary_table(results, skipped_problems)

    if skipped_empty:
        print(f"\n  Empty folders skipped: {', '.join(skipped_empty)}")

    total_errors = sum(len(r.errors) for r in results) + len(skipped_problems)
    total_warnings = sum(len(r.warnings) for r in results)
    print(f"\n  {len(results) + len(skipped_problems)} campaign(s) checked, "
          f"{total_warnings} warnings, {total_errors} errors total")
    print(f"  Run `python3 validate.py --list-checks` to see every check performed.")

    if total_errors:
        print("\nFAILED.")
        sys.exit(1)
    print("\nAll checks passed.")
    sys.exit(0)


if __name__ == "__main__":
    main()
