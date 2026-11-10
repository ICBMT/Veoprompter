# Daily video idea discovery

## User workflow

Open **Today's video ideas** in the app sidebar or the extension's connected-site
shortcut. Choose Pakistan, US, UK or India, one of the seven existing video
formats, and an audience. The server fetches Google Trends trending-search RSS,
retains at most twenty unique entries published on the region's local current
date, and asks the existing configured Gemini/OpenAI-compatible provider for ten
creative ideas. This uses the existing API key server-side; there is no new key
in the browser/extension and no implicit fallback to another paid provider.

The ten suggestions are **not a verified top-ten video ranking**, viewer counts,
or a promise of virality. RSS publication dates establish search-topic evidence,
not the truth of news claims or full daily popularity. Source date, region,
timezone, fetch time, generation time and a server-built Google Trends link are
shown. Fewer than ten trend topics can yield different creative angles on the
same topic. Verify current-event facts before making documentary/educational
claims. Cinematic fictional angles must be labelled as fictional.

Expand an idea to review its format-specific topic/premise, learning objective
(educational only), creative direction and character/object/motif suggestion.
**Review in project wizard** selects a session-private draft; the existing
one-question wizard gets its suggested name/audience/video type, with every
setting editable. After project setup, only empty creative fields are filled.
Existing user-written fields and project settings/duration permissions are not
overwritten. Nothing auto-submits or auto-generates. Generate a normal kit and
use the unchanged **Send to Flow** sequence. The extension shortcut is navigation
only and requests no extra permissions. Subtitle functionality is untouched.

## Isolation and integrity

- All three idea endpoints use the existing active-user authentication. Generation
  has its own two/minute quota and per-user lock. Completed request UUIDs are
  cached privately by owner for three hours, before releasing the lock, so replay
  after a lost response does not issue another paid request. A UUID with changed
  inputs is rejected. This does not guarantee recovery of a request killed before
  its result is cached; no blind transport retry is performed.
- No schema migration. Batches and the single active selected draft use the
  existing session. Choosing another draft replaces the active selection; stale
  draft submissions are rejected before creating a project. Drafts and batches
  expire after three hours. Result cache entries expire too. Existing projects
  and prompts are never rewritten. Draft creative fields are only exposed in the
  owning session after binding them to the newly created project ID.
- The feed URL is fixed server-side. Redirects, external XML entities/DTDs,
  overlarge responses, malformed XML, missing/old/future publication dates and
  duplicate topics are rejected. Only valid evidence is cached for 15 minutes,
  keyed by region and local date; no stale-day or model-invented fallback is used.
  A date change during model generation rejects the stale result.
- Model output must contain exactly ten unique titles with valid evidence IDs,
  bounded strings and only permitted planning fields. Models cannot supply source
  links, credentials, timing overrides or project-setting commands. The server
  attaches source links from fetched evidence, and Blade/value assignments treat
  all model/feed text as data. Output limits/incomplete streams are failures, not
  partial successful lists.
- Uses the existing provider streaming transport and provider-specific token
  payload helper without changing their code or the prompt-generation service,
  system prompt, root prompt.txt, continuity policies or model settings. Reverse
  proxy/PHP request timeouts must allow the configured provider request duration.
  Provider errors are sanitized; upstream bodies/keys are never shown or logged.
- No background polling, scheduled API costs, or auto-research on page load.
  Ordinary GET pages and extension shortcuts never trigger a model request.

## Deployment / evidence limits

No migration or new environment key is needed. The server needs SimpleXML/libxml
and outbound HTTPS to `trends.google.com` as well as the configured AI provider.
Regions without available current-day RSS evidence get a clear unavailable-data
message; the model is not called. Network restrictions in this development
sandbox prevent live Google/provider verification. The feed contract and all
provider integrations still need a deployed smoke test; successful fixtures are
not proof of live trend-feed availability or the model's news accuracy.

Validation commands:

```sh
php scripts/check-trend-rss.php
php scripts/check-video-ideas.php
npm test
php artisan test --filter=VideoIdeasTest
```

The standalone PHP checks execute the production XML and idea-response parsers,
including local midnight, stale/future/duplicate dates, XXE, malformed content,
source binding, seven formats, response length/shape and duplicate titles. JS
tests cover safe empty-field prefill, preserved manual answers/settings, no
submission and extension-origin restrictions. Feature tests cover the HTTP→AI
fixture→selection→wizard→project flow, replay billing protection, source failure,
owner-session selection and format validation. Full Laravel feature execution
requires installed Composer vendor dependencies.

Executed in this workspace: **11** production RSS-parser assertions, **33**
production idea-response assertions, **68** JavaScript tests, and **6** real
Chromium HTTP-fixture tests (two idea/popup tests plus four existing subtitle and
extension controls regressions). Changed/new PHP files passed native PHP syntax
checks and `git diff --check` passed. Laravel `VideoIdeasTest` is added but has
not been executed because Composer vendor dependencies are not installed in
this checkout. No live Google feed, authenticated AI call, or complete Laravel
end-to-end run is claimed.
