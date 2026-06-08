<?php
/**
 * Halo Email — Brief form
 * -----------------------------------------------------------------------------
 * Renders the email brief as an interactive form (sections 1-8). On submit it
 * builds a filled-in markdown brief (same shape as brief_sample.md), with the
 * Sender (§9) and Test targeting (§10) values hardcoded, and writes it to
 * ../submissions/. It does NOT download anything — the file is just saved.
 *
 * IMPORTANT: this is PHP — it must run on a PHP-capable host. GitHub Pages is
 * static and will NOT execute it. Deploy this file (and a writable
 * ../submissions/ folder) to your PHP server.
 * -----------------------------------------------------------------------------
 */

/* --- hardcoded send config (§9, §10) — not editable in the form --- */
const BRAZE_APP_ID = '575c10a7-11d8-4494-afdc-a01e5a420cf4';
const BRAZE_FROM   = 'The Halo Team <thehaloteam@app.halocollar.com>';
const TEST_SEGMENT = '31c76280-df2d-40c4-b566-e29117768163';

/* --- documentation base (live docs site) --- */
const DOCS = 'https://topdecileinc.github.io/halo-resources/README.html';

function field($k) { return isset($_POST[$k]) ? trim((string) $_POST[$k]) : ''; }

/** Flatten a value for safe use inside a single markdown table cell. */
function cell($s) {
    $s = trim($s);
    if ($s === '') return '';
    $s = preg_replace('/\s+/', ' ', $s);   // tables are single-line
    return str_replace('|', '\\|', $s);    // escape pipe so it doesn't break the table
}

/** Selected section names (multi-select checkboxes) joined for the brief cell. */
function sections_value() {
    $s = (isset($_POST['sections']) && is_array($_POST['sections'])) ? $_POST['sections'] : array();
    $s = array_filter(array_map('trim', $s), function ($x) { return $x !== ''; });
    return cell(implode(', ', $s));
}

$isPost    = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST');
$saveError = null;
$savedName = null;
$briefMd   = '';

if ($isPost) {
    // ---- filename: brief_<slug>_<YYYYMMDD>.md ----
    $campaign = field('campaign_name');
    $slug = strtolower($campaign !== '' ? $campaign : 'untitled');
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    $slug = trim($slug, '_');
    if ($slug === '') $slug = 'untitled';
    $savedName = 'brief_' . $slug . '_' . date('Ymd') . '.md';

    $title = ($campaign !== '') ? $campaign : '[Campaign Name]';

    // ---- build the filled brief (mirrors brief_sample.md); §9 + §10 hardcoded ----
    $briefMd =
"---
---
# Email Brief — {$title}

## 1. Campaign basics

| Field | Your input |
|---|---|
| Product / subject | " . cell(field('product_subject')) . " |
| Campaign name | " . cell(field('campaign_name')) . " |
| Occasion / theme | " . cell(field('occasion')) . " |
| Send date | " . cell(field('send_date')) . " |
| Primary goal | " . cell(field('primary_goal')) . " |

## 2. Audience

| Field | Your input |
|---|---|
| Target segment | " . cell(field('target_segment')) . " |

## 3. Content

| Field | Your input |
|---|---|
| Headline | " . cell(field('headline')) . " |
| Subhead | " . cell(field('subhead')) . " |
| Key message or offer | " . cell(field('key_message')) . " |

## 4. Hero image

| Field | Your input |
|---|---|
| Hosted hero URL | " . cell(field('hero_url')) . " |
| Reference image attached? | " . cell(field('reference_attached')) . " |
| Alt text | " . cell(field('alt_text')) . " |

## 5. Pricing

| Field | Your input |
|---|---|
| Show pricing? | " . cell(field('show_pricing')) . " |
| Original price | " . cell(field('original_price')) . " |
| Sale / final price | " . cell(field('sale_price')) . " |
| Discount | " . cell(field('discount')) . " |
| Promo code | " . cell(field('promo_code')) . " |

## 6. Call to action

| # | CTA label | Destination |
|---|---|---|
| 1 | " . cell(field('cta1_label')) . " | " . cell(field('cta1_dest')) . " |
| 2 | " . cell(field('cta2_label')) . " | " . cell(field('cta2_dest')) . " |
| 3 | " . cell(field('cta3_label')) . " | " . cell(field('cta3_dest')) . " |

## 7. Structure & starting point

| Field | Your input |
|---|---|
| Start from a template? | " . cell(field('template')) . " |
| Sections to include | " . sections_value() . " |
| Anything to exclude | " . cell(field('exclude')) . " |

## 8. Notes for the builder

| Field | Your input |
|---|---|
| Notes | " . cell(field('notes')) . " |

## 9. Sender (for the Braze send)

| Field | Your input |
|---|---|
| Braze `app_id` | " . BRAZE_APP_ID . " |
| `from` | " . BRAZE_FROM . " |

> These are **not secrets**. The API key and Braze REST URL live in env vars on the
> runner's machine, not here — see the README's \"Sending via Braze (test sends)\" section.

## 10. Test targeting (segment)

Test sends target a designated test segment in a non-production Braze workspace.

| Field | Your input |
|---|---|
| Segment ID (UUID) | " . TEST_SEGMENT . " |
";

    // ---- save to ../submissions/ (no download — just save) ----
    $dir = __DIR__ . '/../submissions';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    if (is_dir($dir) && is_writable($dir)) {
        if (@file_put_contents($dir . '/' . $savedName, $briefMd) === false) {
            $saveError = 'The brief could not be written to submissions/ (write failed).';
        }
    } else {
        $saveError = 'The submissions/ folder is missing or not writable on this server.';
    }
}

/**
 * Render one labelled field: label + summary + a docs link + the control.
 * Requiring $summary and $anchor here guarantees no field ships without both.
 */
function fld($label, $for, $summary, $anchor, $control) {
    $doc = DOCS . '#' . $anchor;
    echo '<div class="field">';
    echo '<label for="' . $for . '">' . $label . '</label>';
    echo '<span class="hint">' . $summary
       . ' <a class="doc-link" href="' . $doc . '" target="_blank" rel="noopener">Docs &#8599;</a></span>';
    echo $control;
    echo '</div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Email brief form — Halo Email Resources</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;650;700;800&display=swap" rel="stylesheet">
<style>
  :root {
    --halo-font-sans: -apple-system, BlinkMacSystemFont, "Inter", "Segoe UI", Roboto, Arial, sans-serif;
    --halo-font-mono: "SF Mono", Menlo, Consolas, monospace;
    --halo-ink: #071826;
    --halo-gray-700: #354c5c;
    --halo-gray-600: #526777;
    --halo-gray-300: #d3dce3;
    --halo-gray-200: #e2e9ee;
    --halo-gray-50: #fbfcfd;
    --halo-white: #ffffff;
    --halo-yellow: #fcd62d;
    --halo-blue: #2f93f3;
    --halo-radius-md: 8px;
    --halo-radius-lg: 12px;
    --halo-radius-pill: 999px;
  }
  *, *::before, *::after { box-sizing: border-box; }
  body {
    margin: 0; background: var(--halo-white); color: var(--halo-ink);
    font-family: var(--halo-font-sans); font-size: 16px; line-height: 1.6;
    -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale;
  }
  a { color: var(--halo-blue); text-decoration: none; }
  a:hover { text-decoration: underline; }
  code { font-family: var(--halo-font-mono); font-size: 0.92em; }

  .halo-header {
    position: sticky; top: 0; z-index: 30;
    border-bottom: 1px solid var(--halo-gray-200);
    background: rgba(255,255,255,0.94);
    backdrop-filter: saturate(180%) blur(18px);
    -webkit-backdrop-filter: saturate(180%) blur(18px);
  }
  .halo-header__inner {
    width: min(900px, calc(100% - 32px)); margin-inline: auto; min-height: 64px;
    display: flex; align-items: center; gap: 16px;
  }
  .halo-header__inner img { width: 92px; height: auto; display: block; }
  .halo-header__tag { color: var(--halo-gray-600); font-weight: 800; letter-spacing: 0.04em; font-size: 0.9rem; }
  .halo-header__inner .spacer { flex: 1; }
  .halo-header__inner .home { font-weight: 650; font-size: 0.92rem; }
  .back { display: inline-block; margin-bottom: 16px; font-weight: 600; font-size: 0.9rem; }

  main { width: min(900px, calc(100% - 32px)); margin-inline: auto; padding: 40px 0 96px; }
  .lede { max-width: 62ch; color: var(--halo-gray-700); }
  h1 { font-size: 2rem; letter-spacing: -0.02em; margin: 0 0 8px; }

  form { margin-top: 8px; }
  fieldset {
    border: 1px solid var(--halo-gray-200); border-radius: var(--halo-radius-lg);
    padding: 20px 22px 8px; margin: 0 0 22px; background: var(--halo-gray-50);
  }
  legend { font-weight: 800; font-size: 1.05rem; padding: 0 8px; color: var(--halo-ink); }
  legend .num { display: inline-block; min-width: 1.4em; margin-right: 6px; color: var(--halo-blue); font-variant-numeric: tabular-nums; }
  .field { margin: 0 0 16px; }
  .field > label { display: block; font-weight: 650; font-size: 0.95rem; margin: 0 0 4px; }
  .field .hint { display: block; font-size: 0.82rem; color: var(--halo-gray-600); margin: 0 0 7px; font-weight: 400; }
  .field .doc-link { white-space: nowrap; font-weight: 600; }
  input[type=text], input[type=url], input[type=date], select, textarea {
    width: 100%; font: inherit; color: var(--halo-ink);
    padding: 10px 12px; background: var(--halo-white);
    border: 1px solid var(--halo-gray-300); border-radius: var(--halo-radius-md);
    transition: border-color 140ms, box-shadow 140ms;
  }
  textarea { min-height: 84px; resize: vertical; }
  input:focus, select:focus, textarea:focus {
    outline: none; border-color: var(--halo-blue); box-shadow: 0 0 0 3px rgba(47,147,243,0.18);
  }
  .checks { display: grid; gap: 8px; }
  .check {
    display: flex; align-items: center; gap: 10px; cursor: pointer;
    font-weight: 500; font-size: 0.92rem; background: var(--halo-white);
    border: 1px solid var(--halo-gray-300); border-radius: var(--halo-radius-md); padding: 9px 12px;
  }
  .check:hover { border-color: var(--halo-blue); }
  .check input { width: 16px; height: 16px; flex: none; cursor: pointer; }
  .check .opt-note { color: var(--halo-gray-600); font-weight: 400; }
  .req { color: #c0392b; }
  .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 18px; }
  .cta-row { display: grid; grid-template-columns: 1fr 1.4fr; gap: 0 18px; }
  @media (max-width: 560px) { .grid2, .cta-row { grid-template-columns: 1fr; } }

  .actions { margin-top: 8px; }
  .btn {
    display: inline-block; cursor: pointer; font: inherit; font-weight: 700;
    border: none; border-radius: var(--halo-radius-pill);
    padding: 14px 34px; background: var(--halo-yellow); color: #333;
    transition: transform 140ms, box-shadow 140ms;
  }
  .btn:hover { text-decoration: none; box-shadow: 0 6px 20px rgba(252,214,45,0.45); transform: translateY(-1px); }
  .btn--ghost { background: var(--halo-white); color: var(--halo-ink); border: 1px solid var(--halo-gray-300); }

  .panel { border: 1px solid var(--halo-gray-200); border-radius: var(--halo-radius-lg); padding: 24px 26px; background: var(--halo-gray-50); }
  .panel.ok { border-color: #b7e0c2; background: #f3fbf5; }
  .panel.warn { border-color: #f1d59a; background: #fdf7ea; }
  .panel h2 { margin: 0 0 8px; font-size: 1.25rem; }
  .hardcoded { font-size: 0.85rem; color: var(--halo-gray-600); border-left: 3px solid var(--halo-gray-300); padding: 2px 0 2px 12px; margin: 4px 0 0; }
  pre.preview {
    margin-top: 18px; max-height: 320px; overflow: auto;
    background: var(--halo-white); border: 1px solid var(--halo-gray-200);
    border-radius: var(--halo-radius-md); padding: 14px 16px;
    font-family: var(--halo-font-mono); font-size: 0.82rem; line-height: 1.5; white-space: pre-wrap;
  }
</style>
</head>
<body>
  <header class="halo-header">
    <div class="halo-header__inner">
      <img src="https://topdecileinc.github.io/halo-resources/email-design-system/assets/shared-images/halo-logo-dark.svg" alt="Halo">
      <span class="halo-header__tag">EMAIL BRIEF</span>
      <span class="spacer"></span>
      <a class="home" href="https://topdecileinc.github.io/halo-resources/">All resources</a>
    </div>
  </header>

  <main>
    <a class="back" href="https://topdecileinc.github.io/halo-resources/">&larr; Back to all resources</a>
<?php if ($isPost): ?>
    <div class="panel <?php echo $saveError ? 'warn' : 'ok'; ?>">
      <h2><?php echo $saveError ? '&#9888; Brief built — but not saved' : '&#10003; Brief saved'; ?></h2>
      <?php if ($saveError): ?>
        <p>The brief could <strong>not</strong> be saved to <code>submissions/</code>:</p>
        <p><em><?php echo htmlspecialchars($saveError, ENT_QUOTES, 'UTF-8'); ?></em></p>
        <p>Here is the brief so it isn't lost — copy it from below.</p>
      <?php else: ?>
        <p>Saved to <code>submissions/<?php echo htmlspecialchars($savedName, ENT_QUOTES, 'UTF-8'); ?></code>.</p>
      <?php endif; ?>
      <div class="actions" style="margin-top:16px;">
        <a class="btn btn--ghost" href="form.php">Start a new brief</a>
      </div>
      <pre class="preview"><?php echo htmlspecialchars($briefMd, ENT_QUOTES, 'UTF-8'); ?></pre>
    </div>
<?php else: ?>
    <h1>Email brief</h1>
    <p class="lede">Fill this in for one campaign. On submit it saves a copy to the team's
       <code>submissions/</code> folder. Leave anything blank that doesn't apply — the generator
       asks about or defaults the rest. Every field links to its documentation.</p>

    <form method="post" action="" autocomplete="off">

      <fieldset>
        <legend><span class="num">1</span>Campaign basics</legend>
        <?php
          fld('Campaign name <span class="req">*</span>', 'campaign_name',
              'A short name for this send — used to name the saved file (e.g. "Mothers Day 2026").',
              '1-campaign-basics',
              '<input type="text" id="campaign_name" name="campaign_name" required>');
          fld('Product / subject', 'product_subject',
              'What the email is about — the product, feature, or topic at its center.',
              '1-campaign-basics',
              '<input type="text" id="product_subject" name="product_subject">');
          fld('Occasion / theme', 'occasion',
              'The hook or timing — holiday, awareness month, flash sale, or evergreen.',
              '1-campaign-basics',
              '<input type="text" id="occasion" name="occasion">');
          fld('Send date', 'send_date',
              'The date this email is scheduled to go out.',
              '1-campaign-basics',
              '<input type="date" id="send_date" name="send_date">');
          fld('Primary goal', 'primary_goal',
              'The one outcome this email is for — drive a purchase, re-engage, or announce a feature.',
              '1-campaign-basics',
              '<input type="text" id="primary_goal" name="primary_goal">');
        ?>
      </fieldset>

      <fieldset>
        <legend><span class="num">2</span>Audience</legend>
        <?php
          fld('Target segment', 'target_segment',
              'Which defined audience segment this email targets. The segment shapes the angle, tone, and offer. Options come from rules_segment_definition.md.',
              '2-audience--segments',
              '<select id="target_segment" name="target_segment">'
              . '<option value="">—</option>'
              . '<option value="Acquisition">Acquisition</option>'
              . '<option value="Warm leads">Warm leads</option>'
              . '<option value="New customers">New customers</option>'
              . '<option value="Active / existing customers">Active / existing customers</option>'
              . '<option value="Lapsed">Lapsed</option>'
              . '<option value="Gold / premium members">Gold / premium members</option>'
              . '</select>');
        ?>
      </fieldset>

      <fieldset>
        <legend><span class="num">3</span>Content</legend>
        <?php
          fld('Headline', 'headline',
              'The main headline. Provide your own, or write "suggest" to have one generated.',
              '3-content',
              '<input type="text" id="headline" name="headline">');
          fld('Subhead', 'subhead',
              'The supporting line under the headline. Provide one, or write "suggest".',
              '3-content',
              '<input type="text" id="subhead" name="subhead">');
          fld('Key message or offer', 'key_message',
              'The core thing to communicate. Body copy is generated from this, the segment, and brand voice — there is no separate body field.',
              '3-content',
              '<textarea id="key_message" name="key_message"></textarea>');
        ?>
      </fieldset>

      <fieldset>
        <legend><span class="num">4</span>Hero image</legend>
        <?php
          fld('Hosted hero URL', 'hero_url',
              'The live, hosted URL of the hero image the email links to (not an upload).',
              '4-hero-image-hosted-url--reference-upload',
              '<input type="url" id="hero_url" name="hero_url" placeholder="https://…">');
          fld('Reference image attached?', 'reference_attached',
              'Whether you are attaching the actual image separately so the build can match the visual.',
              '4-hero-image-hosted-url--reference-upload',
              '<select id="reference_attached" name="reference_attached"><option value="">—</option><option value="Yes — attached separately">Yes — attached separately</option><option value="No">No</option></select>');
          fld('Alt text', 'alt_text',
              'A short description of the hero image for accessibility and Outlook fallback.',
              '4-hero-image-hosted-url--reference-upload',
              '<input type="text" id="alt_text" name="alt_text">');
        ?>
      </fieldset>

      <fieldset>
        <legend><span class="num">5</span>Pricing</legend>
        <?php
          fld('Show pricing?', 'show_pricing',
              'Whether this email displays pricing at all. Choose No to omit the price fields.',
              '5-pricing',
              '<select id="show_pricing" name="show_pricing"><option value="">—</option><option value="yes">Yes</option><option value="no">No</option></select>');
          fld('Original price', 'original_price',
              'The pre-discount / list price, if you are showing a strike-through.',
              '5-pricing',
              '<input type="text" id="original_price" name="original_price">');
          fld('Sale / final price', 'sale_price',
              'The price the customer actually pays.',
              '5-pricing',
              '<input type="text" id="sale_price" name="sale_price">');
          fld('Discount', 'discount',
              'How the discount reads (e.g. "$50 off" or "20% off") — must reconcile with the prices above.',
              '5-pricing',
              '<input type="text" id="discount" name="discount">');
          fld('Promo code', 'promo_code',
              'The promo code to display, if any.',
              '5-pricing',
              '<input type="text" id="promo_code" name="promo_code">');
        ?>
      </fieldset>

      <fieldset>
        <legend><span class="num">6</span>Call to action</legend>
        <?php
          fld('CTA 1 label', 'cta1_label',
              'The button text for the primary call to action (e.g. "Shop now").',
              '6-call-to-action',
              '<input type="text" id="cta1_label" name="cta1_label" placeholder="Shop now">');
          fld('CTA 1 destination', 'cta1_dest',
              'Where the primary button links — brand site, marketplace, or other URL.',
              '6-call-to-action',
              '<input type="text" id="cta1_dest" name="cta1_dest" placeholder="https://…">');
          fld('CTA 2 label', 'cta2_label',
              'Optional second CTA button text. Leave blank to omit this CTA.',
              '6-call-to-action',
              '<input type="text" id="cta2_label" name="cta2_label">');
          fld('CTA 2 destination', 'cta2_dest',
              'Where the second button links. Leave blank to omit this CTA.',
              '6-call-to-action',
              '<input type="text" id="cta2_dest" name="cta2_dest">');
          fld('CTA 3 label', 'cta3_label',
              'Optional third CTA button text. Leave blank to omit this CTA.',
              '6-call-to-action',
              '<input type="text" id="cta3_label" name="cta3_label">');
          fld('CTA 3 destination', 'cta3_dest',
              'Where the third button links. Leave blank to omit this CTA.',
              '6-call-to-action',
              '<input type="text" id="cta3_dest" name="cta3_dest">');
        ?>
      </fieldset>

      <fieldset>
        <legend><span class="num">7</span>Structure &amp; starting point</legend>
        <?php
          fld('Start from a template?', 'template',
              'Whether to base this on an existing template (newsletter / promo) or build fresh.',
              '7-structure--starting-point',
              '<select id="template" name="template"><option value="">—</option><option value="newsletter">Newsletter</option><option value="promo">Promo</option><option value="none — build fresh">None — build fresh</option></select>');
          fld('Sections to include', 'f_sections',
              'The middle blocks to include — header and footer are always added automatically. Anything custom: describe it in Notes (§8).',
              '7-structure--starting-point',
              '<div class="checks">'
              . '<label class="check"><input type="checkbox" id="f_sections" name="sections[]" value="hero"> Hero <span class="opt-note">&mdash; image + headline + subhead</span></label>'
              . '<label class="check"><input type="checkbox" name="sections[]" value="feature row"> Feature row <span class="opt-note">&mdash; icon + label + short copy</span></label>'
              . '<label class="check"><input type="checkbox" name="sections[]" value="offer / pricing"> Offer / pricing <span class="opt-note">&mdash; the deal: price, discount, code</span></label>'
              . '</div>');
          fld('Anything to exclude', 'exclude',
              'Any blocks or elements to deliberately leave out.',
              '7-structure--starting-point',
              '<input type="text" id="exclude" name="exclude">');
        ?>
      </fieldset>

      <fieldset>
        <legend><span class="num">8</span>Notes for the builder</legend>
        <?php
          fld('Notes', 'notes',
              'Anything specific to this send the builder should know — constraints, must-include lines, tone notes. Leave blank if none.',
              '8-notes-for-the-builder',
              '<textarea id="notes" name="notes"></textarea>');
        ?>
      </fieldset>

      <fieldset>
        <legend><span class="num">9 &amp; 10</span>Sender &amp; test targeting</legend>
        <p class="hint" style="margin-bottom:10px;">These are fixed for every send and are added to the brief automatically — you don't fill them in.</p>
        <p class="hardcoded">Braze <code>app_id</code>: <code><?php echo BRAZE_APP_ID; ?></code></p>
        <p class="hardcoded"><code>from</code>: <code><?php echo htmlspecialchars(BRAZE_FROM, ENT_QUOTES, 'UTF-8'); ?></code></p>
        <p class="hardcoded">Test segment ID: <code><?php echo TEST_SEGMENT; ?></code></p>
      </fieldset>

      <div class="actions">
        <button type="submit" class="btn">Save brief</button>
      </div>
    </form>
<?php endif; ?>
  </main>
</body>
</html>
