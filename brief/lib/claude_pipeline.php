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
    foreach (['brand-brain', 'product-brain', 'email-design-system', 'braze-deployment'] as $d) {
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
        . "BUILD ONLY FROM WHAT EXISTS. Assemble the email exclusively from the section_ and "
        . "component_ HTML provided and the structures shown in the example emails. Reuse those "
        . "snippets as the literal building blocks: keep their markup and structure intact "
        . "(including class markers like class=\"section-header\"), and fill only the [BRACKETED] "
        . "placeholders. Do NOT invent new sections, components, layouts, or structural HTML that "
        . "does not appear in the provided sections/components or example emails. If the brief asks "
        . "for a block that has no matching snippet or example pattern, use the closest existing "
        . "pattern or omit it — never fabricate a new structure. Preserve the "
        . "formatting and whitespace of the snippets; do not minify or collapse the HTML.\n\n"
        . "OUTPUT CONTRACT (overrides any output instruction in the orchestrator): return your result "
        . "ONLY as the structured object with keys `subject`, `preheader` (50-100 chars), and `html` "
        . "(the complete, production-ready, cross-client email HTML document). Do not output the Braze "
        . "send JSON or any commentary — the surrounding system assembles and sends it.\n";

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

/** Send the test email through Braze. Returns ['attempted','ok','code','message']. */
function hp_send_via_braze(array $cfg, array $email) {
    $body = hp_braze_body($cfg, $email);
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
function hp_save_generated(array $cfg, array $gen, $slug) {
    if (empty($cfg['repo_root'])) return;
    $genDir = rtrim($cfg['repo_root'], '/') . '/generated';
    if (!is_dir($genDir)) @mkdir($genDir, 0775, true);
    $base = 'brief_' . $slug . '_' . date('Ymd');
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
        . "inventing new ones. List EVERY rule it violates, each as one short specific line naming the rule "
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
 * Full pipeline. Each attempt: generate → deterministic validator (all rules) →
 * if that passes, the AI backstop review. The email must pass BOTH gates. On a
 * content failure from either gate it regenerates ONCE with the combined failures
 * fed back; a second failure does NOT send and returns the failures. An AI-review
 * infrastructure error fails closed (does not send) without retrying.
 */
function hp_run_pipeline(array $cfg, $briefMarkdown, $slug) {
    $validate = !array_key_exists('validate', $cfg)  || !empty($cfg['validate']);
    $aiReview = !array_key_exists('ai_review', $cfg) || !empty($cfg['ai_review']);
    $maxAttempts = 2;          // first try + one retry
    $feedback = '';
    $attempts = 0;
    $gen = null;
    $derrors = [];
    $aviolations = [];

    while ($attempts < $maxAttempts) {
        $attempts++;
        $gen = hp_generate_email($cfg, $briefMarkdown, $feedback);
        if (empty($gen['ok'])) {
            return ['ok' => false, 'stage' => 'generate', 'error' => $gen['error'] ?? 'Generation failed',
                    'attempts' => $attempts, 'send' => null];
        }

        $derrors = [];
        $aviolations = [];

        // Gate 1 — deterministic validator (every rule, md-driven values).
        if ($validate) {
            $val = hp_validate($cfg, $gen, $briefMarkdown);
            if (empty($val['ok'])) $derrors = $val['errors'];
        }

        // Gate 2 — AI backstop, only if the deterministic gate already passed.
        if (empty($derrors) && $aiReview) {
            $rev = hp_ai_review($cfg, $gen, $briefMarkdown);
            if (!empty($rev['error'])) {                 // infra failure — fail closed, no retry
                hp_save_generated($cfg, $gen, $slug);
                $base = 'brief_' . $slug . '_' . date('Ymd');
                return ['ok' => false, 'stage' => 'ai_review',
                        'error' => 'AI review pass could not run — not sent: ' . $rev['error'],
                        'attempts' => $attempts, 'html_file' => $base . '.html', 'json_file' => $base . '.send.json', 'send' => null];
            }
            $aviolations = $rev['violations'];
        }

        if (empty($derrors) && empty($aviolations)) break;   // passed both gates

        $feedback = '- ' . implode("\n- ", array_merge($derrors, $aviolations));
    }

    hp_save_generated($cfg, $gen, $slug);
    $base = 'brief_' . $slug . '_' . date('Ymd');

    // Failed a gate on the final attempt → DO NOT send.
    if (!empty($derrors) || !empty($aviolations)) {
        return [
            'ok' => false,
            'stage' => 'validate',
            'validation_failed' => true,
            'attempts' => $attempts,
            'errors' => array_merge($derrors, $aviolations),
            'subject' => $gen['subject'],
            'preheader' => $gen['preheader'],
            'loaded' => $gen['loaded'] ?? [],
            'html_file' => $base . '.html',
            'json_file' => $base . '.send.json',
            'send' => null,
        ];
    }

    // Passed both gates → optionally send.
    $send = null;
    if (!empty($cfg['auto_send'])) $send = hp_send_via_braze($cfg, $gen);

    return [
        'ok' => true,
        'error' => null,
        'attempts' => $attempts,
        'validated' => $validate,
        'ai_reviewed' => $aiReview,
        'subject' => $gen['subject'],
        'preheader' => $gen['preheader'],
        'truncated' => !empty($gen['truncated']),
        'usage' => $gen['usage'],
        'loaded' => $gen['loaded'],
        'html_file' => $base . '.html',
        'json_file' => $base . '.send.json',
        'send' => $send,
    ];
}
