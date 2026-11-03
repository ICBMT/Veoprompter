<?php

return [
    'continuation_identity_mode' => env('CONTINUATION_IDENTITY_MODE', 'ai_managed'),
    // Both Gemini and OpenAI-compatible text generation; not TTS/subtitle timeouts.
    'stream' => (bool) env('PROMPT_STREAM', true),
    'provider_timeout' => (int) env('PROMPT_PROVIDER_TIMEOUT', env('XKIRO_STREAM_TIMEOUT', 300)),
    // Provider/model output limits still apply. These are token budgets, not clip-count ceilings.
    'output_tokens' => (int) env('PROMPT_OUTPUT_TOKENS', 12288),
    'quality_output_tokens' => (int) env('PROMPT_QUALITY_OUTPUT_TOKENS', 32768),
];
