"""Block fidelity: verifies reused HTML blocks in the rendered email match
their source files in sections/ and components/.

Convention:
  Every section_*.html file must contain class="section-<name>" on its root element.
  Every component_*.html file must contain class="component-<name>" on its root element.

The validator:
  1. Loads source files from sections_dir and components_dir.
  2. Verifies each source file has its required class (catches "forgot the class" bugs).
  3. Scans the rendered email for elements with section-* / component-* classes.
  4. For each labeled block in the email, verifies its structure matches the source.
  5. Checks header appears once and is the first labeled block; footer appears once and
     is the last labeled block.

Unlabeled HTML (fresh campaign middle) is NOT validated here — that's the job of
html_hygiene.py and style_guide.py. This module only verifies blocks that claim
provenance via a class.
"""

import re
from pathlib import Path


# ---------- source file parsing ----------

def _extract_class_from_root(html_text):
    """Find the first class attribute in the file and return matching section-* or component-*.

    Source files are short and the marker class is on the root element near the top.
    Returns (kind, name) or (None, None) — e.g. ("section", "header") or ("component", "button").
    """
    # Look for class="...section-NAME..." or class="...component-NAME..."
    m = re.search(r'class\s*=\s*"[^"]*\b(section|component)-([a-z0-9_-]+)\b[^"]*"', html_text, re.I)
    if m:
        return m.group(1).lower(), m.group(2).lower()
    return None, None


def _structural_signature(html_text):
    """Produce a structural signature of the HTML source.

    Strategy: extract the *tag sequence* (ordered list of opening tag names) and
    the count of each. Two HTML blocks with the same tag sequence and same counts
    are considered structurally equivalent — placeholders/text content don't matter
    for the comparison. This is forgiving of placeholder substitution but catches
    drift in structure (added/removed tags, reordered nesting).
    """
    # Strip HTML comments
    stripped = re.sub(r"<!--.*?-->", "", html_text, flags=re.DOTALL)
    # Strip MSO conditional comments (commented blocks containing tags)
    stripped = re.sub(r"<!--\[if[^\]]*\]>.*?<!\[endif\]-->", "", stripped, flags=re.DOTALL)
    tags = re.findall(r"<\s*([a-zA-Z][a-zA-Z0-9]*)\b", stripped)
    return tuple(t.lower() for t in tags)


def _load_source_blocks(folder, kind_expected, ctx, strict):
    """Walk a folder, parse every <kind>_*.html, return dict[name] = (signature, filepath).

    'kind_expected' is "section" or "component" — used to verify the class matches the file's role.
    Emits errors/warnings on ctx for malformed source files.
    """
    out = {}
    if not folder or not folder.exists():
        return out

    pattern = f"{kind_expected}_*.html"
    for src in sorted(folder.glob(pattern)):
        try:
            text = src.read_text(encoding="utf-8")
        except Exception as e:
            ctx.err(f"block.source_unreadable", f"{src.name}: {e}")
            continue

        kind, name = _extract_class_from_root(text)

        if kind is None:
            level = ctx.err if strict else ctx.warn
            level(
                "block.source_missing_class",
                f"{src.name} has no `class=\"{kind_expected}-<name>\"` — required by convention",
            )
            continue

        if kind != kind_expected:
            ctx.err(
                "block.source_wrong_class_kind",
                f"{src.name} declares class={kind!r} but lives in the {kind_expected}s folder",
            )
            continue

        # File should be {kind}_{name}.html — verify class name matches filename
        expected_name = src.stem[len(kind_expected) + 1:]  # strip "section_" or "component_"
        if name != expected_name.replace("_", "-") and name != expected_name:
            ctx.warn(
                "block.source_name_mismatch",
                f"{src.name} declares class={kind}-{name}, but filename suggests {kind}-{expected_name}",
            )

        signature = _structural_signature(text)
        if not signature:
            ctx.warn(
                "block.source_empty",
                f"{src.name} has no HTML tags — can't extract structural signature",
            )
            continue

        out[name] = (signature, src)

    return out


# ---------- rendered email parsing ----------

def _find_labeled_blocks(html):
    """Find every element with a section-* or component-* class in the rendered HTML.

    Returns list of (kind, name, block_html, position) in document order.
    'position' is the start index in the source HTML (used for order checking).

    The block_html captures from the opening tag through its matching close tag,
    using a simple depth counter for the same tag name.
    """
    out = []
    # Match opening tags that have class="...section-NAME..." or class="...component-NAME..."
    pattern = re.compile(
        r'<\s*([a-zA-Z][a-zA-Z0-9]*)\b[^>]*\bclass\s*=\s*"[^"]*\b(section|component)-([a-z0-9_-]+)\b[^"]*"[^>]*>',
        re.I,
    )

    for m in pattern.finditer(html):
        tag, kind, name = m.group(1).lower(), m.group(2).lower(), m.group(3).lower()
        start = m.start()
        # Find the matching close tag by counting nested same-tag opens
        block_text = _extract_block(html, start, tag)
        out.append((kind, name, block_text, start))

    return out


def _extract_block(html, start, tag):
    """Extract HTML from `start` through the matching </tag>."""
    open_re = re.compile(rf"<\s*{tag}\b", re.I)
    close_re = re.compile(rf"</\s*{tag}\s*>", re.I)

    depth = 1
    pos = start + 1
    while pos < len(html) and depth > 0:
        next_open = open_re.search(html, pos)
        next_close = close_re.search(html, pos)
        if not next_close:
            return html[start:]  # malformed; return rest
        if next_open and next_open.start() < next_close.start():
            depth += 1
            pos = next_open.end()
        else:
            depth -= 1
            pos = next_close.end()
    return html[start:pos]


# ---------- main check ----------

def check(ctx, sections_dir=None, components_dir=None, strict=True):
    if not ctx.html:
        return

    sections_dir = Path(sections_dir) if sections_dir else None
    components_dir = Path(components_dir) if components_dir else None

    # Load source files
    sections = _load_source_blocks(sections_dir, "section", ctx, strict)
    components = _load_source_blocks(components_dir, "component", ctx, strict)

    if sections:
        ctx.ok(f"block.sections_loaded (n={len(sections)})")
    elif sections_dir and sections_dir.exists():
        ctx.warn("block.sections_loaded", f"no section_*.html files in {sections_dir}")

    if components:
        ctx.ok(f"block.components_loaded (n={len(components)})")
    elif components_dir and components_dir.exists():
        ctx.warn("block.components_loaded", f"no component_*.html files in {components_dir}")

    # Scan the rendered email
    blocks = _find_labeled_blocks(ctx.html)

    # Check header / footer presence and position
    section_blocks = [(name, pos) for kind, name, _, pos in blocks if kind == "section"]
    section_names = [n for n, _ in section_blocks]

    if "header" in sections:
        header_count = section_names.count("header")
        if header_count == 0:
            ctx.err("block.header_present", "no element with class='section-header' in rendered email")
        elif header_count > 1:
            ctx.err("block.header_present", f"section-header appears {header_count} times — should appear exactly once")
        else:
            ctx.ok("block.header_present")
            # Position: should be first labeled section
            if section_names and section_names[0] != "header":
                ctx.warn(
                    "block.header_first",
                    f"section-header should be the first section in the email; found {section_names[0]} first",
                )
            else:
                ctx.ok("block.header_first")

    if "footer" in sections:
        footer_count = section_names.count("footer")
        if footer_count == 0:
            ctx.err("block.footer_present", "no element with class='section-footer' in rendered email")
        elif footer_count > 1:
            ctx.err("block.footer_present", f"section-footer appears {footer_count} times — should appear exactly once")
        else:
            ctx.ok("block.footer_present")
            if section_names and section_names[-1] != "footer":
                ctx.warn(
                    "block.footer_last",
                    f"section-footer should be the last section in the email; found {section_names[-1]} last",
                )
            else:
                ctx.ok("block.footer_last")

    # Verify each labeled block in the email matches its source signature
    matched_kinds = set()
    for kind, name, block_html, block_offset in blocks:
        sources = sections if kind == "section" else components
        if name not in sources:
            loc = ctx.locate(f"{kind}-{name}", source="html", max_results=1)
            loc_text = f" (line {loc[0]['line']})" if loc else ""
            ctx.warn(
                f"block.{kind}_unknown",
                f"rendered email has class='{kind}-{name}' but no {kind}_{name}.html source file exists{loc_text}",
            )
            continue

        source_sig, source_path = sources[name]
        rendered_sig = _structural_signature(block_html)

        if rendered_sig == source_sig:
            ctx.ok(f"block.{kind}_match.{name}")
        else:
            from collections import Counter
            src_counts = Counter(source_sig)
            rend_counts = Counter(rendered_sig)
            added = {t: rend_counts[t] - src_counts.get(t, 0) for t in rend_counts if rend_counts[t] > src_counts.get(t, 0)}
            removed = {t: src_counts[t] - rend_counts.get(t, 0) for t in src_counts if src_counts[t] > rend_counts.get(t, 0)}
            diag = []
            if added:
                diag.append(f"extra tags: {dict(added)}")
            if removed:
                diag.append(f"missing tags: {dict(removed)}")

            # Convert the block_offset to a line number
            line = (ctx.html.count("\n", 0, block_offset) + 1) if ctx.html else 0
            ctx.warn(
                f"block.{kind}_drift.{name}",
                f"rendered {kind}-{name} (line {line}) structure differs from {source_path.name}: "
                + "; ".join(diag),
            )
        matched_kinds.add((kind, name))

    if blocks:
        ctx.ok(f"block.labeled_blocks_found (n={len(blocks)})")
