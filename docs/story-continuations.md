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

Immediately before saving, a short transaction locks/re-reads the source and compares its content hash, persisted status/context and brief. If another action edited/deleted it while generation was running, the result is rejected rather than attaching to an obsolete ending. The original kit and its repair history are never replaced. As with other provider calls, charges may still occur for a generated candidate rejected by validation; no automatic retry or extra story-specific provider call is hidden here.

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

Laravel database/auth/HTTP Feature tests were added for owner isolation, initial long-form generation, linked sequels/endings, UI availability, replay/lock behavior, invalid input, junction rejection and concurrent source editing. Those tests were originally unexecuted without dependencies; see the follow-up verification below for the now-running native Laravel/PHPUnit environment. Browser end-to-end remains unexecuted. Run them in your PHP/Composer-enabled test deployment. No live-provider, rendered-video or long-story model-quality benchmark was performed. A compact memory record and text-based heuristics cannot establish perfect narrative recall, physical continuity or factual accuracy.


## Long-form opening state integrity fix

Inspection found contradictory provider instructions: the general plain-text contract prohibited JSON, while compact memory required JSON; the footer instruction also described status and memory together rather than showing separate lines. The long-form-only addendum now explicitly permits JSON solely for the memory value and shows separate plain labels and a one-line JSON schema. Missing/malformed/duplicate metadata still fails validation—no fallback summary, inferred status, source rewrite or story-specific patch is supplied.

Previously the opening context stored memory/canon but not an explicit current-batch snapshot, and `prepare()` trusted cached memory without comparing it to the current source footer. New long-form openings now:

1. Validate explicit OPEN status and compact factual memory from the **final serialized/repaired** output.
2. Capture the final scene/presentation state, final-clip number/hash, clip count and full-content hash in `story_context.batch_state`, with the validated status in `story_context.status`.
3. Pass the same continuation-readiness checks before being saved. Save content, status and context atomically; a failed long-form history save returns an error, never a successful but unsaved opening. Existing short-form save behavior stays unchanged.
4. On continuation, re-read and validate actual-batch memory/status and compare the persisted snapshot with the latest source. A missing/invalid/stale snapshot is rejected without generating or modifying the source. The commit guard also rejects a concurrent context/status/brief-only change.

Authorized existing repair/restore actions refresh an already-valid snapshot from the actual saved result in the same transaction. They do not invent missing memory or bootstrap missing legacy state; invalid state remains blocked while normal repair/restore behavior is preserved. Old sources without the required persisted snapshot are deliberately **not silently backfilled or rebuilt**. No new migration is needed: these fields use the existing private JSON context column.

Regression coverage includes newly generated opening → persisted state → successful continuation, canonical reference expansion and automatic repairs before capture, missing/ended/ambiguous/malformed metadata rejection, stale/tampered cached state, transactional save failure, normal repair/restore, concurrent state-only edits and multiple synthetic subject topics.

### Native PHP/Composer test environment follow-up

The sandbox now has PHP 8.4.21, actual Laravel 13.39.0 and PHPUnit 12.5.33 dependencies (all satisfy the project's declared constraints), installed into ignored `vendor/`. The official Composer release asset route was blocked. A working Composer 2.2.25 bundled in the official WP-CLI GitHub phar was used instead, with plugins/scripts disabled. A temporary local Composer metadata repository was assembled from public GitHub lockfiles/package manifests; package archives came from their GitHub distribution URLs. Temporary metadata, lockfile, downloads and launchers remain outside Git under `/home/user/.cache/composer`; application dependency requirements were not edited.

This NativePHP binary lacks XMLWriter. Dependency resolution therefore ignored **only** `ext-xmlwriter`, and tests used the unmodified `PHPUnit\TextUI\Application` via a temporary console launcher rather than PHPUnit's CLI extension preflight. No XMLWriter shim, fake PHPUnit assertions, framework doubles or skipped failing tests were installed. XML reports/coverage and tests using that unavailable extension remain unsupported here. Normal deployment/testing should install full PHP extensions and use the standard Composer/PHPUnit commands.

The complete suite was also run against an isolated `git archive` of the pre-fix HEAD with the identical dependencies. It already had 27 errors and 14 failures (including non-existent assertion methods, unit tests lacking a Laravel config container, stale UI/prompt string assertions and a provider-trimming expectation). Those unrelated failures are not silently repaired in this focused change. The post-fix suite has no newly failing test names; the opening regression's provider-whitespace expectation was corrected without changing production trimming. Provider calls are HTTP-faked; no live model or rendered-video quality guarantee follows from these results.


Final checks for this fix: **44 focused PHPUnit tests / 422 assertions passed**, **49 standalone story assertions**, **851 refinement assertions**, **79 planning assertions**, and **49 Node tests**. Full-suite comparison: **373 tests, 27 errors, 13 failures** after the fix versus **367 tests, 27 errors, 14 failures** at pre-fix HEAD; no new failing test names. These broader failures remain visible and are not a claim of a fully green suite.


## Authoritative continuation-opening construction

The prior request already sent ending-state strings to the model, but relied on prose instructions and model retyping. Continuation planning now explicitly loads the validated persisted `batch_state` for long-form sources and treats both `ending_state` and `ending_presentation` as authoritative first-frame inputs. The actual provider request includes a separate `authoritative_opening_snapshot` plus the complete `source_batch_snapshot`; compact memory remains context for later beats, never a reconstruction source for the opening. Existing short-form sources retain their saved-final-clip evidence path rather than gaining a new metadata prerequisite.

The planner is instructed to emit these whole-field references in the first copy-ready clip:

```text
OPENING CONTINUITY STATE: {{VEO_SOURCE_END_STATE}}
OPENING PRESENTATION STATE: {{VEO_SOURCE_END_PRESENTATION}}
```

The existing canonical serializer expands them literally from the authoritative inputs **before** independent QA. These are prospective template bindings, not post-hoc repairs: incompatible literal fields are never overwritten. References are permitted only in Clip 1's corresponding opening fields; later clips, notes, swapped fields, missing/inconsistent snapshots and unresolved references fail closed. Protected spoken words are never expanded. This preserves the entire stored state string, including positions, environment, motion, camera/framing, lighting, visual style and relevant audio state, without selecting only a subset of properties or interpreting a summary.

The exact-equality boundary check remains mandatory, now shared by generation and acceptance: it runs after initial serialization (before any automatic repair), after generation's repair pipeline, and again at child acceptance. A literal mismatch fails immediately without a hidden corrective provider call. Type A/B/C changes must start from the inherited state and visibly progress afterward; their existing independent junction validators are unchanged. Existing literal outputs still work when they already match exactly. No saved source story is rewritten and no migration is required.

Regression tests exercise actual Studio opening creation, persisted final-batch evidence, the next actual HTTP-faked provider request, compiler expansion, independent QA, and linked-child saving. The test uses explicit character/object positions and environment/camera/framing/lighting/style/motion/audio details absent from the compact summary. Separate continuity and presentation mismatch cases assert one provider call, no child, and identical full source attributes. Serializer tests cover scope, invalid snapshots, literal preservation and protected speech.

Verification for this follow-up uses the native PHP/Composer environment described above. The broader suite still has its known baseline errors/failures; no new failing test names appeared. These tests establish text/schema inheritance and fail-closed behavior, not flawless live-model scene descriptions or rendered-video continuity.

### Literal canonical identity inheritance

The continuation compiler now materializes missing identity definitions from the **saved immutable canonical ledger**, not from the model's memory or an inferred description. Previously, the request carried that ledger as context but the serializer only looked up definitions repeated in the provider's new header. A provider could declare `CHAR-01`, omit its literal header definition, and correctly fail the final identity validator.

- Before generation, check the saved `canon`, `anchors` and `approved_cast` against one another and against literal definitions in the current source package. Missing or inconsistent authority fails closed; do not reconstruct a definition from a name or summary.
- For IDs declared in the canonical roster, visible cast/subject metadata or numbered-shot cast metadata, copy an **omitted** known `CANONICAL IDENTITY ID: [complete literal definition]` into the generated character-bible header. Copy no characters into the visible cast, change no counts, and introduce no replacement definitions. An explicit conflicting, empty or repeated definition rejects rather than being overwritten.
- Perform this construction before resolving `{{VEO_IDENTITY:ID}}` clip-lock references, so those references resolve to the saved literal definition even if the provider omitted its header entry. Spoken words are never interpolated.
- Apply the same compiler to one-shot corrective responses. Independently audit the actual final output and enforce identity completeness/equality both at the generation boundary and again at acceptance. Dropping the roster/header or improving unrelated QA findings cannot bypass the final identity gate.
- Unknown/unplanned characters, altered established counts, conflicting identities and unresolved definitions still reject. The no-new-subjects rule also applies to a source with an empty identity ledger. A rejected child is never saved and every source attribute remains unchanged.

`quality_review.first_pass.serialization.inherited_identity_ids` records the IDs copied during authoritative first-pass construction. `template_sha256` still identifies the original provider response, before those definitions are added. Valid definitions already present are not duplicated. Ordinary new-story generation without continuation context is unchanged.

No schema change or source-story rewrite is required. A source genuinely missing its authoritative literal definition must be resolved explicitly; the compiler will not invent one.

Regression coverage: `ContinuationCanonicalIdentityTest` exercises the real controller, generation/serialization/repair pipeline and database persistence with HTTP-faked provider responses; `ContinuationIdentitySerializationTest` covers arbitrary IDs, complete extended definitions, idempotence, corrupt ledgers, duplicate/empty definitions, protected dialogue, shot-only declarations and empty-roster permissions. These are text/identity checks, not live-model or rendered-character quality claims.

### Explicit roster blocks and Gemini failures

The reader supports a bounded roster list/table below an empty `CANONICAL CHARACTER ROSTER:` label (or matching Markdown heading). Each row must contain an explicit canonical ID; table Count/Quantity columns preserve declared quantities. Names-only, mixed unknown entries, duplicate rows, contradictory None entries and unreadable counts remain invalid. Definitions and IDs in later sections are never borrowed to manufacture a roster. The generator is instructed to prefer the existing single-line roster contract.

For an older block-form source whose saved `approved_cast` was empty because of the previous reader, preparation may interpret explicit counts from the saved canonical roster only when they match the current source roster. Literal definitions and ledger consistency are still required. This is a read-time interpretation, not an edit to the original story. A missing count map for an ordinary inline roster remains an error.

Gemini final-text parsing excludes thought parts. Prompt blocks, safety/other non-normal terminations and token-limit truncation have distinct failures and are not retried or accepted as partial kits. A quick, successful response containing no final text is retried once; slow empty responses are not automatically repeated, bounding additional latency. Repeated empty replies do not create saved runs. The connection probe now permits 1,024 output tokens instead of 64 and applies the same final-text/termination checks. This does not guarantee provider availability or a particular model's performance.

Live probe on a host that can reach Google's API:

```sh
# Use a newly rotated key, supplied through your shell/secret manager, not committed files.
# Exact model ID must be returned by the provider model list; no model alias/fallback is assumed.
ALLOW_LIVE_PROVIDER_TEST=1 GEMINI_MODEL=gemini-3.5-flash-lite \
  php scripts/check-live-continuation.php
# GEMINI_API_KEY must already be exported securely in that environment.
```

This opt-in script makes billable calls using the actual application services: model discovery, connection test, long opening, continuation and ending. It operates on a synthetic story in memory, prints pass/fail and QA counts rather than credentials or provider payloads, and performs no database writes. It is not a replacement for reproducing a particular customer's saved prompt kit or browser behavior. The development sandbox's outbound allowlist does not include Google's API, so no live Gemini success is claimed for the local HTTP-faked regression runs.
