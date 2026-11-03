# xKiro prompt-generation connection

VeoPrompter uses xKiro's OpenAI Chat Completions dialect, not its Anthropic dialect.
No migration or separate provider driver is needed. The provider remains `openai`
in saved settings; the base URL identifies xKiro.

1. Open **Control room → Set up xKiro**. This fills
   `https://api.xkiro.com/v1` without saving or making a provider request.
   A typed key for a different endpoint is cleared, never forwarded.
2. Enter an xKiro API key in the administrator's password field (not in chat).
3. Load available models, or enter the exact full `vendor/model` ID manually.
   IDs come from the catalogue, not a hardcoded list. Free, paid and premium tier
   labels reflect catalogue metadata, not your account balance or entitlement.
4. Test the selected model and save the connection. Saved credentials stay
   encrypted server-side. Model loading and connection tests also run server-side.

Choose a text-generation model, and a vision-capable model when uploading reference
images. A successful small test does not guarantee enough capacity for a full kit.
The public catalogue is not account-filtered; free access has usage limits and
availability can change. Check remaining allowance in the xKiro dashboard.

## Limits and failure handling

- Requests use Bearer authentication, `/models`, and `/chat/completions`, preserving
  the complete model ID. No automatic switch to a paid model or another provider.
- xKiro documents a **95-second limit for non-streaming requests**. VeoPrompter
  currently uses non-streaming requests. Slow/long generations can still time out;
  use a faster suitable model. Streaming support is not included in this preset.
- Output-token/context limits vary by model. Truncated output is rejected, not
  silently saved. All existing quality and continuity gates still apply.
- Quota/provider failures do not replace successful history; the frontend retains
  the previous kit and allows another attempt. No blind quota retries are added.
- Speech, image generation, and subtitle services are **not** switched to xKiro by
  this prompt-generation preset.

Contract tests use HTTP fakes. They do not establish live availability, actual daily
allowances, model quality, or production network access. This sandbox cannot make
live xKiro API requests; test a key on your deployed server.

Official references consulted:
- https://docs.xkiro.com/
- https://docs.xkiro.com/api/list-models/
- https://docs.xkiro.com/api/chat-completions/
