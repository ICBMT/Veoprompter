# Build and end-to-end audit — 2 November 2026

## Scope and fixes

- **Independent throttles:** Laravel's unprefixed numeric throttles share the authenticated user's counter, even on unrelated routes. Presence pings, subtitle polling, approval, generation and repair could exhaust one another's allowances. All 18 throttled operations now have distinct scopes; the original numerical limits and decay periods are unchanged. Functional regression tests exhaust presence while still allowing subtitle polling and normal voice validation.
- **Keyboard-accessible motion:** below-fold scroll reveals used `visibility: hidden`, removing navigation buttons, card links and fields from the tab order before a keyboard user could reach/scroll them. Scroll-triggered reveals now animate opacity without hiding focusable content. Rain, typography, transforms and reduced-motion behavior remain intact. The browser test tabs from the wizard name directly to Continue at a 720px viewport.
- **One-time paste state:** after insertion fires page input handlers, re-read extension storage before clearing the matching pending token. Do not overwrite a replacement, rewind, re-arm, removal or metadata update with the old snapshot. This is not a claim of atomic compare-and-set across all extension contexts. Five regressions cover these input-handler changes; an actual Chromium textarea test checks one insertion and no form submission, using mocked Chrome storage.
- **Fresh account defaults:** Eloquent instances now expose the same admin/duration-choice defaults as database-reloaded accounts. Global duration-question switches remain disabled by default.
- **Reproducible frontend build:** bundle Instrument Sans from its npm package instead of fetching Bunny fonts during the build. The rendered application's existing self-hosted Inter and rainy-sky styling are unchanged. Commit the npm lockfile; build/browser artifacts remain ignored.

The prior authoritative continuation-opening work remains in place, including deliberate state/presentation mismatch rejection and preservation of the original run. No change to `prompt.txt` or `resources/prompts/master-spec.txt` is needed for this audit.

## Final results

| Check | Result |
| --- | --- |
| PHP Unit + Feature suite | 379 tests, 2,418 assertions, pass |
| Extension/browser-helper Node suite | 54 tests, pass |
| Chromium end-to-end suite (all options enabled) | 4 workflows, pass; no skips |
| Real FFmpeg portrait + landscape integration | 1 test, 20 assertions, pass |
| Vite production build | Pass |
| npm dependency audit | Zero reported vulnerabilities |

## Test maintenance, not relaxed production checks

The initial executable PHP suite contained assertion API/bootstrap errors and stale expectations. Corrected the Laravel test bootstrap, PHPUnit assertion names, session assertion receiver, current prompt wording, trimmed provider-response hashes and metadata-value assertions.

Speech tests now use genuinely over-budget scripts when testing infeasibility. The old six-clip fixture places long pauses before late speech; forcing it to pass would require compressing those pauses. The regression instead verifies honest remaining findings, exact speech preservation, synchronized runtime views, safe handoffs and the ten-second ceiling. A separate six-clip case with feasible original pauses verifies a fully successful repair. No speech thresholds, protected words or production repair gates were weakened to obtain green tests.

## Reproduce

Normal development prerequisites: PHP/extensions and Composer dependencies from the project manifest; Node compatible with Vite 8; Chromium/Playwright browser dependencies. This sandbox used native PHP 8.4 and genuine PHPUnit 12 through a cache-only launcher because its PHP binary lacks XMLWriter. Assertions, Laravel bootstrapping and SQLite tests were real; XML reporting was not available.

```sh
npm ci
npm test
npm run build
php artisan test
# Opt-in real-media integration; requires executable FFmpeg with libass and usable fonts:
SUBTITLE_TEST_FFMPEG=/absolute/path/to/ffmpeg vendor/bin/phpunit tests/Integration/SubtitleRenderTest.php
```

### Isolated browser application

Never seed or point this suite at a real installation. It creates administrator-owned projects and saved prompt runs. Only a dedicated SQLite file named `browser-qa.sqlite` is accepted by the seed script. Use a fresh dedicated storage directory or clear **only that isolated environment's cache** before repeating quickly; production rate limits deliberately stay enabled.

In one shell, set the environment (reuse it in the server/worker shells):

```sh
export QA_ROOT="$HOME/.cache/veoprompter-browser-qa"
mkdir -p "$QA_ROOT/storage"/{app,framework/cache/data,framework/sessions,framework/views,logs}
export APP_ENV=local APP_DEBUG=false
export APP_URL=http://127.0.0.1:8000
export APP_KEY="$(php artisan key:generate --show)"
export LARAVEL_STORAGE_PATH="$QA_ROOT/storage"
export DB_CONNECTION=sqlite DB_DATABASE="$QA_ROOT/browser-qa.sqlite"
export SESSION_DRIVER=database CACHE_STORE=file QUEUE_CONNECTION=database
export BROWSER_QA_SEED=1
export BROWSER_PASSWORD="$(php -r 'echo bin2hex(random_bytes(24));')"
touch "$DB_DATABASE"
php artisan migrate --force
# Optional: a four-second synthetic portrait MP4 for caption review/render tests.
# This uses a checked-in transcript, NOT automatic speech recognition or genuine speech.
export SUBTITLE_FFMPEG=/absolute/path/to/ffmpeg
export SUBTITLE_FFPROBE=/absolute/path/to/ffprobe
"$SUBTITLE_FFMPEG" -nostdin -v error -y \
  -f lavfi -i 'color=c=0x253e50:s=360x640:r=24:d=4' \
  -f lavfi -i 'sine=frequency=440:duration=4' \
  -c:v libx264 -pix_fmt yuv420p -c:a aac -shortest "$QA_ROOT/review.mp4"
export BROWSER_QA_MEDIA="$QA_ROOT/review.mp4"
php tests/browser/seed.php
export BROWSER_PROVIDER_FIXTURES="$LARAVEL_STORAGE_PATH/app/browser-provider-fixtures.json"
```

Start these as separate processes with the same environment:

```sh
node tests/browser/provider.mjs
php -S 0.0.0.0:8000 -t public
# Required only for the optional real approved-caption render:
php artisan queue:work subtitles --queue=subtitles --sleep=1 --tries=1 --timeout=7200
```

Then:

```sh
npx playwright install chromium  # plus OS dependencies where needed
export BROWSER_BASE_URL=http://127.0.0.1:8000
export BROWSER_QA_RENDER=1       # only with optional media + a working subtitle worker
npm run test:browser
```

For an already installed browser, set `BROWSER_EXECUTABLE` to its path; optional `BROWSER_ARGS` is a JSON array of launch arguments. This sandbox used Chromium 153 with its bundled runtime libraries. Without media, the subtitle case explicitly skips; without opt-in/password, the suite explicitly skips rather than altering an arbitrary app. Fresh seed data is necessary when repeating the review case because approval is intentionally immutable.

## Verified and not verified

The Chromium workflows cover desktop authenticated pages, mobile widths, reduced motion, one-question creation, short generation, long opening → next batch → ending → Part 2, saved library navigation, native editable-field paste, and caption editing/save/reload → explicit approval → real queued FFmpeg render/download. Generation uses a deterministic loopback provider through the real HTTP/controller/persistence pipeline, not a live model.

The separate real-media integration renders portrait and landscape captions and checks caption/highlight pixels, safe margins, unchanged source hashes and preserved audio stream hashes.

**Still requires deployment/live testing:** real AI/TTS availability and output quality; actual Whisper recognition on real recordings and production CPU/disk limits; installed MV3 extension permission/popup/service-worker behavior and Google Flow's current DOM/clipboard policies; delivery of password-reset mail; concurrent multi-browser/multi-worker infrastructure behavior. Node tests cover the extension parser, paste and repair-link helpers, not a fully installed extension. No external provider keys or real user media were used, and no rendered-video quality guarantee is implied.
