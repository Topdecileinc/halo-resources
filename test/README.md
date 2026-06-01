# Email validation harness

A zero-dependency Python 3 test harness that validates email build packages against
the rules, sections, and components in the repo. Catches mechanical and factual
errors before any email goes out.

Warnings that pinpoint something specific in the HTML include a **line number
and snippet** with a `›` marker showing the exact match position — so you can
jump straight to the offending markup instead of grepping.

## Quick start

```bash
python3 validate.py                # from this folder
python3 test/validate.py           # from the repo root
python3 validate.py --list-checks  # see every check it runs
```

Exits 0 on no errors, 1 if any campaign has errors. Drop campaign packages into
`emails/`, run the script, read the report. That's the whole workflow.

---

## Layout

```
test/
├── validate.py              ← entry point
├── config.ini               ← paths and behavior toggles
├── README.md                ← this file
├── .gitignore               ← keeps emails/ local
├── validators/              ← one module per concern
│   ├── context.py
│   ├── json_body.py
│   ├── html_hygiene.py
│   ├── brand_fidelity.py
│   ├── style_guide.py
│   ├── block_fidelity.py
│   ├── brief_reconciliation.py
│   └── w3c.py
└── emails/                  ← campaign packages; gitignored
    └── <campaign-name>/
        ├── *.html
        ├── *.json
        └── *.md
```

## Adding an email to test

1. Make a folder under `emails/`, name it whatever you want.
2. Drop in **exactly three files**: one `.html` (the email), one `.json` (the Braze send body), one `.md` (the brief). Filenames don't matter — found by extension.
3. Run `python3 validate.py`.

Folders with more or fewer of any required type fail the campaign with a clear
error. Folders that are completely empty are silently skipped.

---

## Reading the output: line numbers and snippets

Warnings that find a specific thing in your HTML (a forbidden color, a bad font
declaration, a non-CTA pill, an italic tag, drift in a section, an em dash) emit
a **line number plus a snippet** so you can jump straight to the offending markup.
A `›` marker inside the snippet points at the exact match.

Example:

```
[WARN] style.color_palette: hex colors not in style guide palette: ['#884477']
       palette: [...]
         #884477:
           line 14: ...padding:24px;background-color:›#884477;font-family:Inter,Arial...
```

Up to 3 occurrences are shown per finding, with `... and N more` if there are
extras. Snippets are about 100 characters wide, centered on the match, with
whitespace collapsed to one line.

Warnings that report *absence* (e.g. "the legal footer text is missing") don't
have a location to point at — they say what's missing instead. Brief reconciliation
mismatches (e.g. `from` in the brief vs the JSON) compare two values rather than
pointing at a single source, so they also omit locations.

If you want to add a located warning to a new check, see `validators/context.py`
— `ctx.locate()`, `ctx.locate_one()`, and `ctx.format_locations()` are the helpers.

---

## Configuration

`config.ini` controls paths and behavior. All paths resolve relative to the `test/`
directory so the script runs identically from any working directory.

### `[paths]`

| Setting | Default | What it does |
|---|---|---|
| `repo_root` | `..` | Root of the resources repo. Other path defaults are derived from this. Change this one setting if the test folder ever moves. |
| `emails_dir` | `./emails` | Where campaign subfolders live. |
| `rules_dir` | `brand-standards` | Where `rules_*.md` files live (relative to `repo_root`). |
| `sections_dir` | `design-libraries/sections` | Where `section_*.html` files live. |
| `components_dir` | `design-libraries/components` | Where `component_*.html` files live. |

### `[behavior]`

| Setting | Default | What it does |
|---|---|---|
| `fail_fast` | `false` | If true, stop at the first campaign that errors. If false, validate all and summarize. |
| `verbose_passed` | `true` | If true, print every `[OK]` line. If false, only print warnings/errors and counts. Redirect output to a file if it's too long. |
| `strict_rule_parsing` | `true` | If true, validators that can't parse their rule file (e.g. style guide Colors table is malformed) emit **errors**. If false, they emit warnings and continue. Strict is the default because a silent skip is worse than a loud failure. |

### `[w3c]` — optional HTML conformance check

| Setting | Default | What it does |
|---|---|---|
| `enabled` | `false` | Disabled by default — email HTML legitimately violates HTML5 strict standards. Turn on for extra rigor. |
| `use_web_service` | `true` | When enabled, validates via W3C's free web service at `validator.w3.org/nu` — no install needed. Set false to use a local tool instead. |
| `vnu_jar_path` | (empty) | Optional path to a local `vnu.jar`. Used when `use_web_service = false`. |

If `enabled = true` and the web service can't be reached, the script falls back
to `vnu.jar` (if configured) or `html5validator` on PATH. If nothing works,
emits a warning — never an error.

---

## What it validates

There are **seven validator modules**, organized by concern. Every check has a
stable ID (e.g. `json.broadcast_true`) so you can grep for failures and refer
to them in issues. Run `python3 validate.py --list-checks` to see the complete
current catalog grouped by module.

This README explains *why* each module exists and *how* it works. The
`--list-checks` output is the authoritative list of *what* each module checks
(it's generated from the actual code, so it can't drift out of sync).

---

### Module 1: JSON send body (`json_body.py`)

**Why:** The Braze send is fired by POSTing a JSON file. If the JSON has the
wrong shape, wrong types, or missing/extra fields, the send either fails at the
API or — worse — silently misbehaves (e.g. you sent with `broadcast: true` but
no segment, so nothing went out but the request succeeded).

**What it does:**
- Parses the JSON; emits `json.parse` error if the file isn't valid JSON.
- Verifies required top-level fields (`broadcast`, `segment_id`, `messages`) are present.
- Verifies `broadcast` is boolean `true` (segment sends require this; the API rejects `false` if no recipients are specified).
- Verifies `segment_id` is a valid UUID (regex check).
- Verifies forbidden fields (`audience`, `campaign_id`, `external_user_ids`) are absent — these would expand the targeting beyond the named segment.
- Verifies `messages.email.app_id` is a valid UUID.
- Verifies `messages.email.from` matches `Display Name <[email protected]>` format **and** does NOT contain the chat-rendered placeholder `[email protected]` (a real bug we've hit — copy-paste from chat strips real emails for privacy and we don't want that mask in production data).
- Verifies subject is present (and warns if over ~100 chars — gets truncated in many inboxes).
- Verifies preheader is present (warns if missing — recommended for inbox preview).
- Verifies body is non-empty, starts with `<!DOCTYPE` or `<html>`, and is at least 500 chars (a few-char body is almost always a bug).

---

### Module 2: HTML hygiene (`html_hygiene.py`)

**Why:** Email HTML has constraints that web HTML doesn't — no flexbox, no CSS
grid, table-based layouts, every `<img>` needs explicit `alt`/`width`/`height`,
etc. Catching these here means you don't discover them when an email looks broken
in someone's inbox.

**What it does:**
- Parses the HTML with Python's stdlib HTML5-tolerant parser and warns if tags are unbalanced.
- Scans visible copy for em dashes (`—`) which `rules_email_copy.md` forbids; copy is supposed to use commas or colons instead. Emits line numbers and snippets for each em dash found.
- For every `<img>` tag: verifies it has `alt`, `width`, `height`, `display:block`, and an absolute `https://` URL (relative paths break in email; uploads aren't supported).
- Errors on `display:flex` or `display:grid` — neither is email-safe.
- Heuristic warning if the HTML has many `<div>`s vs. few `<table>`s (suggests non-table-based layout).
- Warns if no MSO conditional comments are present (you almost always want some for Outlook compatibility).
- Warns if no web-safe font fallback (Arial/Helvetica/sans-serif) is declared.

---

### Module 3: Brand fidelity (`brand_fidelity.py`)

**Why:** The footer legal line, the unsubscribe link, and the hosted social-icon
URLs aren't visual choices — they're stable boilerplate that must appear on every
send for compliance and brand consistency. This module reads `rules_email_footer.md`
and `rules_brand.md` directly and verifies the HTML actually uses them.

**What it does:**
- Reads `rules_email_footer.md`. If the file is missing or the legal line can't be parsed from it, emits an error (or warning, with `strict_rule_parsing = false`) — the validator-of-the-validator pattern.
- Verifies the exact legal/company line from the rules file appears verbatim in the rendered HTML.
- Verifies the word "unsubscribe" appears (compliance requirement).
- Verifies every social icon URL listed in `rules_email_footer.md` (Facebook, Instagram, TikTok, YouTube) actually appears in the HTML.
- Reads `rules_brand.md` and verifies the hosted logo URL appears in the HTML.

**The principle:** the rules files are the source of truth. The HTML must reflect
them. If the legal line in `rules_email_footer.md` changes, every future email
inherits the change automatically — and the validator catches anything that didn't.

---

### Module 4: Style guide enforcement (`style_guide.py`)

**Why:** The style guide defines the brand's visual vocabulary — exact color
palette, allowed border-radius values, font stack, no italics, pills-are-CTA-only.
Without enforcement, generated emails drift into "close enough" colors and
borders that erode brand consistency over time. This module is the brake on drift.

**What it does:**
- Parses `rules_email_style_guide.md` to extract: the **color palette** (every hex value listed), allowed **border-radius** values (24px for containers, 999px/100% for pills), the required **font stack** (Inter + Arial/Helvetica fallback).
- Validates that the parser actually extracted something. If it couldn't parse the palette or border-radius rules (e.g. someone reformatted the markdown tables), emits an **error** rather than silently passing — same validator-of-the-validator pattern.
- **Color palette check:** scans every `#RRGGBB` value in the HTML and flags any that isn't in the allowed palette. Pure white and pure black are always allowed as defaults. The grays `#F5F5F5` and `#F2F4F4` are both allowed (the style guide treats them as interchangeable light backgrounds).
- **Border radius check:** scans every `border-radius:` declaration and flags any value not in the allowed set.
- **Font family check:** every `font-family:` declaration must include `Inter` and one of `Arial`/`Helvetica`/`sans-serif` as fallback.
- **No italics:** errors if any `<i>` or `<em>` tag is present (style guide says use bold or color for emphasis).
- **Pill-CTA-only:** finds every element with pill border-radius (`999px` or `100%`) and checks for a nearby `<a>` tag. Pills are reserved for clickable CTAs; flagging non-CTA pills catches accidental use of button styling on offer badges, eyebrows, etc.

**Where it can produce false positives:** color palette enforcement is the most
likely. If an email legitimately needs a one-off color (a partner brand mention, a
photographic overlay tint), it'll get flagged as a warning. That's a signal to
either add the color to the style guide officially or accept the warning. The
script never blocks on style guide warnings; it just surfaces them.

**Located warnings:** color palette, border radius, font family, no-italics, and
pill-CTA-only all emit `line N: <snippet>` locations (up to 3 per finding) so you
can jump straight to the markup. A `›` marker inside the snippet points at the
exact match position.

---

### Module 5: Block fidelity — sections + components (`block_fidelity.py`)

**Why:** The repo has reusable HTML blocks: sections (composed blocks like header
and footer) in `design-libraries/sections/`, and components (primitives like the
button) in `design-libraries/components/`. The build is supposed to use them
verbatim with placeholders filled in — not rewrite or restyle them per email. This
module verifies that what shows up in the rendered email actually matches the source.

**How it knows what's a section vs. what's fresh campaign HTML:** by a CSS class
convention. Every `section_*.html` file includes `class="section-<name>"` on its
root element (e.g. `section_header.html` → `class="section-header"`). Every
`component_*.html` file includes `class="component-<name>"`. The build preserves
these classes in the rendered HTML, marking which blocks came from source files vs.
which were built fresh.

**What it does:**

*Source file checks (catches "I forgot the class" bugs at validation time):*
- Loads every `section_*.html` and `component_*.html` from their respective folders.
- Verifies each source file declares the right kind of class (`section-` for sections, `component-` for components).
- Verifies the class name in the file matches the filename (`section_header.html` should have `class="section-header"`).
- Errors on unreadable files or missing classes.

*Rendered email checks:*
- Scans the email HTML for every element with a `section-*` or `component-*` class.
- For each labeled block found, extracts its structural signature (ordered list of HTML tag names with counts) and compares it to the source file's signature.
- If the signatures match, it's `block.section_match.<name>` or `block.component_match.<name>` — the rendered block faithfully uses the source.
- If they differ, it's a `block.<kind>_drift.<name>` warning with a diagnostic: which tags were added or removed compared to the source.

*Position and uniqueness checks (sections only):*
- `section-header` must appear exactly once and be the first labeled section.
- `section-footer` must appear exactly once and be the last labeled section.
- Other sections (when they exist) have no count restriction — a feature row can repeat.

*Unknown blocks:*
- If the HTML has `class="section-hero"` but no `section_hero.html` source file exists, that's a warning — the build invented a class for a section that isn't formalized.

**What this module deliberately does NOT do:**
- It doesn't validate unlabeled HTML. Fresh campaign middle (hero, offer block, anything one-off) has no class to identify it, so there's no source to compare against. That's fine — `html_hygiene.py` and `style_guide.py` catch problems there.
- It doesn't enforce that everything must be a section or component. The build can mix labeled (reused) and unlabeled (fresh) HTML freely.

**Why the structural signature approach:** A literal HTML comparison would never
work because source files contain `[BRACKETED]` placeholders that get substituted
per email. Comparing tag sequences-and-counts is forgiving of placeholder content
(the values can vary) but catches drift in structure (added or removed tags,
reordered nesting). It's not as precise as a perfect tree diff, but it's
robust and fast.

**Located warnings:** `block.<kind>_drift.<name>` and `block.<kind>_unknown`
include the line number where the offending block starts, so you can find the
drifted section/component fast.

---

### Module 6: Brief reconciliation (`brief_reconciliation.py`)

**Why:** The brief is the source of truth for what a specific email says. If the
generated HTML and JSON drift from the brief — wrong price, wrong segment ID,
made-up product name — the brief lied to the team and the wrong thing went out.
This module is the "not making things up" check.

**What it does:**
- Parses the brief markdown's table rows to extract values from each section.
- **§9 reconciliation:** verifies the JSON's `messages.email.app_id` matches the brief's `Braze app_id`. Verifies the JSON's `from` matches the brief's `from` (catches the chat-mask leak again, plus any other divergence).
- **§10 reconciliation:** verifies the JSON's `segment_id` matches the brief's segment UUID.
- **§5 pricing reconciliation:** if pricing is shown, verifies `Original - Sale = Discount` mathematically. Then verifies both the original and sale prices actually appear in the rendered HTML body (forgetting to display a price you said you'd display is a real failure mode).
- **§3 headline check:** if the brief gave a specific headline (not `suggest`), verifies it appears in the HTML.

**Caveats:**
- The brief parser uses regex over markdown tables. If someone reformats a brief's tables (drops a `|`, renames a row), the parser may silently return `None` for that field and skip the related check. Made forgiving on purpose, but means a malformed brief degrades to "fewer checks run" rather than a loud failure. Worth knowing.
- Section-9 reconciliation only fires if both the brief and the JSON have the field. The JSON validator (Module 1) is what enforces presence.

---

### Module 7: W3C HTML validation — optional (`w3c.py`)

**Why:** A strict HTML5 conformance check. Catches structural HTML issues the
hygiene module's regex-based checks miss. Disabled by default because email HTML
legitimately violates HTML5 strict standards (Outlook-specific markup, table
attributes deprecated by HTML5 that email clients still require) — so the
value-to-noise ratio is lower than it looks. Worth turning on for an extra rigor
pass if you want it.

**What it does (when enabled):**
- POSTs the HTML to W3C's free web service at `validator.w3.org/nu` (default), or runs `vnu.jar` locally if you've configured a path, or runs `html5validator` if it's on PATH.
- Filters the validator output to drop expected email violations: VML namespaces (`v:roundrect`), MSO conditionals, `cellpadding`/`cellspacing`/`valign`/`align` attributes, `bgcolor`, embedded `<style>` tags in `<body>`.
- Reports any remaining issues as warnings (never errors — even when enabled, W3C is informational, not blocking).

**To enable:** set `enabled = true` in `config.ini` under `[w3c]`. The web service
default needs only internet; no installs.

---

## What it doesn't check

The harness catches **mechanical and factual** errors. It does NOT catch:

- **Editorial quality** — whether the copy is good, the layout feels right, the tone lands. Human review still required.
- **Image rendering** — whether hosted images actually load, whether they look right, whether they're the correct size. Open the email in a real inbox to check.
- **Email-client-specific rendering** — whether Outlook 2016 mangles your gradient, whether Gmail clips long emails. Use [Litmus](https://litmus.com) or [Email on Acid](https://www.emailonacid.com) for that.
- **Send actually reaching inboxes** — `/messages/send` returning `success` only means Braze accepted the request. Whether it landed depends on the segment, subscriptions, bounces, and the recipient's inbox. Check delivery in Braze itself.

---

## Workflow patterns

### Gate the curl behind validation

```bash
python3 test/validate.py && \
  curl -X POST "$BRAZE_REST_URL/messages/send" \
    -H "Content-Type: application/json" \
    -H "Authorization: Bearer $BRAZE_API_KEY" \
    --data @emails/<campaign>/send_test_body.json
```

The `&&` means curl only runs if validation passed. Simple guardrail; takes
nothing extra.

### Quiet mode for CI

Set `verbose_passed = false` in `config.ini` to print only warnings and errors.
Or pipe through grep:

```bash
python3 validate.py 2>&1 | grep -E "(WARN|ERR)"
```

### See every check

```bash
python3 validate.py --list-checks
```

Always reflects the current toolkit. If a check is in there, the validator can run
it. If something's not in there, the validator won't catch it — that's a signal to
either add a check or accept the gap.

---

## Honest limits worth knowing

**The validator is a regex/text-based tool, not a real HTML parser.** It works
because email HTML is structurally simple and our rule files have predictable
shapes. For more rigorous structural validation, see the optional W3C module.

**Every parser is a maintenance item.** If the brief format changes, the brief
reconciliation parser needs updating. If the style guide's tables get
restructured, the style guide parser needs updating. Each module attempts to
fail loud (emit errors, not silently skip) when its source file can't be parsed —
that's the `strict_rule_parsing` behavior. Pay attention to those errors; they
mean the validator needs sharpening, not that the email is broken.

**Drift between the validator and what the rules actually say is possible.** When
you add or change a rule, ask: "Does the validator know about this?" If it
doesn't, add a check. The `--list-checks` output is your inventory.

**False positives happen.** Especially in color palette, border radius, and font
family enforcement. The first runs on a new email will flag legitimate cases. The
fix is rule-by-rule: either tighten the validator, loosen the rule, or document
the exception. Don't suppress warnings blindly.
