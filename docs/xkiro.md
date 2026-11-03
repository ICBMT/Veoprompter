# xKiro prompt-generation connection

VeoPrompter uses xKiro's OpenAI Chat Completions dialect, not its Anthropic dialect.
No migration or new saved provider type is needed. The provider remains `openai`
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
  now requests **server-side SSE streaming** for Gemini and OpenAI-compatible text generation,
  including xKiro. This avoids that specific cap; it does not bypass model limits
  or deployment timeouts. Connection probes use the same protocol-aware transport.
  The UI still receives one validated final JSON response, not raw provider chunks.
  Gateways returning complete JSON are handled without resending the request.
- The xKiro provider-call timeout defaults to 300 seconds, configurable through
  `PROMPT_PROVIDER_TIMEOUT` (bounded to 120–900; the earlier
  `XKIRO_STREAM_TIMEOUT` name is retained as a fallback). This is separate from PHP-FPM,
  web-server, reverse-proxy, load-balancer and hosting request limits. Configure
  those to cover the **entire** workflow, including a possible quality correction
  call. Otherwise the browser can still lose a request even with healthy upstream
  streaming. Follow your hosting platform’s limits; a smaller hosting hard limit
  requires a background-job architecture, not simply a higher HTTP-client timeout.
- The stream must finish with a normal final-text stop and `[DONE]` before validation
  and persistence. Mid-stream errors, disconnects, malformed events, missing
  terminators and truncation are rejected; private reasoning is not prompt text.
  No automatic retry is made for these failures, because tokens may have been
  consumed already. Previous history remains unchanged.
- `31,711 tokens` (or any provider usage count) can include prompt input and private
  reasoning; it does not prove that a complete final answer reached the app.
  The application cannot retrieve a lost answer from token counts alone.
- Output-token/context limits vary by model. Truncated output is rejected, not
  silently saved. All existing quality and continuity gates still apply.
- Quota/provider failures do not replace successful history; the frontend retains
  the previous kit and allows another attempt. No blind quota retries are added.
- Speech, image generation, and subtitle services are **not** switched to xKiro by
  this prompt-generation preset.

See [provider transport configuration](provider-transport.md) for protocol coverage
and the explicit non-streaming compatibility switch.

Contract tests use HTTP fakes. They do not establish live availability, actual daily
allowances, model quality, or production network access. This sandbox cannot make
live xKiro API requests; test a key on your deployed server.

Official references consulted:
- https://docs.xkiro.com/
- https://docs.xkiro.com/api/list-models/
- https://docs.xkiro.com/api/chat-completions/
- https://docs.xkiro.com/guides/streaming/

## Transport regression validation

In addition to HTTP-fake controller tests and fragmented SSE parser tests, a local
real HTTP fixture sent heartbeats for 125 seconds before the completed response.
The production streaming client received and assembled it successfully, beyond
the old 120-second overall timeout. This was a local transport test, not live xKiro.
