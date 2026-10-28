# Continuity-first maximum-quality planning

Maximum quality plans the complete visual sequence and requested narration before choosing a count. Seven or eight clips is not a target or ceiling; additional clips must have meaningful content, not filler. Overflow speech continues in order across connected clips, retaining the voice identity and a visible, same-clip explanation of each line.

For **AI duration**, estimate the actual speech/action time, overlapping only genuinely simultaneous work, then add **2–3 seconds** of useful visual setup, reaction, comprehension and ending room (normally 2, 3 for complexity). Round up to whole seconds. If that cannot fit within 10 seconds or a stricter project constraint, split the content when count is AI-selected; do not clamp an overfull estimate, rush speech or drop requested content. This is not a new four-second minimum. Fixed durations and manual counts stay exact. A single extension remains one prompt. An infeasible fixed brief must be identified, not silently expanded.

Plan continuously evolving action first so its consecutive cuts can legitimately use **Type A**. Type B is still required for a new visible physical bridge, and Type C for a necessary intentional temporal/narrative progression. Explain exceptions in the plan; never relabel jumps or missing operations as seamless continuity. Only the copied opening state is unchanged: ongoing motion must continue, not freeze.

Each standalone video-generator prompt requests a `TIMING FLEXIBILITY` note with its actual declared window. Sub-beat and speech placement may move inside that window, but words, natural delivery, same-clip visual alignment and the exact handoff states remain binding. This does not grant a renderer unsupported duration controls or guarantee a seamless rendered result. The one-shot Studio correction may add clips for AI counts and must synchronize the plan, overview, runtime and handoffs. User-requested targeted metadata repairs remain conservative.

## Provider capacity

Server-only configuration in `config/prompt_generation.php`:

- `PROMPT_OUTPUT_TOKENS=12288`: ordinary generation budget.
- `PROMPT_QUALITY_OUTPUT_TOKENS=32768`: maximum-quality generation and its one-shot correction budget.

These are output-token budgets, **not clip-count ceilings**. Values are bounded to 1,024–65,536 and must also fit the selected provider/model's actual supported output limit. For a model with a smaller maximum, lower the configured budget or choose another model; requests are not automatically retried with a smaller budget. Targeted repair retains its existing 12,288-token budget. The existing 120-second provider timeout is unchanged. Larger responses can incur more latency/cost and still hit provider limits; this change is not unlimited-output pagination. Gemini `MAX_TOKENS` and compatible-API `length` finishes fail explicitly rather than accepting a partial kit. A failed correction retains the original draft with its existing review warnings, not the truncated correction.

The protected root `prompt.txt` and its exact runtime specification copy remain unchanged. Request-specific server policy adds these pacing rules without rewriting the master method, wizard, reference handling or Type A/B/C definitions.

## Verification

`tests/Feature/PromptPlanningPolicyTest.php` covers request-policy content, fixed-setting/extension guards, AI versus fixed correction rules, configurable budgets, both provider payloads and explicit truncation failures. Run with native PHP/Composer dependencies:

```sh
php artisan test --filter='PromptPlanningPolicyTest|PromptStudioTest|MasterSpecificationTest'
```

Payload/structural tests do not prove that a live model will choose a particular count, calculate every speech duration correctly, or produce seamless rendered video. Validate representative long scripts against the configured live model and then the target video tool before asserting those outcomes.
