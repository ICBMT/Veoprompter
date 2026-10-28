# Speech timing: planning, final QA and safe repair

Implemented from `main:naration.txt`. This is shared policy for educational and non-educational projects, narration and character dialogue, plain/Markdown clip prompts and single extensions. It remains a **text estimate**, not measured TTS or rendered-video validation.

## Planning and QA

- Normally establish the image for **0.2–0.4 seconds** before the first word. QA enforces a **0.2-second minimum**, not a maximum: a longer meaningful visual setup is valid.
- Reserve **at least 0.2 seconds** between separate spoken lines and **0.3 seconds** after the final word. Floating-point tolerance is 0.0001 seconds, not a 0.05-second relaxation of these minima.
- Start ordinary English planning around **2.5–3 words/second**, conservatively using **2.5** for the QA estimate. Preschool/toddler/early-learner audiences use at most **2.2**. Sentence-pause allowance and a small additional allowance for words of twelve or more letters are included. The long-word heuristic is not a pronunciation dictionary; short technical terms, acronyms, emphasis, accents and non-English delivery still need review.
- The canonical plan includes a per-clip `speech_budget`: available speech seconds after opening/closing/gaps, estimated required seconds and whether it fits. QA reports aggregate budget overflow as well as per-line overrun, overlapping/invalid windows, opening/gap/tail omissions and out-of-clip speech. The last audible end is the maximum of all row ends, not merely the last printed row.
- The existing **2–3 second useful visual headroom** remains a planning target. The opening/gap/closing minima are allocated *within* that headroom, not added twice. Text QA can enforce the numeric speech windows but cannot calculate or certify the actual visual action duration.
- Maximum Quality may add meaningful connected clips to carry the exact script naturally, keeping each phrase aligned with what that clip visibly shows. Fixed counts, fixed durations, the AI ten-second ceiling, stricter declared limits and project-wide runtime constraints remain authoritative. Do not rush, remove, or rewrite protected dialogue to conceal infeasibility.

The protected root `prompt.txt` and exact runtime master-spec copy are unchanged. The server's final request-specific planning policy overrides the older generic speech-rate/shortening advice in that baseline without changing its structure or continuation method.

## Repairs and evidence

**Studio generation** first tries the existing deterministic repair service with no contextual provider call. It changes timing metadata only where it can preserve words, speakers, existing delivery-window lengths and synchronized timing views. It re-audits the complete candidate. Remaining repairable issues may trigger the existing **one-shot AI correction**, with two additional acceptance guards:

1. The exact spoken-text stream and speaker order must survive; redistributing the same words across rows/clips is allowed. Malformed/unparsed speech cannot be certified as preserved.
2. Unaffected clip bodies must survive in order; affected clips and their required adjacent handoffs may change. Related overview, clip-plan and runtime references must still pass the full QA.

Rejected dialogue rewrites retain the original content/findings and add an explanation to the visible QA limitations. A timing-compliant shorter replacement script is not accepted as a fix.

**User-requested Auto-Fix** continues to apply conservative timing/state edits and synchronize complete parseable duration references. It no longer shortens an existing speech window, established visual lead-in or longer inter-line pause just to force the schedule into a fixed duration. It never moves a line earlier to conceal a tail conflict. If the complete schedule cannot fit, it leaves the relevant findings unresolved with an explanation; it does not quietly borrow time from another clip, remove words or exceed limits.

For remaining Maximum Quality speech problems, when no contextual continuity request takes priority, a ready provider can return a bounded **split/review proposal** (up to four affected clips, at most one contextual request per repair invocation). Only validated clip IDs and enumerated decisions are used; replacement dialogue or arbitrary prose is not applied. The proposal is advisory: splitting a scene needs aligned visual actions, all timing views and handoffs replanned and re-audited. It does **not** remove QA issues or mark a repair complete. A provider failure leaves content unchanged and is reported as retryable. Fixed-count and single-extension overflow do not receive an automatic extra-clip proposal.

The existing **maximum two user repair attempts**, repair signatures/no-progress protection and one-shot Studio correction remain intact. Credentials, TTS sample generation, extension click-to-paste, and local-only extension privacy are unchanged. The extension's local warning parser uses the ordinary-English estimate/minima, without uploading prompts or pretending it knows saved audience-specific settings.

## Regression coverage and verification

- `tests/Unit/SpeechTimingTest.php`: seven generic cases covering four prompt headings/formats, opening/gap/tail boundaries, budgets, audience/technical allowance, impossible fixed/AI schedules, non-compression of existing delivery, protected words/speakers and unaffected clips.
- `tests/Feature/PromptPlanningPolicyTest.php`: added fake-provider rejection of a shorter script and a mocked contextual proposal that remains unresolved.
- `tests/Feature/PromptStudioTest.php`: opening/overrun regression now expects deterministic repair with the **original exact narration**, synchronized six-second timing and no second provider request.
- Existing auditor tests were retained; the declared-range test fixture now actually puts eight seconds in the standalone clip (it previously left the helper's four seconds while expecting an eight-second violation).
- Extension tests include opening/gap/tail checks for clip and extension prompts. The existing “naturally timed” fixture now starts at 0.2 rather than 0.0 seconds.

Available checks in this workspace: all **30 extension tests passed**; a standalone PHP-WASM compatibility smoke executed **35 regression methods** (seven new speech cases plus 28 existing auditor cases) against actual services and passed. This compatibility runner uses lightweight assertion/bootstrap substitutes and **is not native PHPUnit or Laravel integration execution**. PHP syntax and diff checks are also run.

Native PHP/Composer/vendor dependencies are unavailable here. Run the actual suites on the application environment:

```sh
php artisan test --filter='SpeechTimingTest|PromptOutputAuditorTest|PromptRepairServiceTest|PromptPlanningPolicyTest|PromptStudioTest|PromptRepairTest|MasterSpecificationTest'
node --test chrome-extension/tests/*.test.js
```

Still unverified: native Laravel HTTP/database/history integration, real provider contextual proposals/replans, actual spoken delivery, multilingual pronunciation and final rendered visual/audio alignment. Check representative long scripts, protected character dialogue, technical explanations, silent projects, fixed-duration conflicts and extensions with the deployed model before claiming production audio quality.
