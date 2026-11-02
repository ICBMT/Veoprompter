# Short-form, long-form and linked story batches

## Using the feature

Create Project asks **Short-form or a longer story?**, as one more question in the existing wizard. Edit Settings has the same preference. Existing projects default to **short-form**, and ordinary short-form generation receives no new story-generation instructions.

- **Short-form:** the first kit follows the existing complete-story workflow. Its controls offer **Create Part 2**.
- **Long-form:** the first kit is instructed to establish an ongoing story/chapter instead of ending it. Its controls offer **Generate next clips** and **Generate ending · end story**.
- **Next clips:** advance the same part and increment its batch number.
- **End story:** produce a resolving batch, mark that part complete, then offer **Create Part N+1**.
- **Create next part:** open a sequel part, starting from the saved world, outcomes and ending rather than resetting the story. This part can then grow through more batches before the user explicitly ends it. Short-form projects can therefore grow just like long-form ones.

Every continuation lets the user choose **a set of clips** (the saved count strategy/manual count) or **one next clip**, and supply optional direction up to 4,000 characters. Choosing one clip is scoped to that continuation; it does not change the project's 2–8 manual wizard choices or lose the set-count recipe. Timing, narrator, speech mode, format, style and other brief preferences come from the saved source, with current duration-permission switches still enforced. Count/duration allocations and QA remain per generated kit; this is not a new target-total-duration scheduler.

Controls appear below the Studio's generated result, on saved-kit pages, and inside expandable controls on every Prompt Library card. Saved pages link to the parent kit and paginate direct continuation/sequel branches. A continuation opens its own saved-kit page with the existing QA, Auto-Fix, compare/restore, copy/download and Send to Flow controls.

## Persistence and branching

Run `php artisan migrate` **before using the updated application**. Migration `2026_11_02_000001_add_story_continuations_to_prompt_projects.php` adds:

- nullable `parent_prompt_id` (delete sets null, does not cascade-delete descendants);
- `story_part`, `story_batch`, `story_status` (`open`/`ended`);
- `story_context` snapshot;
- unique `continuation_key` for idempotent generation requests.

Each batch is a new owner-scoped `PromptProject`, with its own original output, QA evidence and repair history. Neither generation nor finishing rewrites the parent. An old open batch remains an open historical branching point even after one of its children finishes; the finished child offers the next part. Different intentional requests from the same parent create alternative branches, not an implicit overwritten “latest” script. Old records require no fabricated story summaries and can start a sequel if their ending is parseable.

## What the model receives

`StoryContinuationService` prepares server-side context, never browser-supplied raw history:

1. saved brief/preferences and the original pre-clip bible/plan prefix;
2. an immutable literal identity ledger, including explicitly approved later additions;
3. explicit approved cast counts where canonical IDs/counts were supplied;
4. compact cumulative story memory: summary, unresolved/resolved threads and important facts;
5. the current source batch's primary ideas, visible cause/action/result, ending states and exact parsed spoken rows;
6. the actual current final clip plus its ending continuity/presentation states;
7. requested intent, target part/batch and user direction.

The current saved clip, including any repair/restore, is authoritative for the next opening—not a stale remembered frame. Previously spoken material is supplied as context, not permission to replay it. Existing root dialogue/voice protections remain in force. An optional checkbox authorizes new recurring subjects explicitly described in the direction; it does not permit silently changing an existing identity/count or overriding a no-people/no-animals restriction. Legacy prose without IDs cannot receive the same deterministic identity guarantees.

The service stores a fresh snapshot rather than recursively embedding prior request envelopes. It does **not** concatenate an ever-growing history into every call. There is no application limit on the number of linked batches/parts. There are honest context budgets: original/accumulated canon 48,000 bytes, compact memory 20,000 bytes, final clip 24,000 bytes and recent beat/dialogue context 24,000 bytes. Oversized evidence fails visibly rather than silently truncating identities or dialogue. This supports long chains with a stable cast but is not unlimited model memory or a guarantee that arbitrary older details survive compression. Memory content is model-authored; its structure is validated, not its factual completeness.

## Quality and handoff behavior

Every new batch uses the **same** `PromptGenerationService`, canonical reference compiler, full output auditor and existing bounded deterministic/one-shot corrections. No alternate low-quality continuation provider path or automatic generation loop is added.

Ordinary short-form output formatting and the protected root/runtime master files are unchanged. For long-form and continuation requests, an appended story contract clarifies that `FINAL` means the end of the current batch, not necessarily the narrative. Each batch still numbers local clips from 1, with the normal INITIAL marker.

The additional Section 11 footer, outside every copy fence, contains:

- `STORY STATUS: OPEN` or `ENDED`;
- `STORY MEMORY:` one-line JSON with `summary`, `open_threads`, `resolved_threads`, `important_facts`;
- on continuation batches, `STORY HANDOFF TYPE: TYPE A/B/C — [correct description]` for the external junction.

OPEN needs at least one declared unresolved thread; ENDED needs none. Lists have at most 40 concise items, each at most 1,000 bytes; summary at most 8,000 bytes. This status is a requested/declared narrative state, **not an automatic detector proving the plot is truly complete**.

After ordinary QA, continuation acceptance independently requires:

- the new first opening continuity AND presentation strings to match the current source's ending literally;
- a readable last ending for future continuation;
- an explicit unambiguous external A/B/C classification;
- the existing handoff/continuity validators to pass on a synthetic source-last/new-first junction (without changing either saved prompt's local labels);
- no unresolved identity/roster findings, no redefined known identity or changed established cast count, and permission for new explicit recurring anchors;
- valid requested status/memory structure and context budgets.

A Type B/C change therefore starts matched and unfolds inside the new first clip. A Type C jump needs explicit before/transition/after evidence and is not presented as seamless. Failed junction/identity/memory guards return a visible error and do not save a child or overwrite the source. Other ordinary QA findings retain the existing review/repair behavior; this is not a promise that every accepted batch is semantically flawless. Story guards are separate acceptance errors, not new entries inflating the auditor's 64-code inventory or first-pass metrics.

The extension already excludes trailing sections from video chunks; a regression confirms Section 11 memory stays in review notes and is never pasted as part of the final video prompt. No rendered frame is uploaded with a story-continuation request. References to actual target-tool frame/continuation mechanisms remain conditional on evidence; text equality is not rendered-video continuity.

## Safety and request lifecycle

`POST /history/{project}/continue` uses the existing authenticated/active-account middleware, ownership checks, CSRF protection, validation and rate limiting. The endpoint derives all source content and recipe settings on the server. Provider/TTS credentials are never returned to the browser or extension; TTS behavior is unchanged and remains on demand.

A per-owner/source/request UUID maps to a unique database key. A 360-second cache lease covers the two existing 120-second provider calls without holding a database transaction open over network work. Identical retries reuse the saved child instead of generating again; reused IDs with changed directions are rejected. The browser disables controls while generating, preserves the request ID on errors, and creates a new ID when the direction/options change. Both endpoints and result navigation must be same-origin.

Immediately before saving, a short transaction locks/re-reads the source and compares its content hash. If another action edited/deleted it while generation was running, the result is rejected rather than attaching to an obsolete ending. The original kit and its repair history are never replaced. As with other provider calls, charges may still occur for a generated candidate rejected by validation; no automatic retry or extra story-specific provider call is hidden here.

## Verification and deployment

```sh
php artisan migrate
php scripts/check-story-continuation.php
php scripts/check-refinement.php
php scripts/check-first-pass-planning.php
node --test tests/js/*.test.js chrome-extension/tests/*.test.js
php artisan test --filter='StoryContinuationTest|StoryContinuationServiceTest|PromptStudioTest|PromptRepairTest|PromptPlanningPolicyTest'
```

Executed here with a standalone native PHP 8.4 CLI:

- **41** story-contract assertions, including valid A/B/C junctions, forbidden state/camera/identity/count changes, open/finish/sequel transitions, one-clip recipe preservation, malformed/missing memory, current repaired endings, context budgets and repeated-batch boundedness;
- existing **851** refinement and **79** planning-policy assertions;
- **47** actual-service unit methods via a compatibility assertion runner, **not PHPUnit/Laravel execution**;
- **43** Node tests (6 new story UI tests plus 37 extension tests);
- changed/new non-Blade PHP syntax checks, changed JS syntax checks and protected-file/diff checks.

Laravel database/auth/HTTP Feature tests were added for owner isolation, initial long-form generation, linked sequels/endings, UI availability, replay/lock behavior, invalid input, junction rejection and concurrent source editing. Composer/vendor dependencies are unavailable here, so those Feature tests, real Blade rendering, browser end-to-end and migration execution remain **unexecuted**. Run them in your PHP/Composer-enabled test deployment. No live-provider, rendered-video or long-story model-quality benchmark was performed. A compact memory record and text-based heuristics cannot establish perfect narrative recall, physical continuity or factual accuracy.
