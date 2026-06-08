<?php
/**
 * Halo Email — pipeline configuration (SAMPLE)
 * -----------------------------------------------------------------------------
 * COPY this file to `config.php` in the same folder and fill in your keys.
 * `config.php` is git-ignored so your secrets never land in the repo.
 *
 *   cp config.sample.php config.php
 *   # then edit config.php
 *
 * When `config.php` exists and `enabled` is true, submitting the brief form
 * will: (1) generate the email via the Claude REST API using the orchestrator +
 * all rule files as context, and (2) if `auto_send` is true, send the test email
 * through Braze. With no config.php present, the form just saves the brief.
 * -----------------------------------------------------------------------------
 */
return [
    'enabled' => true,

    // ---- Claude API (https://platform.claude.com → API keys) ----
    'anthropic_api_key' => 'sk-ant-REPLACE-ME',
    'anthropic_base_url' => 'https://api.anthropic.com',
    'anthropic_version' => '2023-06-01',
    'anthropic_model'   => 'claude-opus-4-8',   // most capable; do not downgrade without reason
    'effort'            => 'high',               // low | medium | high | max (max = Opus only)
    'max_tokens'        => 32000,                // room for adaptive thinking + the email HTML
    'request_timeout'   => 900,                  // seconds to wait on the Claude call

    // ---- Validation gates ----
    'validate'          => true,                  // deterministic: validate every email against ALL rules (test/validate.py)
    'python_bin'        => 'python3',             // how Python is invoked on this host
    'ai_review'         => true,                  // backstop: a second AI pass for judgment rules the static checks can't cover
                                                  // (runs only after the deterministic gate passes; both must pass to send)

    // What stable context to load into the (prompt-cached) system prompt.
    // These are read fresh from disk on every request, so editing a rule file
    // is picked up on the next send automatically.
    'include_examples'  => true,                 // include email-examples/*.html for tone reference

    // Path to the resources repo root, relative to THIS file (brief/).
    'repo_root'         => __DIR__ . '/..',

    // ---- Braze (test send) ----
    'auto_send'         => true,                 // POST to Braze /messages/send after generating
    'braze_rest_url'    => 'https://rest.iad-01.braze.com',  // YOUR Braze REST endpoint (region-specific)
    'braze_api_key'     => 'BRAZE-REST-API-KEY-REPLACE-ME',
    // These three are the hardcoded send identity (same values the form bakes into the brief):
    'braze_app_id'      => '575c10a7-11d8-4494-afdc-a01e5a420cf4',
    'braze_from'        => 'The Halo Team <thehaloteam@app.halocollar.com>',
    'braze_segment_id'  => '31c76280-df2d-40c4-b566-e29117768163',
];
