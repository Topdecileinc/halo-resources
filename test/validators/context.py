"""Shared validation context: parsed inputs + result collectors."""

import json
import re
from pathlib import Path


class Context:
    """Carries parsed inputs and accumulates errors/warnings/passed for one campaign."""

    def __init__(self, campaign_name, html_path=None, json_path=None,
                 brief_path=None, rules_dirs=None):
        self.campaign = campaign_name
        self.html_path = Path(html_path) if html_path else None
        self.json_path = Path(json_path) if json_path else None
        self.brief_path = Path(brief_path) if brief_path else None
        # Rules are split across the layered folders, so search a list of dirs.
        # Accept a single path too, for backward compatibility.
        if rules_dirs is None:
            rules_dirs = []
        elif isinstance(rules_dirs, (str, Path)):
            rules_dirs = [rules_dirs]
        self.rules_dirs = [Path(d) for d in rules_dirs]

        self.html = self.html_path.read_text(encoding="utf-8") if self.html_path else None
        self.brief = self.brief_path.read_text(encoding="utf-8") if self.brief_path else None

        self.json_text = self.json_path.read_text(encoding="utf-8") if self.json_path else None
        self.body = None
        if self.json_text:
            try:
                self.body = json.loads(self.json_text)
            except json.JSONDecodeError:
                self.body = None  # caller's json check will catch and report

        self.errors = []      # list of (check, msg)
        self.warnings = []    # list of (check, msg)
        self.passed = []      # list of check strings

    def err(self, check, msg):
        self.errors.append((check, msg))

    def warn(self, check, msg):
        self.warnings.append((check, msg))

    def ok(self, check):
        self.passed.append(check)

    def read_rule(self, filename):
        """Read a rule file by searching the configured rule dirs; None if missing."""
        for d in self.rules_dirs:
            p = d / filename
            if p.exists():
                return p.read_text(encoding="utf-8")
        return None

    def brief_field(self, section_num, label):
        """Extract a value from a brief table row inside section §N.
        Looks for: | <label> | <value> | (case-insensitive). Returns the value or None.
        """
        if not self.brief:
            return None
        section_re = re.compile(rf"^## {section_num}\.", re.MULTILINE)
        m = section_re.search(self.brief)
        if not m:
            return None
        start = m.start()
        next_m = re.search(r"\n## \d+\.", self.brief[start + 1:])
        end = start + 1 + next_m.start() if next_m else len(self.brief)
        section = self.brief[start:end]
        row_re = re.compile(rf"\|\s*{re.escape(label)}\s*\|\s*(.+?)\s*\|", re.IGNORECASE)
        rm = row_re.search(section)
        return rm.group(1).strip() if rm else None

    # ---------- location helpers ----------

    def locate(self, needle, source="html", max_results=3, snippet_chars=100):
        """Find occurrences of `needle` (str or compiled regex) in the named source.

        Returns a list of {line, col, snippet} dicts, up to max_results.
        Snippet is ~snippet_chars wide, centered on the match, with leading/trailing
        whitespace collapsed and an arrow marker (›) just before the match.

        source: 'html', 'brief', or 'json' (the raw text of each).
        Returns [] if source isn't loaded or needle isn't found.
        """
        text = self._source_text(source)
        if not text:
            return []

        offsets = self._find_offsets(needle, text, max_results)
        return [self._offset_to_location(text, off, snippet_chars) for off in offsets]

    def locate_one(self, needle, source="html", snippet_chars=100):
        """Convenience: return a formatted 'line N: <snippet>' string for the first
        match, or empty string if not found. Suitable for appending to a warning message.
        """
        hits = self.locate(needle, source=source, max_results=1, snippet_chars=snippet_chars)
        if not hits:
            return ""
        return f"line {hits[0]['line']}: {hits[0]['snippet']}"

    def format_locations(self, needle, source="html", max_results=3, snippet_chars=100):
        """Convenience: format multiple hits as a multi-line string for a warning message.
        Returns '' if no matches.
        """
        hits = self.locate(needle, source=source, max_results=max_results, snippet_chars=snippet_chars)
        if not hits:
            return ""
        total = self._count_matches(needle, source)
        out = [f"line {h['line']}: {h['snippet']}" for h in hits]
        if total > len(hits):
            out.append(f"... and {total - len(hits)} more")
        return "\n".join(out)

    def _source_text(self, source):
        return {"html": self.html, "brief": self.brief, "json": self.json_text}.get(source)

    def _find_offsets(self, needle, text, max_results):
        """Return up to max_results character offsets where needle matches."""
        offsets = []
        if hasattr(needle, "finditer"):
            for m in needle.finditer(text):
                offsets.append(m.start())
                if len(offsets) >= max_results:
                    break
        else:
            pos = 0
            while len(offsets) < max_results:
                i = text.find(needle, pos)
                if i == -1:
                    break
                offsets.append(i)
                pos = i + max(1, len(needle))
        return offsets

    def _count_matches(self, needle, source):
        text = self._source_text(source)
        if not text:
            return 0
        if hasattr(needle, "finditer"):
            return sum(1 for _ in needle.finditer(text))
        if not needle:
            return 0
        count = 0
        pos = 0
        while True:
            i = text.find(needle, pos)
            if i == -1:
                return count
            count += 1
            pos = i + len(needle)

    @staticmethod
    def _offset_to_location(text, offset, snippet_chars):
        """Convert a character offset into {line, col, snippet}."""
        line = text.count("\n", 0, offset) + 1
        line_start = text.rfind("\n", 0, offset) + 1
        col = offset - line_start + 1

        # Build a snippet ~snippet_chars wide, centered on the match
        half = snippet_chars // 2
        start = max(0, offset - half)
        end = min(len(text), offset + half)
        snippet = text[start:end]

        # Mark where the match starts within the snippet with '›'
        match_pos_in_snippet = offset - start
        snippet = snippet[:match_pos_in_snippet] + "›" + snippet[match_pos_in_snippet:]

        # Collapse newlines and excessive whitespace so the snippet fits on one line
        snippet = re.sub(r"\s+", " ", snippet).strip()

        # Add ellipsis if we trimmed
        if start > 0:
            snippet = "..." + snippet
        if end < len(text):
            snippet = snippet + "..."

        return {"line": line, "col": col, "snippet": snippet}
