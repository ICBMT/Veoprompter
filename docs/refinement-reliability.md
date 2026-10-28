# Canonical compilation, independent QA and honest evidence

## Pipeline traced before refinement

1. The one-question wizard and request validation resolve the saved brief, manual/AI count and duration settings and optional reference frame. Admin provider/TTS settings remain server-only.
2. `PromptGenerationService` assembles the protected runtime master, format-aware instructions, continuity/speech planning instructions and active QA constants, then requests one provider draft.
3. **New:** `PromptPlanSerializer` reads the draft's explicit canonical sources and expands reserved dependent references. No second provider planning request, JSON output format, guessed identity or implicit fact inference is added.
4. `PromptOutputAuditor` independently parses and checks the exact serialized package through `PromptProjectPlan`; it does not trust compiler success as QA success.
5. First-pass evidence is saved now, before either repair route. Existing automatic deterministic repair and the separate Studio one-shot provider correction remain bounded. The corrected provider template is also compiled and fully audited. Protected words/speakers and unaffected clips remain guarded; a new finding rejects the candidate.
6. User-triggered authenticated `PromptRepairService` is distinct from Studio correction. Every candidate is re-audited, rejected-candidate findings are distinguished from accepted final findings, and the existing two manual-attempt limit/history/restore rules are unchanged. Ambiguous metadata cannot be silently auto-edited.
7. The local extension parses the saved package into context and copy-ready chunks. It never submits/generates video. Closing handoff/audit sections remain visible as separate review notes, not part of the final video prompt. Clipboard/paste also sanitize old stored video chunks without rewriting their raw text or navigation state.

This is **text-template compilation of a provider-authored canonical allocation**, not a full structured pre-provider planner or independently solved physical simulation. It prevents repeated facts from being re-improvised when references are used; it does not prove the original allocation is feasible. Literal legacy responses are byte-preserved by the compiler and still independently audited.

## Complete QA inventory

`resources/prompts/qa-catalogue.json` contains all **64 actual issue codes**, including the unparsed-response sentinel and dynamic required-field check. Every entry specifies:

- exact detection/defect condition;
- earliest prevention stage and governing invariant;
- independent exact-output check;
- `contract_violation` versus `review` classification;
- named executable valid/malformed regression in `tests/Support/PromptQaCases.php`.

Source/catalogue/regression equality is executable, not a manually maintained count. `PromptQaCatalogueTest` also offers each pair as native PHPUnit data cases. The framework-free runner executes the same fixtures for a wheel, seed tray and glass carafe; character-led identity cases add a closed named roster. These are **384 valid/malformed assertions**, not 384 live model samples. Existing process, character/dialogue, extension, silent and Type A/B/C tests provide additional coverage. No name or subject receives a production exception.

Context-dependent prose similarity/contradiction and bridge heuristics are review findings, not proof of a physically invalid scene. An unparseable response is `not_checked`, never clean. Rendered-video/factual/physical correctness checks stay human review/informational and do not inflate defect counts. Missing explicit identity evidence produces `identity: not_run`, not a fabricated identity pass. Legacy arbitrary prose cannot certify a closed cast, anatomy or absence of background extras.

### Evidence-driven parser fixes, not alleged generation improvements

- Server headings now accept the extension's section-wrapped and dash-separated video-prompt grammar.
- Both parsers respect copy fences: outside audit prose/metadata is not mistakenly treated as part of a video prompt. Exact source offsets are retained for safe edits/compiler ownership.
- Clip-plan `Clip N — 6 seconds` point rows are recognized. Their previous missing-duration findings were parser false positives. Ranges are not treated as point allocations.
- Repeated critical metadata is ambiguous, even when equal. Duration/required-field checks avoid reporting an additional missing value for that same duplication; type extraction never chooses the first conflicting record. An ambiguous source cannot drive a summary/canonical expansion or automatic repair.
- Literal LINE markers that do not parse as timed rows now produce a warning; they are not silently certified as speech-free.
- Closing audit sections no longer contaminate the last copied clip; extension notes and old stored sequence chunks are covered.

## Canonical prevention and final invariants

The grammar is in `resources/prompts/serialization-contract.txt`:

- Each standalone clip owns one literal duration, ending continuity/presentation state and outgoing classification. Overview/shot-list point durations and descriptive total runtime derive from those durations. Hard constraints are not allocation outputs and must stay literal.
- The next incoming label derives from the preceding outgoing label. Explicit `HANDOFF N -> N+1:` summaries derive from that same source; independent QA checks their consistency and recognized type-letter/description contradictions.
- Next opening state derives from the prior ending. Type A is for genuinely continuing action, not a mandate to freeze motion. Type B opens matched and shows its meaningful bridge inside the destination; Type C documents before/transition/after and is not sold as seamless. A/B opening presentation may derive from the prior ending; Type C must plan presentation explicitly. Final QA still checks labels, state/presentation, written frame evidence and bridge/transition evidence.
- Explicit literal canonical identity definitions can supply character, creature, object, machine or material locks. Optional closed character rosters enforce IDs and explicit counts. Subject IDs/locks use supplied anchors without inventing people/animals. Changing process state is not an immutable trait. Missing/duplicate sources fail closed; paraphrased anchors are a review finding, not proven redesign.
- Unknown, malformed, out-of-scope, nested or missing-source references are rejected. Exact spoken suffixes after `| LINE:` are never interpolated. No unresolved internal template is intentionally accepted as finished output.

The existing timing allocation contract exports the actual conservative speech policy and configured pads/gaps/pronunciation allowances. Final QA independently checks row ranges, pauses, per-line and aggregate capacity, canonical durations, declared ranges and repeated timing views. AI duration remains positive whole seconds up to ten (under four is allowed); request-validated manual durations remain 4–20, with stricter declared limits authoritative. Fixed counts/durations are not silently widened. Insufficient fixed capacity stays unresolved; contextual splitting is advisory, not automatic semantic rewriting. Count choices, minimum-practical versus maximum-quality, and uncapped meaningful AI planning remain intact.

## Reporting and original evidence

Finding identity uses code, clip, speech row, critical field, opening/ending phase and applicable explicit identity IDs—not changing numeric message prose. Thus an unchanged overrun with a different measured window is unresolved, not simultaneously fixed and new; different affected rows/IDs are not conflated.

- **Fixed:** present before, absent from the fully audited accepted result.
- **Unresolved:** remains, cannot be safely fixed or lacks sufficient evidence.
- **New issues:** introduced in the accepted result relative to the original baseline.
- **Rejected candidate issues:** introduced by a discarded candidate, not attributed to the accepted text.
- **Informational/context review:** labeled separately; no claim of research, measured pronunciation, scientific truth or rendered validation.

`first_pass` version 2 explicitly says `serialized_provider_template_before_repair`, includes clean flag/counts/codes/categories/kinds/stable keys, and records compiler expansion count plus original template SHA-256. It is **not raw-provider QA** and is not directly comparable to v1 raw first-pass metrics without that distinction. No extra raw prompt, credential or private reasoning is logged into these metrics. Existing saved history is not migrated or overwritten. Manual repair/re-audit/restore preserve this baseline; final unresolved/new metrics are recalculated where keys exist, not fabricated for legacy records. Studio/history display first versus final findings and contextual versus contract labels.

## Tool and evidence limits

No Flow continuation feature or target-tool setting was inferred or added. Reference instructions remain conditional on supplied capability evidence. Extension mode cannot invent an unseen original cast/design from a written description. Text-state equality is not a measured seamless video cut. Arbitrary prose identity, internally correct scientific chronology, natural-language contradictions beyond the explicit heuristics, and exact speech/action correspondence remain model/human responsibilities. These changes do not claim complete semantic or visual validation.

No live provider A/B benchmark, rendered video, TTS timing measurement or scientific research verification was run. Compiler adoption/error rates and model-level quality improvements remain unmeasured. Deploy only after native Laravel/HTTP/integration tests in a PHP/Composer-enabled environment.

## Reproduce and current verification

```sh
php scripts/check-first-pass-planning.php
php scripts/check-refinement.php
node --test chrome-extension/tests/*.test.js
php artisan test --filter='PromptPlanningPolicyTest|PromptQaCatalogueTest|PromptPlanSerializerTest|PromptOutputAuditorTest|SpeechTimingTest'
```

Current sandbox has no native PHP, Composer or `vendor`. The first two scripts ran using pinned `@php-wasm/cli@3.1.60`: **79 policy checks** and **851 refinement assertions** (including 448 catalogue-field assertions, source coverage, the 384 paired assertions and compiler/repair/evidence checks). A compatibility runner executed **46 zero-argument actual-service unit methods**, but this is **not native PHPUnit/Laravel execution**. New mocked-provider first-pass/correction Feature tests were added but not executed here. Extension Node tests: **36 passed**. Do not add overlapping runs together as independent sample counts. All 13 changed/new non-Blade PHP files passed syntax lint; changed JS passed syntax checks and `git diff --check` passed. Both protected prompt files are byte-identical to their respective HEAD versions. Root `prompt.txt` contains a Python wrapper, so whole-file comparison with the plain-text runtime master is not meaningful; the embedded specification matches after edge whitespace normalization. Neither protected file was rewritten. Blade compilation and browser/Flow end-to-end integration were not executed.
