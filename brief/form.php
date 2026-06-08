<?php
/**
 * Halo Email — Brief form
 * -----------------------------------------------------------------------------
 * Renders the email brief as an interactive form. On submit it builds a
 * filled-in markdown brief (same shape as brief_sample.md), writes it to
 * ../submissions/, and downloads it to the user.
 *
 * IMPORTANT: this is PHP — it must run on a PHP-capable host. GitHub Pages is
 * static and will NOT execute it. Deploy this file (and a writable
 * ../submissions/ folder) to your PHP server.
 * -----------------------------------------------------------------------------
 */

function field($k) { return isset($_POST[$k]) ? trim((string) $_POST[$k]) : ''; }

/** Flatten a value for safe use inside a single markdown table cell. */
function cell($s) {
    $s = trim($s);
    if ($s === '') return '';
    $s = preg_replace('/\s+/', ' ', $s);   // tables are single-line
    return str_replace('|', '\\|', $s);    // escape pipe so it doesn't break the table
}

$isPost     = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST');
$saveError  = null;
$savedName  = null;
$briefMd    = '';

if ($isPost) {
    // ---- filename: brief_<slug>_<YYYYMMDD>.md ----
    $campaign = field('campaign_name');
    $slug = strtolower($campaign !== '' ? $campaign : 'untitled');
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    $slug = trim($slug, '_');
    if ($slug === '') $slug = 'untitled';
    $savedName = 'brief_' . $slug . '_' . date('Ymd') . '.md';

    $title = ($campaign !== '') ? $campaign : '[Campaign Name]';

    // ---- build the filled brief (mirrors brief_sample.md) ----
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
| Sections to include | " . cell(field('sections_include')) . " |
| Anything to exclude | " . cell(field('exclude')) . " |

## 8. Notes for the builder

| Field | Your input |
|---|---|
| Notes | " . cell(field('notes')) . " |

## 9. Sender (for the Braze send)

| Field | Your input |
|---|---|
| Braze `app_id` | " . cell(field('braze_app_id')) . " |
| `from` | " . cell(field('from_sender')) . " |

## 10. Test targeting (segment)

| Field | Your input |
|---|---|
| Segment ID (UUID) | " . cell(field('segment_id')) . " |
";

    // ---- save to ../submissions/ ----
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

/** Markdown content for safe embedding in a <script> (drives the client download). */
$jsBrief = json_encode($briefMd, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);
$jsName  = json_encode($savedName, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
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
    --halo-gray-400: #a7b3bd;
    --halo-gray-300: #d3dce3;
    --halo-gray-200: #e2e9ee;
    --halo-gray-100: #f2f6f8;
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

  main { width: min(900px, calc(100% - 32px)); margin-inline: auto; padding: 40px 0 96px; }
  .lede { max-width: 60ch; color: var(--halo-gray-700); }
  h1 { font-size: 2rem; letter-spacing: -0.02em; margin: 0 0 8px; }

  form { margin-top: 8px; }
  fieldset {
    border: 1px solid var(--halo-gray-200); border-radius: var(--halo-radius-lg);
    padding: 20px 22px 8px; margin: 0 0 22px; background: var(--halo-gray-50);
  }
  legend {
    font-weight: 800; font-size: 1.05rem; padding: 0 8px;
    color: var(--halo-ink);
  }
  legend .num {
    display: inline-block; min-width: 1.4em; margin-right: 6px;
    color: var(--halo-blue); font-variant-numeric: tabular-nums;
  }
  .field { margin: 0 0 16px; }
  .field > label { display: block; font-weight: 650; font-size: 0.95rem; margin: 0 0 5px; }
  .field .hint { display: block; font-size: 0.82rem; color: var(--halo-gray-600); margin: 0 0 7px; font-weight: 400; }
  input[type=text], input[type=url], input[type=date], select, textarea {
    width: 100%; font: inherit; color: var(--halo-ink);
    padding: 10px 12px; background: var(--halo-white);
    border: 1px solid var(--halo-gray-300); border-radius: var(--halo-radius-md);
    transition: border-color 140ms, box-shadow 140ms;
  }
  textarea { min-height: 84px; resize: vertical; }
  input:focus, select:focus, textarea:focus {
    outline: none; border-color: var(--halo-blue);
    box-shadow: 0 0 0 3px rgba(47,147,243,0.18);
  }
  .req { color: #c0392b; }
  .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 18px; }
  .cta-row { display: grid; grid-template-columns: 1fr 1.4fr; gap: 0 18px; align-items: end; }
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

  .panel {
    border: 1px solid var(--halo-gray-200); border-radius: var(--halo-radius-lg);
    padding: 24px 26px; background: var(--halo-gray-50);
  }
  .panel.ok { border-color: #b7e0c2; background: #f3fbf5; }
  .panel.warn { border-color: #f1d59a; background: #fdf7ea; }
  .panel h2 { margin: 0 0 8px; font-size: 1.25rem; }
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
    </div>
  </header>

  <main>
<?php if ($isPost): ?>
    <div class="panel <?php echo $saveError ? 'warn' : 'ok'; ?>">
      <h2><?php echo $saveError ? '&#9888; Brief built — but not saved on the server' : '&#10003; Brief submitted'; ?></h2>
      <?php if ($saveError): ?>
        <p>Your download will still start below, but the copy could <strong>not</strong> be saved to <code>submissions/</code>:</p>
        <p><em><?php echo htmlspecialchars($saveError, ENT_QUOTES, 'UTF-8'); ?></em></p>
      <?php else: ?>
        <p>Saved to <code>submissions/<?php echo htmlspecialchars($savedName, ENT_QUOTES, 'UTF-8'); ?></code> and downloaded to your device.</p>
      <?php endif; ?>
      <div class="actions" style="margin-top:16px;">
        <button type="button" class="btn" id="dl">Download again</button>
        <a class="btn btn--ghost" href="form.php">Start a new brief</a>
      </div>
      <pre class="preview"><?php echo htmlspecialchars($briefMd, ENT_QUOTES, 'UTF-8'); ?></pre>
    </div>
    <script>
      (function () {
        var data = <?php echo $jsBrief; ?>;
        var name = <?php echo $jsName; ?>;
        function download() {
          var blob = new Blob([data], { type: 'text/markdown;charset=utf-8' });
          var url = URL.createObjectURL(blob);
          var a = document.createElement('a');
          a.href = url; a.download = name;
          document.body.appendChild(a); a.click(); a.remove();
          setTimeout(function () { URL.revokeObjectURL(url); }, 1500);
        }
        document.getElementById('dl').addEventListener('click', download);
        download(); // auto-start
      })();
    </script>
<?php else: ?>
    <h1>Email brief</h1>
    <p class="lede">Fill this in for one campaign. On submit it saves a copy to the team's
       <code>submissions/</code> folder and downloads a <code>brief_&lt;campaign&gt;.md</code>
       you can hand to the generator. Leave anything blank that doesn't apply — the generator
       asks about or defaults the rest.</p>

    <form method="post" action="form.php" autocomplete="off">

      <fieldset>
        <legend><span class="num">1</span>Campaign basics</legend>
        <div class="field">
          <label for="campaign_name">Campaign name <span class="req">*</span></label>
          <span class="hint">Used to name the file (e.g. "Mothers Day 2026").</span>
          <input type="text" id="campaign_name" name="campaign_name" required>
        </div>
        <div class="field">
          <label for="product_subject">Product / subject</label>
          <span class="hint">What the email is about.</span>
          <input type="text" id="product_subject" name="product_subject">
        </div>
        <div class="grid2">
          <div class="field">
            <label for="occasion">Occasion / theme</label>
            <span class="hint">Holiday, awareness month, flash sale, evergreen…</span>
            <input type="text" id="occasion" name="occasion">
          </div>
          <div class="field">
            <label for="send_date">Send date</label>
            <span class="hint">When it goes out.</span>
            <input type="date" id="send_date" name="send_date">
          </div>
        </div>
        <div class="field">
          <label for="primary_goal">Primary goal</label>
          <span class="hint">Drive purchase, re-engage, announce a feature…</span>
          <input type="text" id="primary_goal" name="primary_goal">
        </div>
      </fieldset>

      <fieldset>
        <legend><span class="num">2</span>Audience</legend>
        <div class="field">
          <label for="target_segment">Target segment</label>
          <span class="hint">Which segment this email is for (see rules_segment_definition.md). The segment shapes tone and offer.</span>
          <input type="text" id="target_segment" name="target_segment">
        </div>
      </fieldset>

      <fieldset>
        <legend><span class="num">3</span>Content</legend>
        <div class="grid2">
          <div class="field">
            <label for="headline">Headline</label>
            <span class="hint">Provide, or write "suggest".</span>
            <input type="text" id="headline" name="headline">
          </div>
          <div class="field">
            <label for="subhead">Subhead</label>
            <span class="hint">Provide, or write "suggest".</span>
            <input type="text" id="subhead" name="subhead">
          </div>
        </div>
        <div class="field">
          <label for="key_message">Key message or offer</label>
          <span class="hint">Body copy is AI-generated from this, the segment, and brand voice — there's no body field.</span>
          <textarea id="key_message" name="key_message"></textarea>
        </div>
      </fieldset>

      <fieldset>
        <legend><span class="num">4</span>Hero image</legend>
        <div class="field">
          <label for="hero_url">Hosted hero URL</label>
          <span class="hint">The live image URL the email links to.</span>
          <input type="url" id="hero_url" name="hero_url" placeholder="https://…">
        </div>
        <div class="grid2">
          <div class="field">
            <label for="reference_attached">Reference image attached?</label>
            <span class="hint">Attach the actual image separately so content can match the visual.</span>
            <select id="reference_attached" name="reference_attached">
              <option value="">—</option>
              <option value="Yes — attached separately">Yes — attached separately</option>
              <option value="No">No</option>
            </select>
          </div>
          <div class="field">
            <label for="alt_text">Alt text</label>
            <span class="hint">Describe the image for accessibility + Outlook fallback.</span>
            <input type="text" id="alt_text" name="alt_text">
          </div>
        </div>
      </fieldset>

      <fieldset>
        <legend><span class="num">5</span>Pricing</legend>
        <div class="field">
          <label for="show_pricing">Show pricing?</label>
          <select id="show_pricing" name="show_pricing">
            <option value="">—</option>
            <option value="yes">Yes</option>
            <option value="no">No</option>
          </select>
        </div>
        <div class="grid2">
          <div class="field">
            <label for="original_price">Original price</label>
            <span class="hint">Pre-discount / list price, if shown.</span>
            <input type="text" id="original_price" name="original_price">
          </div>
          <div class="field">
            <label for="sale_price">Sale / final price</label>
            <span class="hint">What the customer actually pays.</span>
            <input type="text" id="sale_price" name="sale_price">
          </div>
        </div>
        <div class="grid2">
          <div class="field">
            <label for="discount">Discount</label>
            <span class="hint">e.g. "$50 off" or "20% off" — must reconcile with the prices.</span>
            <input type="text" id="discount" name="discount">
          </div>
          <div class="field">
            <label for="promo_code">Promo code</label>
            <span class="hint">If any.</span>
            <input type="text" id="promo_code" name="promo_code">
          </div>
        </div>
      </fieldset>

      <fieldset>
        <legend><span class="num">6</span>Call to action</legend>
        <span class="hint" style="margin-bottom:12px;">Up to three. Leave a row blank to omit that CTA.</span>
        <div class="cta-row">
          <div class="field"><label for="cta1_label">CTA 1 label</label><input type="text" id="cta1_label" name="cta1_label" placeholder="Shop now"></div>
          <div class="field"><label for="cta1_dest">Destination</label><input type="text" id="cta1_dest" name="cta1_dest" placeholder="https://…"></div>
        </div>
        <div class="cta-row">
          <div class="field"><label for="cta2_label">CTA 2 label</label><input type="text" id="cta2_label" name="cta2_label"></div>
          <div class="field"><label for="cta2_dest">Destination</label><input type="text" id="cta2_dest" name="cta2_dest"></div>
        </div>
        <div class="cta-row">
          <div class="field"><label for="cta3_label">CTA 3 label</label><input type="text" id="cta3_label" name="cta3_label"></div>
          <div class="field"><label for="cta3_dest">Destination</label><input type="text" id="cta3_dest" name="cta3_dest"></div>
        </div>
      </fieldset>

      <fieldset>
        <legend><span class="num">7</span>Structure &amp; starting point</legend>
        <div class="field">
          <label for="template">Start from a template?</label>
          <select id="template" name="template">
            <option value="">—</option>
            <option value="newsletter">Newsletter</option>
            <option value="promo">Promo</option>
            <option value="none — build fresh">None — build fresh</option>
          </select>
        </div>
        <div class="field">
          <label for="sections_include">Sections to include</label>
          <span class="hint">In order — see the section vocabulary in the README.</span>
          <textarea id="sections_include" name="sections_include"></textarea>
        </div>
        <div class="field">
          <label for="exclude">Anything to exclude</label>
          <input type="text" id="exclude" name="exclude">
        </div>
      </fieldset>

      <fieldset>
        <legend><span class="num">8</span>Notes for the builder</legend>
        <div class="field">
          <label for="notes">Notes</label>
          <span class="hint">Anything specific to this send — leave blank if none.</span>
          <textarea id="notes" name="notes"></textarea>
        </div>
      </fieldset>

      <fieldset>
        <legend><span class="num">9</span>Sender (for the Braze send)</legend>
        <div class="field">
          <label for="braze_app_id">Braze <code>app_id</code></label>
          <span class="hint">App Identifier for the email app (Braze → Settings → APIs and Identifiers).</span>
          <input type="text" id="braze_app_id" name="braze_app_id">
        </div>
        <div class="field">
          <label for="from_sender"><code>from</code></label>
          <span class="hint">Formatted exactly as <code>Display Name &lt;email@example.com&gt;</code>.</span>
          <input type="text" id="from_sender" name="from_sender">
        </div>
      </fieldset>

      <fieldset>
        <legend><span class="num">10</span>Test targeting (segment)</legend>
        <div class="field">
          <label for="segment_id">Segment ID (UUID)</label>
          <span class="hint">The designated test segment in your non-production Braze workspace.</span>
          <input type="text" id="segment_id" name="segment_id">
        </div>
      </fieldset>

      <div class="actions">
        <button type="submit" class="btn">Save &amp; download brief</button>
      </div>
    </form>
<?php endif; ?>
  </main>
</body>
</html>
