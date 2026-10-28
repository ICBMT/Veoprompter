<?php

return [
    // Provider/model output limits still apply. These are token budgets, not clip-count ceilings.
    'output_tokens' => (int) env('PROMPT_OUTPUT_TOKENS', 12288),
    'quality_output_tokens' => (int) env('PROMPT_QUALITY_OUTPUT_TOKENS', 32768),
];
