# First-pass error prevention

The goal is fewer errors in the **original provider response**, not merely a clean response after repair. Prompt instructions cannot guarantee error-free output or seamless rendered video. Final text QA and bounded repair remain independent safeguards.

## Generation changes

`resources/prompts/first-pass-contract.txt` is appended as the final request-specific planning contract, after the protected master specification and format/speech/continuity addenda. It preserves that specification's output method, wizard, format-aware structure and Type A/B/C semantics. Root `prompt.txt` and its exact runtime master-spec copy are unchanged.

The contract asks for a complete internal planning/preflight pass, not private reasoning or a second diagnostic response:

1. Resolve the full constraint set and protected script before drafting; report infeasible fixed constraints rather than hide them.
2. Establish one identity/object/process and clip-state plan; prefer genuine continuing action over gratuitous bridges or jumps.
3. Solve individual and aggregate speech windows before finalizing the AI count, preserving words and same-clip visual alignment. Retain useful 2–3 second visual headroom without double-counting pads.
4. Compile overview, clip plan, runtime, standalone prompts and handoffs from that same final allocation. Copy state strings verbatim; repeat necessary identity locks, not decorative prose.
5. Read back the completed text for completeness, numbering, durations, speech, exact state handoffs, reference evidence, unsupported claims and final markers. Recheck all dependent views after any edit.

The server instructions and creative brief previously contained two illustrative `0.0–1.8s` speech rows. Those now start at `0.2s`. Existing illustrative timestamps in the protected baseline are not rewritten; the final contract explicitly makes the active QA constants authoritative over generic examples.

## Shared timing constants

`PromptOutputAuditor::speechEstimatePolicy()` provides the same rate, sentence-pause and technical-word allowances that `minimumSpeechSeconds()` actually uses. The final planning contract renders those values and the auditor's opening/inter-line/closing minima. Audience adjustments and server-side configuration therefore propagate to both planning and QA. The stricter handoff closing allowance is used as the conservative planning minimum for the kit.

These remain text estimates. Language-specific pronunciation, emphasis, physical action time, real TTS and rendered audio need verification. The final contract does not bypass manual counts/durations, platform limits, protected dialogue, silent mode or evidence rules.

## Measure original quality separately

Each generation returns/saves `quality_review.first_pass`, captured **before** deterministic or AI repair:

- `scope`: `automated_text_checks`
- `status`: `passed`, `needs_review` or `not_checked`
- `issue_count` and unique `issue_codes`
- parsed `clip_count`
- `contract_version`: `1`

Final `quality_review.status` still describes the accepted final text. A repaired kit must not be counted as first-pass clean. An unparsed output is `not_checked`, not a successful first pass. No raw duplicate prompt, credentials or hidden reasoning is added to this metadata. Later manual repair, re-audit and restore preserve this original snapshot. Existing history records without the field remain valid and do not acquire fabricated first-pass measurements. No migration, extra provider call or extra repair attempt is introduced.

To assess improvements, use a stable set of representative briefs across formats, long scripts, technical language, fixed constraints, silent mode and extensions. Compare first-pass parsed success, issue rates by code and repair usage against the previous version on the same model/settings. Exclude unparsed outputs from claims of success. No such live-model A/B benchmark has been run in this workspace; wording changes alone do not establish a measured error-rate reduction.

## Verification

Standalone, no Composer or provider access:

```sh
php scripts/check-first-pass-planning.php
```

This tests actual policy assembly across every selected video type, speech on/off, extensions and custom QA settings. It is not a generation-quality benchmark.

Native application tests when PHP/Composer dependencies are available:

```sh
php artisan test --filter='PromptPlanningPolicyTest|PromptStudioTest|SpeechTimingTest|PromptOutputAuditorTest|PromptRepairTest|MasterSpecificationTest'
node --test chrome-extension/tests/*.test.js
```

Added regression coverage checks dynamic constants and format coverage, original-vs-final QA metadata, unparsed outputs not being labeled clean, and no repair request for a clean original kit. Native Laravel/provider integration and actual rendered results remain unverified here.
