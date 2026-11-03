# Complete saved-source context for continuation

Part 2 / next-batch generation receives the complete **current final saved prompt**
of the immediately preceding batch, not its old `original_content` or only a compact
summary. The request also carries the exact ending-state/presentation snapshot,
immutable literal identity ledger, user direction and existing story memory.
This applies to both short- and long-form continuation.

The final prompt is included as a JSON string with a SHA-256 digest. After JSON
decoding it is byte-for-byte the saved text, including Unicode, line endings,
spacing and authorized edits. It is historical data, not executable instructions;
previous opening scenes and footer status cannot override the inherited final
frame or the requested direction. The snapshot is built afresh for each request,
not accumulated recursively into the saved identity ledger or child brief.

The complete snapshot has a 256,000-byte safety limit. Oversized sources fail
explicitly before a provider request, never by silently truncating the story.
A model's own context-window limit may still be smaller. Including full source
increases input-token usage; this is not a guarantee of live model quality.

## Identity policy (updated)

Continuation now defaults to **AI-managed identity continuity**:

```
CONTINUATION_IDENTITY_MODE=ai_managed
```

Missing `CANONICAL IDENTITY` labels, absent cached identity ledgers, literal
identity mismatches and identity/roster QA findings no longer block a new part.
The complete current saved source and current configuration are supplied to the
model with explicit instructions to preserve existing characters, appearances,
counts, objects, materials and world/style. New recurring subjects require the
existing explicit permission and direction. No missing traits are filled in by
the application, and model prose is not promoted to a verified identity ledger.

Identity findings are retained as advisory warnings in the saved review. The UI
labels this mode and recommends inspecting characters and objects before rendering.
An identity match or perfect visual continuity is **not** certified by this mode.
No additional AI identity-review request or hidden retry is made.

The prompt still requests the exact inherited opening. Story status and memory validity,
response completeness, timing checks, scoped template resolution, request
idempotency and concurrent-source-edit protection remain enabled. These can
still legitimately reject a request. The source remains unchanged.

An operator can opt back into the previous strict literal-ledger policy with
`CONTINUATION_IDENTITY_MODE=strict`. Apply configuration changes through the
normal deployment config-cache rebuild. Strict mode requires complete consistent
literal definitions and can reject previously saved AI-managed kits that do not
satisfy that contract. No migration is necessary.

Regression suites cover both policies: existing strict-contract tests explicitly
run under the strict setting; dedicated AI-managed tests exercise missing CHAR-01
labels, empty legacy ledgers, short/long forms, repeated next parts, advisory
mismatches, visible warnings and preserved strict-mode/provider-failure rejection.

## HTTP development browsers and opening diagnostics

Continuation request IDs use native `crypto.randomUUID` where available, or
cryptographic `getRandomValues` UUID-v4 construction on HTTP origins where
`randomUUID` is unavailable. No `Math.random` fallback is used. If neither secure
mechanism exists, the form shows an actionable browser/HTTPS error instead of
throwing on every keystroke. Identical retries retain their UUID; edits and intent
changes create a new operation ID. Duplicate submits remain blocked while busy.

The first-clip opening compiler contract is repeated at the end of the generation
brief, after other request configuration. The full source record explicitly
includes the current configuration. This reinforces the existing source-ending
references without rewriting the source or silently patching a conflicting scene.

## User-reviewed boundary QA (default)

```
CONTINUATION_BOUNDARY_MODE=advisory
```

A suspected cross-batch opening/handoff mismatch no longer rejects a usable
candidate. It is saved as a separate kit, with the original source unchanged and
its opening values left intact. The saved page shows **Saved for your continuity
review**, suspected differences and the link to the previous kit. The user can
compare, copy, test, keep or discard the candidate. It is not labeled a verified
match, and no boundary rewrite or extra provider retry is made to force a pass.
Identity and boundary policies are independent; both default to advisory/AI-managed.

Exact-opening and junction comparisons still run as advisory evidence. They can
flag equivalent wording as different, and cannot prove a visual discontinuity.
The current candidate's actual ending becomes the next source snapshot; no
hypothetical state is invented to repair the transition. Missing usable ending
state/presentation, invalid story memory, incomplete provider replies, unresolved
compiler references and concurrent source changes still prevent an unsafe save.

Set `CONTINUATION_BOUNDARY_MODE=strict` and rebuild deployment configuration to
restore the earlier hard gate. In strict mode a mismatch returns HTTP 502 with
`code: continuation_opening_mismatch` and field-level `opening_issues`. The browser
renders those diagnostics and preserves the operation UUID for retry.

Both policies have regression coverage. Strict regressions explicitly enable the
strict configuration; advisory tests verify short/long candidates are saved
unchanged with warnings, the parent remains unchanged, and provider failures or
missing ending data are not falsely reported as success.
