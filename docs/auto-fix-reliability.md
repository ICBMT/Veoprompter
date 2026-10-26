# Auto-Fix and QA reliability

## Scope and evidence boundary

This note documents the reliability work for the glass-bottle/38-second failure report. The exact six-clip prompt and its original QA output were not present in the repository or supplied in the workspace. Consequently, the glass-bottle regression is explicitly **reconstructed and synthetic**; it is not represented as the user's original transcript, and it cannot establish the original incident's exact root cause.

The findings below separate source-code facts from that missing evidence:

- **Verified source-code gap:** the old contextual repair budget was four adjacent pairs. A six-clip project has five handoffs, so the final pair could not be reviewed in that request. The new bounded budget is seven, with the final pair prioritized; any pairs outside the budget remain explicitly unresolved.
- **Verified parser failure mode:** the old broad clip-range matcher could read a numbered overview row such as `Clip 1 — 5 seconds` as the global range `1–5 seconds`. Different per-clip rows could then intersect into a false duration-limit conflict, which made speech repair return without changing anything. A regression now excludes per-clip point values from the global-range scan. The exact incident's overview rows are unavailable, so whether this was its particular conflict is not proven.
- **Verified format limitation:** the old overview-total parser recognized a narrow set of seconds/minutes labels and did not normalize clock forms or several common “overall running time” variants. The exact original label is unavailable. The new parser accepts and normalizes the tested labels and clock forms and reports missing/ambiguous references instead of treating them as valid.
- **Verified speech-policy gap:** the previous scheduler used hard-coded pause values, did not reserve a configurable opening visual lead-in, and the auditor did not require a minimum gap between lines. Inconsistent/overlong dialogue could be left untouched when duration references were unparseable, incomplete, or conflicting. Exact causality for the reported twelve warnings cannot be confirmed without the original prompt and QA output.
- **Verified handoff/report gaps:** the auditor did not require Type B bridge-action evidence or Type C before/transition/after evidence, and its final continuity claim detector focused on exact-copy phrases rather than general agreement/physical-verification claims. The previous repair history also had no content/brief/issue signature, so a user could spend both attempts on an identical non-progressing repair.

## Canonical plan and validation contract

`App\Services\PromptProjectPlan` is the shared parsed representation used by the auditor and repair service. It carries ordered copy-ready clip blocks, clip count/order, per-clip durations, total runtime, saved settings, per-clip and project-wide duration limits, normalized overview/clip-plan references, continuity and presentation states, handoff types, cast/identity fields, parsed timed dialogue, estimated minimum speech duration, and required opening/inter-line/ending timing policy.

Copy-ready clip blocks are authoritative. Overview duration/runtime and clip-plan durations are dependent references: the auditor validates them, and safe duration changes synchronize every complete parseable reference. A missing or ambiguous reference blocks expansion rather than allowing the dependent sections to drift. Descriptive total runtime is kept separate from a stated project-wide minimum/maximum constraint, including saved numeric ranges and labeled text ranges. Conflicts produce explicit issues and are not guessed away. Auto-Fix does not redistribute time across otherwise healthy clips just to force a project-wide total.

The current text parser accepts overview total values written in seconds/minutes (for example, `Approximate total runtime: 38 seconds`), common total/overall/video duration labels, and clock values `m:ss`, `mm:ss`, and `hh:mm:ss` (for example, `Estimated overall running time: 00:38`). Values are normalized to seconds before comparison. Compound forms such as `1 minute 30 seconds` remain unparseable and are explicitly left for review rather than partially interpreted.

Text-only QA does **not** certify physical feasibility, spoken delivery, the visible action's rendered timing, factual accuracy, or the generated video. Those remain human-review items.

## Speech timing defaults and safe behavior

Defaults live in `config/prompt_qa.php` and are consumed by both QA and Auto-Fix:

| Setting | Default |
| --- | ---: |
| Speech estimate | 2.2 words/second |
| Sentence-pause minimum/per sentence/maximum | 0.12 / 0.12 / 0.45 seconds |
| Opening visual lead-in | 0.20 seconds |
| Inter-line breathing/reaction pause | 0.20 seconds |
| Ending visual/closing-handoff tail | 0.30 seconds |
| Timing tolerance | 0.05 seconds |

The estimate is conservative text QA, not a voice-performance guarantee. Auto-Fix preserves the exact dialogue and visible-action text while retiming parsed rows. It reserves the opening lead-in, required inter-line gaps, and ending tail. It can expand an AI-selected clip only within its effective per-clip limits (never above 10 seconds) and the saved project-wide runtime maximum; a proposed shrink is also blocked if it would fall below the project-wide minimum. A fixed manual duration is never changed. If the speech cannot fit, a timing row is unparseable, duration representations cannot all be synchronized, or a project constraint blocks a proposed duration change, the original dialogue/timing remains and the issue is reported unresolved; time is not invented.

## Handoff and bounded-repair behavior

- **Type A:** direct continuation is checked against the structured physical/presentation state and the declared handoff contract.
- **Type B:** the destination must open on the exact prior ending state and contain an anchored visible action bridge inside the clip. A compatible opening alone is not sufficient. Text QA checks evidence but cannot certify physical plausibility.
- **Type C:** the destination must open on the prior ending state, then document a before-state, an intentional temporal/narrative transition, and an after-state inside the clip. It is reported as `transition_documented`, not as a seamless/exact handoff.
- Contextual repair remains bounded. With six clips, the five adjacent pairs—including Clip 5→Clip 6—fit the current budget. The final handoff is prioritized if there are more mismatches than the budget; omitted pairs stay unresolved.
- Every candidate is fully re-audited. A candidate that adds findings is rejected; a saved `Fixed` item is an issue removed by the final audit, not merely an attempted edit.
- Manual repair reservations hash the prompt, saved brief, and QA issue descriptors. A matching completed non-retryable no-progress attempt is blocked without consuming another attempt. Provider/transport failures are marked retryable.

The repair report separates `Fixed and verified`, `Unresolved`, `New regressions in the saved prompt`, `Rejected attempts (candidate not saved)`, and `Informational` notes. The Studio one-shot correction in `PromptGenerationService` is a separate path from manual Auto-Fix: its correction brief now repeats explicit speech timing thresholds (including the opening visual beat) and it re-audits the single corrected response. A partial Studio candidate is accepted only when it reduces findings without adding a new QA issue; the Studio regression verifies that an opening-beat finding is included in the correction request and the corrected output passes text QA.

## Synthetic six-clip regression: before and after

The unit fixture is named `test_reconstructed_six_clip_38_second_glass_bottle_case_repairs_speech_handoffs_and_all_runtime_views`. It models six durations totaling 38 seconds, a `00:38` overview runtime, twelve overlong speech rows, five mismatched handoffs, missing opening/tail beats, and contradictory final-audit claims. The provider response is mocked and selects only an already-existing state value; it does not model a real provider's judgment.

A separate PHP-WASM smoke harness executed the same general case through the real auditor and repair service with dependency shims:

| Measure | Before | After |
| --- | ---: | ---: |
| Canonical clip duration sum | 38 s | 39 s |
| Overview total runtime reference | Parsed as 38 s | Synchronized to 39 s |
| Speech-overrun findings | 12 | 0 |
| Structured handoff-state mismatches | 5 | 0 |
| Final continuity/pacing overclaims | Present | 0 |
| Final text-QA status | Needs review | Passed; 0 issues |
| Repair report | Not applicable | `applied`; fixes include speech opening/overrun/tail, continuity mismatch, and overclaim codes |

The +1 second is allocated to the first AI-selected clip because the configured natural speech estimate plus opening/gap/ending requirements do not fit in its original five seconds. The overall prompt's exact original content, QA output, physical glassmaking correctness, rendered video, and natural human speech were not available for verification. This result is evidence for the **synthetic code-path regression only**, not a reproduction of the original incident and not a “10/10” certification.

## Regression coverage added or updated

- `tests/Unit/PromptOutputAuditorTest.php`: clock and seconds runtime parsing, saved/text project-wide bounds, numbered per-clip durations (including a same-line list) not becoming a global range, Type B bridge evidence, Type C transition evidence, opening/inter-line/ending speech timing, and final-audit claim evidence.
- `tests/Unit/PromptRepairServiceTest.php`: reconstructed six-clip case, synchronized duration views, speech that fits/cannot fit, AI/manual duration limits, project-minimum shrink guard, Type A/B/C regressions, bounded final-handoff review, no-progress signatures, and candidate-regression rejection.
- `tests/Feature/PromptRepairTest.php`: same non-progressing repair is not retried or charged twice, retryable provider failures still use only the bounded budget, and restoring the original does not count as a repair attempt.
- `tests/Feature/PromptStudioTest.php`: the Studio one-shot prompt receives the opening-visual-beat finding, moves speech after the lead-in, re-audits to a clean QA result, and saves that corrected response.

## Commands and observed validation

The following limited checks were run in this workspace:

```sh
npx --yes --package=@php-wasm/cli@3.1.60 php-wasm-cli -l app/Services/PromptOutputAuditor.php
npx --yes --package=@php-wasm/cli@3.1.60 php-wasm-cli -l app/Services/PromptProjectPlan.php
npx --yes --package=@php-wasm/cli@3.1.60 php-wasm-cli -l app/Services/PromptRepairService.php
npx --yes --package=@php-wasm/cli@3.1.60 php-wasm-cli -f .phpwasm-smoke.php
```

The PHP-WASM lints passed for all changed PHP services, controllers, configuration, and test files. The limited smoke produced the before/after results above and checked valid Type B/C evidence, speech pauses, runtime labels/bounds, the project-minimum shrink guard, cumulative expansion budgets, and no-progress signatures. The temporary `.phpwasm-smoke.php` harness was removed after validation; it is not a replacement for PHPUnit/Laravel tests. `git diff --check` and `node --check public/js/prompt-repair.js` passed. The CLI was pinned to 3.1.60 after the latest npm release failed to resolve a dependency; npm reported engine-version warnings, but the pinned lint/smoke commands completed.

Native `php`, Composer, `vendor/`, and PHPUnit are unavailable in the current workspace, so the added Laravel unit/feature tests have **not** been executed here. The native commands to run in an installed project environment are:

```sh
php artisan test --filter=PromptOutputAuditorTest
php artisan test --filter=PromptRepairServiceTest
php artisan test --filter=PromptRepairTest
php artisan test --filter=PromptStudioTest
```

The Vite dependency is also not installed, so a production asset build has not been verified. PHP-WASM syntax/smoke checks do not execute Laravel's database transactions, HTTP fakes, model casts, or PHPUnit assertions.

## Changed files

- `app/Services/PromptProjectPlan.php`, `config/prompt_qa.php`
- `app/Services/PromptOutputAuditor.php`, `app/Services/PromptRepairService.php`, `app/Services/PromptGenerationService.php`
- `app/Http/Controllers/PromptRepairController.php`, `app/Http/Controllers/PromptHistoryController.php`
- `public/js/prompt-repair.js`
- `tests/Unit/PromptOutputAuditorTest.php`, `tests/Unit/PromptRepairServiceTest.php`
- `tests/Feature/PromptRepairTest.php`, `tests/Feature/PromptStudioTest.php`

Root `prompt.txt` was not edited. The change is limited to QA, repair, reporting, Studio correction, tests, and documentation.

## Remaining risks

1. The exact reported six-clip source and QA response are still missing. The synthetic test needs to be replaced or complemented with that real fixture before attributing incident-specific root causes.
2. This parser intentionally warns on unsupported/compound formats; future format variants need explicit regression cases. A clock value of `1:30` is interpreted as minutes:seconds, so users must label durations with hours when needed.
3. Word-rate and pause assumptions are configurable estimates. They cannot prove voice timing, pronunciation, comprehension, audio/video synchronization, or edit feasibility.
4. Type B/C checks use text evidence and limited state heuristics. They cannot validate real-world physics, actual transitions, or the rendered video.
5. Native Laravel/PHPUnit and Vite checks remain outstanding in an environment with project dependencies installed.
