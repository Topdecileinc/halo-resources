<?php
/**
 * Halo Email — Claude pipeline (REST)
 * -----------------------------------------------------------------------------
 * Assembles the orchestrator + every rule file + sections/components into a
 * prompt-cached system prompt (read fresh from disk each call, so doc edits are
 * picked up automatically), calls the Claude Messages REST API with a structured
 * output schema, then optionally sends the result through Braze /messages/send.
 *
 * No SDK — raw HTTPS via curl, per the "use the REST API" requirement.
 * -----------------------------------------------------------------------------
 */

/** POST JSON, return [http_code, decoded_body, raw_body, curl_error]. */
function hp_post_json($url, array $headers, array $payload, $timeout) {
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = ($raw === false) ? null : json_decode($raw, true);
    return [$code, $decoded, $raw, $err];
}

/** Read a repo file and wrap it with a delimiter header. Returns '' if missing. */
function hp_file_block($repoRoot, $rel) {
    $p = $repoRoot . '/' . $rel;
    if (!is_file($p) || !is_readable($p)) return '';
    $body = @file_get_contents($p);
    if ($body === false) return '';
    // Strip Jekyll {% raw %} / {% endraw %} wrappers (used in the md only to display
    // literal Liquid like {{${...}}} on the docs site) so Claude sees the clean value.
    $body = preg_replace('/\{%-?\s*(?:end)?raw\s*-?%\}/', '', $body);
    return "\n===== FILE: {$rel} =====\n" . $body . "\n";
}

/** Sorted relative paths for a glob under repo root. */
function hp_glob_rel($repoRoot, $pattern) {
    $out = [];
    foreach ((glob($repoRoot . '/' . $pattern) ?: []) as $abs) {
        $out[] = ltrim(str_replace($repoRoot, '', $abs), '/');
    }
    sort($out);
    return $out;
}

/** Recursively find files whose basename matches $pattern under <repoRoot>/<subdir>.
    Depth-agnostic, so nested sections/components/examples are always found. Returns
    repo-relative paths. Falls back to a few glob depths if the iterator can't run. */
function hp_find_rel($repoRoot, $subdir, $pattern) {
    $base = $repoRoot . '/' . $subdir;
    $out = [];
    if (!is_dir($base)) return $out;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            if ($file->isFile() && fnmatch($pattern, $file->getFilename())) {
                $out[] = ltrim(str_replace($repoRoot, '', $file->getPathname()), '/');
            }
        }
    } catch (Exception $e) {
        foreach ([$subdir . '/' . $pattern, $subdir . '/*/' . $pattern, $subdir . '/*/*/' . $pattern] as $g) {
            foreach (hp_glob_rel($repoRoot, $g) as $r) $out[] = $r;
        }
    }
    sort($out);
    return array_values(array_unique($out));
}

/**
 * Build the stable system prompt: a short framing preamble + the orchestrator +
 * every rule file + sections/components/templates (+ examples). Files are discovered
 * RECURSIVELY by prefix, so depth/nesting doesn't matter. Read fresh each call.
 */
function hp_load_context(array $cfg) {
    $root = rtrim($cfg['repo_root'], '/');

    $rels = ['prompt_email_generation.md'];                       // orchestrator first
    foreach (['brand-guidelines', 'email-design-system', 'developer-skills'] as $d) {
        foreach (hp_find_rel($root, $d, 'rules_*.md') as $r) $rels[] = $r;          // rules at any depth
    }
    foreach (hp_find_rel($root, 'email-design-system/sections', 'section_*.html') as $r) $rels[] = $r;
    foreach (hp_find_rel($root, 'email-design-system/components', 'component_*.html') as $r) $rels[] = $r;
    foreach (hp_find_rel($root, 'email-design-system/templates', 'template_*.html') as $r) $rels[] = $r;
    if (!empty($cfg['include_examples'])) {
        foreach (hp_find_rel($root, 'email-examples', 'sample_*.html') as $r) $rels[] = $r;  // examples at any depth
    }
    $rels = array_values(array_unique($rels));

    $preamble =
        "You are the Halo email-building engine. The documents below are the orchestrator "
        . "(prompt_email_generation.md) followed by every binding resource it references: brand, "
        . "product, segment, style, copy, build, and footer rules, plus the reusable section and "
        . "component HTML and shipped examples. Follow the orchestrator and all rules_ files exactly. "
        . "Never invent product facts, prices, or claims not present in the brief or these resources.\n\n"
        . "FACT SOURCING (strict). Every factual statement in the email — product specs, model "
        . "differences, prices, battery life, GPS/satellite numbers, what is in the box, customer reviews / "
        . "quotes / ratings, and membership or plan details — MUST come from brand-guidelines/rules_brand.md "
        . "(its Technical Features, Social Proof, and Membership sections) and be relayed EXACTLY "
        . "as written there: verbatim numbers, names, and review quotes (you may trim a review without "
        . "changing its meaning, but never reword a fact). Do NOT take any spec, number, review, or product "
        . "claim from the example emails — the example emails are a reference for COPY TONE/VOICE and HTML "
        . "STRUCTURE ONLY, and may contain outdated facts. Do NOT invent, infer, round, update, or combine "
        . "facts. If a fact is not in those sections, leave it out. The ONLY copy you may write freely is "
        . "the connective SALES PITCH (headlines, subheads, and persuasive framing) in the brand voice — and "
        . "even that must not assert any spec, price, review, or claim that is not in brand-guidelines/rules_brand.md. "
        . "CURATE, DON'T DUMP: when a source file lists MORE items than a section needs (several membership "
        . "tiers, more specs than the grid holds, more reviews than there are cards), freely SELECT the few "
        . "that best fit this campaign and target segment, and relay each selected item verbatim — do not "
        . "pour the whole file into the email. The target segment and the §8 notes steer which to feature "
        . "(e.g. a Gold-member send features the Gold tier).\n\n"
        . "BUILD ONLY FROM WHAT EXISTS. Assemble the email exclusively from the section_ and "
        . "component_ HTML provided and the structures shown in the example emails. Reuse those "
        . "snippets as the literal building blocks: keep their markup and structure intact "
        . "(including class markers like class=\"section-header\"), and fill only the [BRACKETED] "
        . "placeholders. Do NOT invent new sections, components, layouts, or structural HTML that "
        . "does not appear in the provided sections/components or example emails. If the brief asks "
        . "for a block that has no matching snippet or example pattern, use the closest existing "
        . "pattern or omit it — never fabricate a new structure. Preserve the "
        . "formatting and whitespace of the snippets; do not minify or collapse the HTML. You ARE free to "
        . "ORDER and ARRANGE these blocks however reads best (and to place or repeat the CTA wherever it "
        . "fits) — reordering existing blocks is not inventing structure; only fabricating brand-new "
        . "structural HTML is forbidden.\n\n"
        . "AUTOMATION CONTEXT (overrides the orchestrator's 'ask the user about blanks' behavior): there "
        . "is NO human to ask — this pipeline runs unattended. When the brief leaves a field blank, "
        . "GENERATE suitable content for it from the available context (campaign name, key message, target "
        . "segment, the brand voice in rules_brand.md, and the example emails): write the subject, headline, "
        . "subhead, body copy, and image alt text yourself, and frame the product from what is given. Do NOT "
        . "ask questions and do NOT insert placeholder or 'to be confirmed' text. The only things you must "
        . "never invent are specific un-inferrable facts — exact prices and promo codes especially; if "
        . "pricing is not supplied, omit the pricing/offer block. For a CTA destination, default to the brand "
        . "site URL in rules_brand.md when the brief gives none. If the target segment is blank, write to a "
        . "general audience — a missing segment is not a blocker. Client-side rendering risks (e.g. a hero "
        . "image in a format some clients like Outlook may not support) are NOT blocking failures: build the "
        . "email and rely on the image's alt text as the fallback, and never refuse over a rendering risk. "
        . "Generated content for blank fields is expected behavior, not a rule violation.\n\n"
        . "MINIMAL BY DEFAULT. Include only the sections the brief asks for. When the brief's section "
        . "field (§7) names no sections, build the minimal email and nothing more: the header, the hero "
        . "image when a hero URL is given, the headline and subhead, the body copy (your sales pitch), any "
        . "CTA and pricing the brief actually provides, and the footer. Do NOT add a feature / specs row, a "
        . "model-comparison or spec grid, a reviews / social-proof block, or a membership section on your "
        . "own — those are OPT-IN and appear only when §7 names them. Having product facts available in "
        . "brand-guidelines/rules_brand.md does not mean you should showcase them: pull a fact only to support copy "
        . "that belongs in a section the brief actually asked for. ORDER IS YOUR CALL: arrange the included "
        . "blocks however reads best — the CTA can sit anywhere and may repeat — and only the header (first) "
        . "and footer (last) are fixed positions.\n\n"
        . "OUTPUT CONTRACT (overrides any output instruction in the orchestrator): return your result "
        . "ONLY as the structured object with keys `subject`, `preheader` (50-100 chars), and `html` "
        . "(the complete, production-ready, cross-client email HTML document). THE SUBJECT: the brief's "
        . "`Subject` field IS the email subject line — when it is filled in, use it as `subject` verbatim "
        . "(the user wrote or approved it; do not rewrite it). Only when the brief's Subject is blank, write "
        . "a subject for this campaign (occasion / offer / key message) in the brand voice — never the bare "
        . "product name. THE BODY: the brief's `Body copy` block is the email body — when it is filled in, "
        . "use that prose as the body text (place it into the email structure and format it; do not rewrite "
        . "or pad it). Only when Body copy is blank, write the body yourself. The HTML must be FINAL and "
        . "clean: no builder notes, no TODO / placeholder / 'to be confirmed' text, no internal comments "
        . "about the brief or your process, and no raw escape sequences (e.g. \\uXXXX) — every character "
        . "must be real, rendered content. If the brief does not supply an un-inferrable fact (a price, a "
        . "promo code), OMIT that block entirely rather than inserting placeholder text or inventing "
        . "a value. Do not output the Braze send JSON or any commentary — the surrounding system assembles "
        . "and sends it.\n";

    $context = $preamble;
    $loaded = [];
    foreach ($rels as $rel) {
        $block = hp_file_block($root, $rel);
        if ($block !== '') { $context .= $block; $loaded[] = $rel; }
    }
    return [$context, $loaded];
}

/** JSON schema forcing {subject, preheader, html}. */
function hp_output_schema() {
    return [
        'type' => 'json_schema',
        'schema' => [
            'type' => 'object',
            'properties' => [
                'subject'   => ['type' => 'string'],
                'preheader' => ['type' => 'string'],
                'html'      => ['type' => 'string'],
            ],
            'required' => ['subject', 'preheader', 'html'],
            'additionalProperties' => false,
        ],
    ];
}

/**
 * Generate the email via the Claude Messages API.
 * Returns ['ok'=>bool, 'error'=>?string, 'subject','preheader','html',
 *          'truncated'=>bool, 'usage'=>array, 'loaded'=>array].
 */
function hp_generate_email(array $cfg, $briefMarkdown, $feedback = '') {
    list($context, $loaded) = hp_load_context($cfg);

    $userText =
        "Here is the campaign brief. Build the production email now, following the orchestrator "
        . "and every rule file. Return the structured object (subject, preheader, html) and nothing "
        . "else.\n\n===== BRIEF =====\n" . $briefMarkdown;
    if ($feedback !== '') {
        $userText .= "\n\n===== REQUIRED FIXES =====\n"
            . "Your previous attempt FAILED these binding validation checks. Regenerate the email and "
            . "fix EVERY one of them without introducing new violations:\n" . $feedback;
    }

    $payload = [
        'model' => $cfg['anthropic_model'],
        'max_tokens' => (int) $cfg['max_tokens'],
        'thinking' => ['type' => 'adaptive'],
        'output_config' => [
            'effort' => $cfg['effort'],
            'format' => hp_output_schema(),
        ],
        'system' => [[
            'type' => 'text',
            'text' => $context,
            'cache_control' => ['type' => 'ephemeral', 'ttl' => '1h'],
        ]],
        'messages' => [['role' => 'user', 'content' => $userText]],
    ];

    $headers = [
        'content-type: application/json',
        'x-api-key: ' . $cfg['anthropic_api_key'],
        'anthropic-version: ' . ($cfg['anthropic_version'] ?? '2023-06-01'),
    ];
    $url = rtrim($cfg['anthropic_base_url'] ?? 'https://api.anthropic.com', '/') . '/v1/messages';

    list($code, $resp, $raw, $err) = hp_post_json($url, $headers, $payload, (int) $cfg['request_timeout']);

    if ($err !== '') return ['ok' => false, 'error' => "Network error calling Claude: $err"];
    if ($code !== 200) {
        $msg = is_array($resp) && isset($resp['error']['message']) ? $resp['error']['message'] : substr((string) $raw, 0, 500);
        return ['ok' => false, 'error' => "Claude API returned HTTP $code: $msg"];
    }

    $text = '';
    foreach ($resp['content'] ?? [] as $b) {
        if (($b['type'] ?? '') === 'text') $text .= $b['text'];
    }
    $data = json_decode($text, true);
    if (!is_array($data) || !isset($data['html'])) {
        return ['ok' => false, 'error' => 'Could not parse the structured email from Claude.', 'raw' => substr($text, 0, 500)];
    }

    return [
        'ok' => true,
        'error' => null,
        'subject' => (string) ($data['subject'] ?? ''),
        'preheader' => (string) ($data['preheader'] ?? ''),
        'html' => (string) $data['html'],
        'truncated' => (($resp['stop_reason'] ?? '') === 'max_tokens'),
        'usage' => $resp['usage'] ?? [],
        'loaded' => $loaded,
    ];
}

/** Build the Braze /messages/send body per rules_braze_send.md (no audience/campaign_id). */
function hp_braze_body(array $cfg, array $email) {
    return [
        'broadcast' => true,
        'segment_id' => $cfg['braze_segment_id'],
        'messages' => [
            'email' => [
                'app_id' => $cfg['braze_app_id'],
                'from' => $cfg['braze_from'],
                'subject' => $email['subject'],
                'preheader' => $email['preheader'],
                'body' => $email['html'],
            ],
        ],
    ];
}

/** POST a pre-built Braze /messages/send body. Returns ['attempted','ok','code','message']. */
function hp_braze_post(array $cfg, array $body) {
    $url = rtrim($cfg['braze_rest_url'], '/') . '/messages/send';
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $cfg['braze_api_key'],
    ];
    list($code, $resp, $raw, $err) = hp_post_json($url, $headers, $body, 120);
    if ($err !== '') return ['attempted' => true, 'ok' => false, 'code' => 0, 'message' => "Network error: $err"];
    $ok = ($code >= 200 && $code < 300);
    $msg = is_array($resp) && isset($resp['message']) ? $resp['message'] : substr((string) $raw, 0, 300);
    return ['attempted' => true, 'ok' => $ok, 'code' => $code, 'message' => $msg];
}

/** Send a freshly-built email through Braze. */
function hp_send_via_braze(array $cfg, array $email) {
    return hp_braze_post($cfg, hp_braze_body($cfg, $email));
}

/** Send a previously-generated email by its base id — reads generated/<base>.send.json. */
function hp_send_saved(array $cfg, $base) {
    $base = basename((string) $base);
    if (!preg_match('/^brief_[a-z0-9_]+_\d{8}(?:_\d{6})?$/', $base)) {
        return ['attempted' => false, 'ok' => false, 'code' => 0, 'message' => 'Invalid email id.'];
    }
    $jsonPath = rtrim($cfg['repo_root'], '/') . '/generated/' . $base . '.send.json';
    if (!is_file($jsonPath)) {
        return ['attempted' => false, 'ok' => false, 'code' => 0, 'message' => 'Generated email not found — please regenerate.'];
    }
    $body = json_decode(@file_get_contents($jsonPath), true);
    if (!is_array($body)) {
        return ['attempted' => false, 'ok' => false, 'code' => 0, 'message' => 'Could not read the saved send body.'];
    }
    return hp_braze_post($cfg, $body);
}

/** Load a generated email (subject/preheader/html) from generated/<base>.send.json, for preview. */
function hp_load_send_email(array $cfg, $base) {
    $base = basename((string) $base);
    if (!preg_match('/^brief_[a-z0-9_]+_\d{8}(?:_\d{6})?$/', $base)) return null;
    $jsonPath = rtrim($cfg['repo_root'], '/') . '/generated/' . $base . '.send.json';
    if (!is_file($jsonPath)) return null;
    $body = json_decode(@file_get_contents($jsonPath), true);
    $email = (is_array($body) && isset($body['messages']['email'])) ? $body['messages']['email'] : [];
    return [
        'subject' => $email['subject'] ?? '',
        'preheader' => $email['preheader'] ?? '',
        'html' => $email['body'] ?? '',
    ];
}

/** Recursively delete a directory. */
function hp_rrmdir($dir) {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $e) {
        if ($e === '.' || $e === '..') continue;
        $p = $dir . '/' . $e;
        is_dir($p) ? hp_rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

/**
 * Validate a generated email against ALL rules via test/validate.py. Writes a temp
 * campaign package (html + send json + brief), runs the validator on it, and returns
 * ['ok'=>bool, 'ran'=>bool, 'errors'=>string[], 'output'=>string]. Every check is
 * equal — any error fails the gate.
 */
function hp_validate(array $cfg, array $email, $briefMarkdown) {
    $root = rtrim($cfg['repo_root'], '/');
    $script = $root . '/test/validate.py';
    if (!is_file($script)) {
        return ['ok' => false, 'ran' => false, 'errors' => ['validator not found at test/validate.py'], 'output' => ''];
    }
    $tmp = rtrim(sys_get_temp_dir(), '/') . '/halo_val_' . uniqid('', true);
    $camp = $tmp . '/campaign';
    if (!@mkdir($camp, 0775, true)) {
        return ['ok' => false, 'ran' => false, 'errors' => ['could not create temp validation directory'], 'output' => ''];
    }
    @file_put_contents($camp . '/email.html', $email['html']);
    @file_put_contents($camp . '/send.json', json_encode(hp_braze_body($cfg, $email), JSON_UNESCAPED_SLASHES));
    @file_put_contents($camp . '/brief.md', $briefMarkdown);

    $py = $cfg['python_bin'] ?? 'python3';
    $cmd = escapeshellarg($py) . ' ' . escapeshellarg($script) . ' --emails ' . escapeshellarg($tmp) . ' 2>&1';
    $out = [];
    $code = 1;
    @exec($cmd, $out, $code);
    $text = implode("\n", $out);
    hp_rrmdir($tmp);

    // harness sanity — if it didn't actually check the package, fail safe (never pass)
    if (strpos($text, 'No campaign folders') !== false || strpos($text, 'No emails directory') !== false) {
        return ['ok' => false, 'ran' => false, 'errors' => ['the validator did not run on the package (path/harness issue)'], 'output' => $text];
    }
    if (empty($out)) {
        return ['ok' => false, 'ran' => false, 'errors' => ['could not run the validator (is Python on the host? check python_bin)'], 'output' => $text];
    }

    $errors = [];
    foreach ($out as $line) {
        if (strpos($line, '[ERR]') !== false) {
            $errors[] = trim(preg_replace('/^\\s*\\[ERR\\]\\s*/', '', $line));
        }
    }
    return ['ok' => ($code === 0), 'ran' => true, 'errors' => $errors, 'output' => $text];
}

/**
 * Full pipeline: generate → validate (ALL rules) → retry once on failure →
 * send only on a pass. After two failed attempts it does NOT send and returns the
 * validation errors. Saves the last attempt's output for review either way.
 */
function hp_save_generated(array $cfg, array $gen, $base) {
    if (empty($cfg['repo_root'])) return;
    $genDir = rtrim($cfg['repo_root'], '/') . '/generated';
    if (!is_dir($genDir)) @mkdir($genDir, 0775, true);
    @file_put_contents($genDir . '/' . $base . '.html', $gen['html']);
    @file_put_contents($genDir . '/' . $base . '.send.json', json_encode(hp_braze_body($cfg, $gen), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/**
 * AI backstop review: a second model pass over the generated email against the SAME
 * rule context, catching the judgment/semantic things the deterministic validator
 * can't (tone, segment fit, claim/price accuracy, offer treatment, snippet reuse).
 * Returns ['ok'=>bool, 'violations'=>string[], 'error'=>?string]. A set 'error' is an
 * infrastructure failure (caller fails closed); violations with no error are content failures.
 */
function hp_ai_review(array $cfg, array $email, $briefMarkdown) {
    list($context, ) = hp_load_context($cfg);
    $userText =
        "You are a STRICT compliance reviewer — not the author. Above are the orchestrator and every "
        . "binding rule file and example. Below is a generated email (HTML) and the brief it was built "
        . "from. Check the email against EVERY rule, emphasizing what a mechanical checker cannot verify: "
        . "voice/tone, segment fit, accuracy of any claim or price, the offer/discount treatment, and "
        . "whether the structure reuses the provided sections/components and example patterns rather than "
        . "inventing new ones. FACT SOURCING: every product spec, number, price, review quote/rating, and "
        . "membership detail in the email must trace VERBATIM to brand-guidelines/rules_brand.md "
        . "(its Technical Features, Social Proof, and Membership sections); flag any fact that is "
        . "invented, altered, or lifted from the example emails (examples are tone/HTML reference only). "
        . "List EVERY rule it violates, each as one short specific line naming the rule "
        . "and what is wrong. If it fully complies, return pass=true with an empty violations list. Do not "
        . "invent violations or restate passing rules.\n\n"
        . "===== BRIEF =====\n" . $briefMarkdown . "\n\n===== EMAIL HTML =====\n" . $email['html'];

    $schema = ['type' => 'json_schema', 'schema' => [
        'type' => 'object',
        'properties' => [
            'pass' => ['type' => 'boolean'],
            'violations' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['pass', 'violations'],
        'additionalProperties' => false,
    ]];

    $payload = [
        'model' => $cfg['anthropic_model'],
        'max_tokens' => 8000,
        'thinking' => ['type' => 'adaptive'],
        'output_config' => ['effort' => $cfg['effort'], 'format' => $schema],
        'system' => [['type' => 'text', 'text' => $context, 'cache_control' => ['type' => 'ephemeral', 'ttl' => '1h']]],
        'messages' => [['role' => 'user', 'content' => $userText]],
    ];
    $headers = [
        'content-type: application/json',
        'x-api-key: ' . $cfg['anthropic_api_key'],
        'anthropic-version: ' . ($cfg['anthropic_version'] ?? '2023-06-01'),
    ];
    $url = rtrim($cfg['anthropic_base_url'] ?? 'https://api.anthropic.com', '/') . '/v1/messages';

    list($code, $resp, $raw, $err) = hp_post_json($url, $headers, $payload, (int) $cfg['request_timeout']);
    if ($err !== '') return ['ok' => false, 'violations' => [], 'error' => "AI review network error: $err"];
    if ($code !== 200) {
        $msg = is_array($resp) && isset($resp['error']['message']) ? $resp['error']['message'] : substr((string) $raw, 0, 300);
        return ['ok' => false, 'violations' => [], 'error' => "AI review API HTTP $code: $msg"];
    }
    $text = '';
    foreach ($resp['content'] ?? [] as $b) {
        if (($b['type'] ?? '') === 'text') $text .= $b['text'];
    }
    $data = json_decode($text, true);
    if (!is_array($data) || !array_key_exists('violations', $data)) {
        return ['ok' => false, 'violations' => [], 'error' => 'Could not parse the AI review result.'];
    }
    $violations = array_values(array_filter(array_map('strval', (array) $data['violations']), function ($v) { return trim($v) !== ''; }));
    return ['ok' => empty($violations), 'violations' => $violations, 'error' => null];
}

/**
 * Full pipeline. The HARD GATE is the deterministic validator only — it reads your
 * md rule files and compares, no guessing. Each attempt: generate → validate →
 * retry ONCE on failure (feeding the exact [ERR]s back) → a second failure does NOT
 * send. The AI review is ADVISORY by default (ai_review_blocking=false): it runs once
 * after the deterministic gate passes and its findings are surfaced for review, but
 * they do NOT block the send. Set ai_review_blocking=true to make it a hard gate too.
 */
function hp_run_pipeline(array $cfg, $briefMarkdown, $base) {
    $validate   = !array_key_exists('validate', $cfg)  || !empty($cfg['validate']);
    $aiReview   = !array_key_exists('ai_review', $cfg) || !empty($cfg['ai_review']);
    $aiBlocking = !empty($cfg['ai_review_blocking']);   // default: AI is advisory, not a gate
    $maxAttempts = 2;          // first try + one retry
    $feedback = '';
    $attempts = 0;
    $gen = null;
    $derrors = [];
    $advisories = [];
    $aiError = null;

    while ($attempts < $maxAttempts) {
        $attempts++;
        $gen = hp_generate_email($cfg, $briefMarkdown, $feedback);
        if (empty($gen['ok'])) {
            return ['ok' => false, 'stage' => 'generate', 'error' => $gen['error'] ?? 'Generation failed',
                    'attempts' => $attempts, 'send' => null];
        }

        $derrors = [];
        $advisories = [];
        $aiError = null;

        // HARD GATE — deterministic validator (every rule, md-driven values, no guessing).
        if ($validate) {
            $val = hp_validate($cfg, $gen, $briefMarkdown);
            if (empty($val['ok'])) $derrors = $val['errors'];
        }

        // AI review — runs once the deterministic gate passes. Advisory unless ai_review_blocking.
        if (empty($derrors) && $aiReview) {
            $rev = hp_ai_review($cfg, $gen, $briefMarkdown);
            if (!empty($rev['error'])) $aiError = $rev['error'];   // infra issue (note it; only blocks if blocking-mode)
            else $advisories = $rev['violations'];
        }

        // What actually blocks/triggers a retry:
        $blocking = $derrors;
        if ($aiBlocking) $blocking = array_merge($derrors, $advisories);

        if (empty($blocking)) break;                              // passed the hard gate(s)
        $feedback = '- ' . implode("\n- ", $blocking);
    }

    hp_save_generated($cfg, $gen, $base);

    // AI infra error only blocks in blocking mode.
    if ($aiBlocking && $aiError) {
        return ['ok' => false, 'stage' => 'ai_review',
                'error' => 'AI review pass could not run — not sent: ' . $aiError,
                'attempts' => $attempts, 'html_file' => $base . '.html', 'json_file' => $base . '.send.json', 'send' => null];
    }

    $blocking = $derrors;
    if ($aiBlocking) $blocking = array_merge($derrors, $advisories);

    // Hard-gate failure on the final attempt → DO NOT send.
    if (!empty($blocking)) {
        return [
            'ok' => false,
            'stage' => 'validate',
            'validation_failed' => true,
            'attempts' => $attempts,
            'errors' => $blocking,
            'subject' => $gen['subject'],
            'preheader' => $gen['preheader'],
            'loaded' => $gen['loaded'] ?? [],
            'html_file' => $base . '.html',
            'json_file' => $base . '.send.json',
            'send' => null,
        ];
    }

    // Passed the hard gate → ready for PREVIEW. Sending is now a separate manual step
    // (the preview page's "Send" button calls hp_send_saved with the base id).
    return [
        'ok' => true,
        'error' => null,
        'attempts' => $attempts,
        'validated' => $validate,
        'ai_reviewed' => $aiReview,
        'advisories' => $aiBlocking ? [] : $advisories,
        'ai_error' => $aiBlocking ? null : $aiError,
        'subject' => $gen['subject'],
        'preheader' => $gen['preheader'],
        'html' => $gen['html'],
        'base' => $base,
        'truncated' => !empty($gen['truncated']),
        'usage' => $gen['usage'],
        'loaded' => $gen['loaded'],
        'html_file' => $base . '.html',
        'json_file' => $base . '.send.json',
    ];
}

/**
 * Revise an existing email per a free-text instruction (e.g. "make the CTA green",
 * "shorten the intro"). Same return shape as hp_generate_email. The model gets the
 * current email + the change request (+ the original brief for grounding) and returns
 * the COMPLETE revised email, still bound by every rule.
 */
function hp_edit_email(array $cfg, array $current, $instruction, $briefMarkdown = '', $feedback = '') {
    list($context, $loaded) = hp_load_context($cfg);

    $userText =
        "You are REVISING an existing Halo email. Apply the requested change and return the COMPLETE "
        . "revised email as the structured object (subject, preheader, html). Change only what the request "
        . "asks for (plus whatever that change requires to keep the email correct and consistent); keep "
        . "everything else as-is. Keep following every rule: product facts verbatim from the rule files, no "
        . "em dashes, table-based Outlook-safe HTML, CTA-only pill styling, and the existing section/component "
        . "structures.\n\n===== REQUESTED CHANGE =====\n" . $instruction
        . "\n\n===== CURRENT EMAIL =====\nSubject: " . $current['subject'] . "\nPreheader: " . $current['preheader']
        . "\nHTML:\n" . $current['html'];
    if ($briefMarkdown !== '') $userText .= "\n\n===== ORIGINAL BRIEF (for grounding) =====\n" . $briefMarkdown;
    if ($feedback !== '') {
        $userText .= "\n\n===== REQUIRED FIXES =====\nYour previous revision FAILED these binding checks; "
            . "fix EVERY one without introducing new violations:\n" . $feedback;
    }

    $payload = [
        'model' => $cfg['anthropic_model'],
        'max_tokens' => (int) $cfg['max_tokens'],
        'thinking' => ['type' => 'adaptive'],
        'output_config' => ['effort' => $cfg['effort'], 'format' => hp_output_schema()],
        'system' => [['type' => 'text', 'text' => $context, 'cache_control' => ['type' => 'ephemeral', 'ttl' => '1h']]],
        'messages' => [['role' => 'user', 'content' => $userText]],
    ];
    $headers = [
        'content-type: application/json',
        'x-api-key: ' . $cfg['anthropic_api_key'],
        'anthropic-version: ' . ($cfg['anthropic_version'] ?? '2023-06-01'),
    ];
    $url = rtrim($cfg['anthropic_base_url'] ?? 'https://api.anthropic.com', '/') . '/v1/messages';

    list($code, $resp, $raw, $err) = hp_post_json($url, $headers, $payload, (int) $cfg['request_timeout']);
    if ($err !== '') return ['ok' => false, 'error' => "Network error calling Claude: $err"];
    if ($code !== 200) {
        $msg = is_array($resp) && isset($resp['error']['message']) ? $resp['error']['message'] : substr((string) $raw, 0, 500);
        return ['ok' => false, 'error' => "Claude API returned HTTP $code: $msg"];
    }
    $text = '';
    foreach ($resp['content'] ?? [] as $b) {
        if (($b['type'] ?? '') === 'text') $text .= $b['text'];
    }
    $data = json_decode($text, true);
    if (!is_array($data) || !isset($data['html'])) {
        return ['ok' => false, 'error' => 'Could not parse the revised email from Claude.', 'raw' => substr($text, 0, 500)];
    }
    return [
        'ok' => true, 'error' => null,
        'subject' => (string) ($data['subject'] ?? ''),
        'preheader' => (string) ($data['preheader'] ?? ''),
        'html' => (string) $data['html'],
        'truncated' => (($resp['stop_reason'] ?? '') === 'max_tokens'),
        'usage' => $resp['usage'] ?? [],
        'loaded' => $loaded,
    ];
}

/**
 * Apply an AI edit to a previously-generated email (chosen on the preview) and re-validate.
 * Loads generated/<base>.* and submissions/<base>.md, revises, runs the SAME hard gate as
 * generation (retry once), and OVERWRITES the same base in place (refine, not a new copy).
 * Returns the same shape as hp_run_pipeline so the preview view renders it identically.
 */
function hp_edit_pipeline(array $cfg, $base, $instruction) {
    $base = basename((string) $base);
    if (!preg_match('/^brief_[a-z0-9_]+_\d{8}(?:_\d{6})?$/', $base)) {
        return ['ok' => false, 'error' => 'Invalid email id to edit.'];
    }
    $instruction = trim((string) $instruction);
    if ($instruction === '') return ['ok' => false, 'error' => 'No edit instruction was given.'];

    $cur = hp_load_send_email($cfg, $base);
    if (!$cur || ($cur['html'] ?? '') === '') {
        return ['ok' => false, 'error' => 'Could not load the email to edit (is it still in generated/?).'];
    }
    $briefPath = rtrim($cfg['repo_root'], '/') . '/submissions/' . $base . '.md';
    $briefMd = is_file($briefPath) ? (string) @file_get_contents($briefPath) : '';

    $validate   = !array_key_exists('validate', $cfg)  || !empty($cfg['validate']);
    $aiReview   = !array_key_exists('ai_review', $cfg) || !empty($cfg['ai_review']);
    $aiBlocking = !empty($cfg['ai_review_blocking']);
    $maxAttempts = 2; $feedback = ''; $attempts = 0; $gen = null; $derrors = []; $advisories = []; $aiError = null;

    while ($attempts < $maxAttempts) {
        $attempts++;
        $gen = hp_edit_email($cfg, $cur, $instruction, $briefMd, $feedback);
        if (empty($gen['ok'])) {
            return ['ok' => false, 'stage' => 'generate', 'error' => $gen['error'] ?? 'Edit failed', 'attempts' => $attempts, 'send' => null];
        }
        $derrors = []; $advisories = []; $aiError = null;
        if ($validate) {
            $val = hp_validate($cfg, $gen, $briefMd);
            if (empty($val['ok'])) $derrors = $val['errors'];
        }
        if (empty($derrors) && $aiReview) {
            $rev = hp_ai_review($cfg, $gen, $briefMd);
            if (!empty($rev['error'])) $aiError = $rev['error'];
            else $advisories = $rev['violations'];
        }
        $blocking = $derrors;
        if ($aiBlocking) $blocking = array_merge($derrors, $advisories);
        if (empty($blocking)) break;
        $feedback = '- ' . implode("\n- ", $blocking);
    }

    hp_save_generated($cfg, $gen, $base);   // overwrite the same base — refine in place

    if ($aiBlocking && $aiError) {
        return ['ok' => false, 'stage' => 'ai_review', 'error' => 'AI review could not run — edit not finalized: ' . $aiError,
                'attempts' => $attempts, 'html_file' => $base . '.html', 'json_file' => $base . '.send.json', 'send' => null];
    }
    $blocking = $derrors;
    if ($aiBlocking) $blocking = array_merge($derrors, $advisories);
    if (!empty($blocking)) {
        return ['ok' => false, 'stage' => 'validate', 'validation_failed' => true, 'attempts' => $attempts, 'errors' => $blocking,
                'subject' => $gen['subject'], 'preheader' => $gen['preheader'], 'loaded' => $gen['loaded'] ?? [],
                'html_file' => $base . '.html', 'json_file' => $base . '.send.json', 'send' => null];
    }
    return [
        'ok' => true, 'error' => null, 'attempts' => $attempts, 'validated' => $validate, 'ai_reviewed' => $aiReview,
        'advisories' => $aiBlocking ? [] : $advisories, 'ai_error' => $aiBlocking ? null : $aiError,
        'subject' => $gen['subject'], 'preheader' => $gen['preheader'], 'html' => $gen['html'], 'base' => $base,
        'truncated' => !empty($gen['truncated']), 'usage' => $gen['usage'], 'loaded' => $gen['loaded'],
        'edited' => true, 'html_file' => $base . '.html', 'json_file' => $base . '.send.json',
    ];
}

/** Structured-output schema for a drafted brief — property names match the form fields.
    Segment + sections are constrained to the valid options passed in. */
function hp_brief_fields_schema(array $segEnum, array $secEnum) {
    $props = [
        'campaign_name'   => ['type' => 'string', 'description' => 'A short name to identify the brief (NOT shown in the email).'],
        'subject'         => ['type' => 'string', 'description' => 'The email subject line — draft a short, campaign-specific subject in brand voice (occasion / offer / message), NOT the product name.'],
        'occasion'        => ['type' => 'string'],
        'send_date'       => ['type' => 'string'],
        'primary_goal'    => ['type' => 'string'],
        'target_segment'  => ['type' => 'string', 'enum' => $segEnum],
        'headline'        => ['type' => 'string', 'description' => 'Draft a headline in brand voice (sentence case, no em dashes).'],
        'subhead'         => ['type' => 'string', 'description' => 'Draft a supporting subhead.'],
        'body'            => ['type' => 'string', 'description' => 'Draft the email BODY COPY — a few short paragraphs in the brand voice, stating the offer/price in plain terms. The user edits it and the email uses it.'],
        'hero_url'        => ['type' => 'string', 'description' => 'Leave blank unless an image URL was actually given.'],
        'alt_text'        => ['type' => 'string', 'description' => 'Leave blank unless a hero image was actually given — no image means no alt text.'],
        'show_pricing'    => ['type' => 'string', 'enum' => ['', 'yes', 'no']],
        'original_price'  => ['type' => 'string', 'description' => 'Only if a price was stated.'],
        'sale_price'      => ['type' => 'string', 'description' => 'Only if a price was stated.'],
        'discount'        => ['type' => 'string', 'description' => 'Only if stated, e.g. "$50 off".'],
        'promo_code'      => ['type' => 'string', 'description' => 'Only if a code was given.'],
        'cta1_label'      => ['type' => 'string'],
        'cta1_dest'       => ['type' => 'string', 'description' => 'Leave blank unless a URL was given.'],
        'cta2_label'      => ['type' => 'string'],
        'cta2_dest'       => ['type' => 'string'],
        'cta3_label'      => ['type' => 'string'],
        'cta3_dest'       => ['type' => 'string'],
        'template'        => ['type' => 'string', 'enum' => ['', 'newsletter', 'promo', 'none — build fresh']],
        'sections'        => ['type' => 'array', 'items' => ($secEnum ? ['type' => 'string', 'enum' => $secEnum] : ['type' => 'string'])],
        'exclude'         => ['type' => 'string'],
        'notes'           => ['type' => 'string', 'description' => 'Leave BLANK unless the vision states a specific build constraint that does not fit any other field (e.g. "keep under 100 words", "must mention the warranty"). Do NOT restate the vision or repeat other fields here.'],
        'rationale'       => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'One short, plain-language line per field you filled (or deliberately left blank), explaining WHY — written for a non-technical reviewer. Prefix each with the field name, e.g. "Subject: leaned into Father\'s Day since the vision named it." / "Pricing: left blank — no price was given."'],
    ];
    return ['type' => 'json_schema', 'schema' => [
        'type' => 'object',
        'properties' => $props,
        'required' => array_keys($props),
        'additionalProperties' => false,
    ]];
}

/**
 * Turn a free-text "campaign vision" into a drafted brief (field => value), to PRE-FILL the
 * form for review. Fills as much as it reasonably can — including drafting headline/subhead/
 * key message in the brand voice — but never invents hard facts (prices, promo codes, hero
 * URL, CTA URLs) the user didn't give. Returns ['ok'=>bool,'fields'=>array,'error'=>?string].
 */
function hp_parse_brief(array $cfg, $vision, array $segments = array(), array $sections = array()) {
    list($context, $loaded) = hp_load_context($cfg);

    $segList = array_values(array_filter(array_map('strval', $segments), function ($s) { return trim($s) !== ''; }));
    $secList = array_values(array_filter(array_map('strval', $sections), function ($s) { return trim($s) !== ''; }));
    $segEnum = array_merge([''], $segList);

    $userText =
        "A user described an email campaign in free text. Turn it into a filled-in BRIEF: extract what they "
        . "stated and DRAFT the rest in the Halo brand voice, following every copy/brand rule (warm and "
        . "inviting, sentence case, no em dashes). Fill in as MANY fields as you reasonably can — especially "
        . "draft the subject, headline, subhead, and body so they are NOT empty (the user reviews and "
        . "edits them before generating). The subject is the email subject line for THIS campaign (its "
        . "occasion / offer), NOT the product name. The `body` is the email BODY COPY — a few short "
        . "paragraphs, not a one-line summary. "
        . "Choose target_segment ONLY from " . json_encode($segEnum) . " (blank if unclear). "
        . "Choose sections ONLY from " . json_encode($secList) . ", and only when the vision implies them. "
        . "Do NOT invent hard facts the user did not give: leave exact prices, the promo code, the hero image "
        . "URL and its alt text, and CTA destination URLs blank unless they were stated (no image means no "
        . "alt text). Give the brief a short campaign_name. "
        . "Leave `notes` BLANK unless the vision has a specific build constraint that fits no other field "
        . "(e.g. a length limit or a must-include line); the vision's intent already lives in the real fields, "
        . "so do not restate it in notes. "
        . "Also fill `rationale`: a short, plain-language line for each field you filled (or deliberately left "
        . "blank), explaining WHY you chose it — so a non-technical reviewer understands the draft. "
        . "Return ONLY the structured object.\n\n===== CAMPAIGN VISION =====\n" . $vision;

    $payload = [
        'model' => $cfg['anthropic_model'],
        'max_tokens' => 4000,
        'thinking' => ['type' => 'adaptive'],
        'output_config' => ['effort' => $cfg['effort'], 'format' => hp_brief_fields_schema($segEnum, $secList)],
        'system' => [['type' => 'text', 'text' => $context, 'cache_control' => ['type' => 'ephemeral', 'ttl' => '1h']]],
        'messages' => [['role' => 'user', 'content' => $userText]],
    ];
    $headers = [
        'content-type: application/json',
        'x-api-key: ' . $cfg['anthropic_api_key'],
        'anthropic-version: ' . ($cfg['anthropic_version'] ?? '2023-06-01'),
    ];
    $url = rtrim($cfg['anthropic_base_url'] ?? 'https://api.anthropic.com', '/') . '/v1/messages';

    list($code, $resp, $raw, $err) = hp_post_json($url, $headers, $payload, (int) $cfg['request_timeout']);
    if ($err !== '') return ['ok' => false, 'error' => "Network error calling Claude: $err"];
    if ($code !== 200) {
        $msg = is_array($resp) && isset($resp['error']['message']) ? $resp['error']['message'] : substr((string) $raw, 0, 400);
        return ['ok' => false, 'error' => "Claude API returned HTTP $code: $msg"];
    }
    $text = '';
    foreach ($resp['content'] ?? [] as $b) {
        if (($b['type'] ?? '') === 'text') $text .= $b['text'];
    }
    $data = json_decode($text, true);
    if (!is_array($data)) return ['ok' => false, 'error' => 'Could not read the drafted brief from Claude.'];

    // pull the rationale out (it's not a form field) before normalizing the rest
    $rationale = array();
    if (isset($data['rationale']) && is_array($data['rationale'])) {
        $rationale = array_values(array_filter(array_map('strval', $data['rationale']), function ($x) { return trim($x) !== ''; }));
    }
    unset($data['rationale']);

    // normalize: strings stay strings; sections stays an array of strings
    $fields = array();
    foreach ($data as $k => $v) {
        if ($k === 'sections') {
            $fields['sections'] = is_array($v) ? array_values(array_filter(array_map('strval', $v), function ($x) { return trim($x) !== ''; })) : array();
        } else {
            $fields[$k] = is_scalar($v) ? (string) $v : '';
        }
    }
    return ['ok' => true, 'fields' => $fields, 'rationale' => $rationale, 'error' => null, 'loaded' => $loaded];
}
