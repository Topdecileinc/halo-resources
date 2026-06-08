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

/**
 * Build the stable system prompt: a short framing preamble + the orchestrator +
 * every rule file + sections/components (+ examples). Read fresh each call.
 */
function hp_load_context(array $cfg) {
    $root = rtrim($cfg['repo_root'], '/');

    $rels = ['prompt_email_generation.md'];                       // orchestrator first
    foreach (['brand-brain', 'product-brain', 'email-design-system', 'braze-deployment'] as $d) {
        foreach (hp_glob_rel($root, "$d/rules_*.md") as $r) $rels[] = $r;
    }
    foreach (hp_glob_rel($root, 'email-design-system/sections/section_*.html') as $r) $rels[] = $r;
    foreach (hp_glob_rel($root, 'email-design-system/components/component_*.html') as $r) $rels[] = $r;
    if (!empty($cfg['include_examples'])) {
        // Examples live one folder deep: email-examples/<campaign>/sample_*.html
        foreach (hp_glob_rel($root, 'email-examples/sample_*.html') as $r) $rels[] = $r;     // (direct, just in case)
        foreach (hp_glob_rel($root, 'email-examples/*/sample_*.html') as $r) $rels[] = $r;   // nested (the real layout)
    }

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
function hp_generate_email(array $cfg, $briefMarkdown) {
    list($context, $loaded) = hp_load_context($cfg);

    $userText =
        "Here is the campaign brief. Build the production email now, following the orchestrator "
        . "and every rule file. Return the structured object (subject, preheader, html) and nothing "
        . "else.\n\n===== BRIEF =====\n" . $briefMarkdown;

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

/**
 * Full pipeline: generate → save → (optionally) send.
 * $slug names the output files. Returns a status array for the UI.
 */
function hp_run_pipeline(array $cfg, $briefMarkdown, $slug) {
    $gen = hp_generate_email($cfg, $briefMarkdown);
    if (empty($gen['ok'])) {
        return ['ok' => false, 'error' => $gen['error'] ?? 'Generation failed', 'send' => null];
    }

    // Save outputs outside the web root (repo_root/generated/).
    $genDir = rtrim($cfg['repo_root'], '/') . '/generated';
    if (!is_dir($genDir)) @mkdir($genDir, 0775, true);
    $base = 'brief_' . $slug . '_' . date('Ymd');
    $htmlPath = $genDir . '/' . $base . '.html';
    $jsonPath = $genDir . '/' . $base . '.send.json';
    @file_put_contents($htmlPath, $gen['html']);
    @file_put_contents($jsonPath, json_encode(hp_braze_body($cfg, $gen), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $send = null;
    if (!empty($cfg['auto_send'])) {
        $send = hp_send_via_braze($cfg, $gen);
    }

    return [
        'ok' => true,
        'error' => null,
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
