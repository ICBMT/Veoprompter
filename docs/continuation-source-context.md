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

Canonical identities are still read literally. Markdown headings around explicit
`CANONICAL IDENTITY CHAR-01: ...` definitions are recognized, but character names,
clip descriptions and model-inferred traits are not substitutes. A genuinely
missing definition, inconsistent ledger or ambiguous source still stops before
provider usage. This change does not retroactively invent missing definitions
for a broken saved kit; inspect the actual source to resolve those cases.

Generated, serialized and repaired output still passes the existing independent
identity and opening-continuity checks before saving. No new automatic repair
rounds or quality bypasses are introduced. The original source remains unchanged,
and the existing transaction checks still reject concurrent source edits.
