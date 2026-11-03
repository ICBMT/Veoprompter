# Text-model transport and timeout handling

## Supported protocols, not a promise that every model works

The prompt service supports:

- **Gemini native API**: `streamGenerateContent?alt=sse`, authenticated with
  `x-goog-api-key`, including uploaded reference images in Gemini's format.
- **OpenAI Chat Completions**: `stream: true`, Bearer authentication, including
  OpenAI-compatible gateways such as xKiro. Full vendor/model IDs are preserved.
- **Complete JSON replies** from gateways that ignore the streaming request:
  the app consumes the same response, without sending another paid POST.

This transport is shared by new kits, extensions, continuation/ending/sequel
requests, quality corrections, manual repair, speech-timing text review, and
administrator text-connection probes. TTS/audio generation, transcription,
subtitle processing, and video rendering have separate services and timeouts;
this change deliberately does not alter them.

Image-only, speech-only, embedding, tool-only, or API-specific models are not
text prompt models. Native Anthropic Messages and OpenAI Responses endpoints are
not implemented by this Chat Completions integration. A gateway may translate
those models to Chat Completions, but direct protocol compatibility cannot be
inferred from a model's name. Reference-frame generation requires vision support.
Account permissions, quota and model capacity remain provider-controlled.

## Configuration

```
PROMPT_STREAM=true
PROMPT_PROVIDER_TIMEOUT=300
```

The timeout is bounded to 120–900 seconds per provider call. An existing
`XKIRO_STREAM_TIMEOUT` is a fallback if `PROMPT_PROVIDER_TIMEOUT` is absent.
For a gateway that **rejects streaming**, an administrator can explicitly set
`PROMPT_STREAM=false` before generating, then rebuild Laravel's config cache
using the deployment's normal procedure. This is a global compatibility switch,
not an automatic retry. xKiro's documented 95-second blocking cap still applies
when streaming is disabled. Do not disable streaming as a general timeout fix.

PHP/PHP-FPM, web-server, reverse-proxy and hosting hard limits are independent.
They must cover the entire workflow, including a possible quality-correction
call. The browser receives one validated final JSON response; upstream streaming
does not send browser heartbeats. If hosting enforces a short hard limit, an
asynchronous job/polling architecture is required; no client setting overrides it.
Do not configure proxies to retry generation POST requests automatically.

Native OpenAI o1/o3/o4 and GPT-5-family request payloads use
`max_completion_tokens` and omit temperature. Other Chat Completions endpoints
retain `max_tokens` and temperature according to the compatibility contract;
aggregators such as xKiro handle their own vendor translation. No model is
silently replaced, and no automatic switch to a paid model is made.

## Safe completion and failures

- OpenAI SSE must carry a normal final stop **and** `[DONE]`.
- Gemini SSE must finish at EOF with a normal candidate `STOP` reason.
- Token-limit finishes are passed to the existing truncation rejection gate.
- Disconnects, mid-stream errors, malformed events, safety terminations and
  unsupported outputs are failures, never partial prompt kits.
- Private reasoning/thought parts are not copied into final prompt text.
- SSE/JSON buffers are bounded. Streams are closed on success and failure.
- Transport failures are not automatically retried. A provider can consume tokens
  without delivering a usable response. Previously existing quick-empty Gemini
  JSON-response retry remains bounded to one retry; it does not retry a broken stream.
- Existing continuity, canonical identity, repair limits and history protection
  remain in force. All generation still runs the same validators before saving.
- Logs record safe provider identifiers, elapsed milliseconds and HTTP status
  where applicable, not upstream prose, prompt text or credentials.

Small connection probes are limited to 1,024 output tokens. Passing a probe is
not proof that a long kit fits the chosen model's capacity or completes in time.

## Regression coverage

Tests cover fragmented UTF-8 and CRLF streams, heartbeats, reasoning-only chunks,
usage-only frames, Gemini terminal states, OpenAI terminal markers, JSON replies,
provider errors, truncation, unreadable streams and history protection. The
HTTP-fake integration matrix includes native Gemini, OpenAI chat/reasoning,
custom gateways and xKiro. These tests are not live provider quality tests.

Real local HTTP/SSE fixtures for **both** protocols also completed after 125
seconds with heartbeats and intact final text, exceeding the former 120-second
client limit. These were not live Google, OpenAI or xKiro requests.
