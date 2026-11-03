# Video Subtitle Studio

## What was added

An independent authenticated `/subtitles` workspace, linked from the existing sidebar. Upload a finished MP4 from any video generator, choose animated karaoke (default) or plain white captions, watch background stages, review/edit the transcript, explicitly approve its exact wording, then render, preview, and download. Existing prompt generation, QA/Auto-Fix, TTS, wizard and extension behavior are not replaced or coupled to transcription. The extension remains local-only: it does not upload videos or submit generation requests.

The default style uses DejaVu Sans bold, white text, yellow current-word highlighting, a dark outline, subtle shadow, bottom-center alignment, reference font size 46, 26-character target wrapping, and 110px bottom margin on a 720×1280 reference. Font/outline/margins scale with the frame. Conservative glyph-width estimates additionally constrain wide text. Complete sentences have **one stable ASS event**, explicit line breaks, and per-span time-based colour transforms (45ms maximum easing); completed words return to white. There is no per-word event replacement, position animation, flashing, or popping. Very long sentences shrink to a minimum reference font size 24; if they still require more than four lines, processing fails rather than silently clipping text or inventing timing. English is the first supported language; adding languages requires compatible models and representative timing/render tests, not just changing the label.

## Quality contract (important)

Burning subtitles necessarily changes pixels and requires decoding/re-encoding video. **Bit-for-bit 1:1 video is impossible.** The default uses `libx264 -crf 0`, a lossless H.264 video encode, preserves source pixel format for supported SDR YUV inputs, does not resize, and uses timestamp passthrough rather than forcing a frame rate. Source display rotation is applied by FFmpeg. It does not restore quality already lost in the source. libass still changes subtitle-region pixels and uses its own rendering pipeline; this is not a claim of mathematically identical source pixels outside subtitles for every codec/colour configuration.

AAC, MP3, and ALAC audio is stream-copied. Other codecs use AAC 320k and are **not lossless audio**. The first non-cover video track and first audio track are used; extra tracks, original subtitles and metadata are not carried into the deliverable. The original MP4 remains untouched. HDR/PQ/HLG and unsupported pixel formats are rejected instead of silently tonemapped. Lossless H.264 can be much larger and some browsers cannot play all lossless/10-bit/4:4:4 profiles: download and use VLC if necessary. Defaults limit input to 500 MiB, 10 minutes, 4096px per dimension, 9 million pixels, and 60 fps. Output is capped at 2 GiB by FFmpeg; a capped/truncated render fails, never becomes the downloadable result. Reserve at least the output cap plus 128 MiB free disk space per running render. Two active jobs, twenty retained videos and 2 GiB of original uploads per user limit resource growth. Deploy with OS CPU/memory/disk quotas as well.

## Server installation

Production needs PHP 8.3+ with normal Laravel dependencies, Python, FFmpeg **7+** with libass/libx264, DejaVu fonts, a pre-provisioned local OpenAI Whisper environment/model, database queues, and a scheduler. No paid API is used. Install dependencies on your Ubuntu server (administrative commands, not run in this workspace):

```sh
sudo apt update
sudo apt install ffmpeg fonts-dejavu-core fontconfig python3-venv
# Verify FFmpeg version; older Ubuntu versions may need a maintained FFmpeg 7+ package.
sudo python3 -m venv /opt/whisper
sudo /opt/whisper/bin/pip install openai-whisper
sudo mkdir -p /opt/whisper/models
sudo /opt/whisper/bin/python -c "import whisper; whisper.load_model('base.en', download_root='/opt/whisper/models')"
fc-match 'DejaVu Sans:style=Bold'
/usr/bin/ffmpeg -version
/opt/whisper/bin/pip show openai-whisper
```

Install PyTorch appropriate to your CPU/GPU following its distribution instructions if needed. Download the model **during provisioning**, not on the first user request. This model download requires network access outside this workspace's permitted hosts. Give the dedicated unprivileged worker read access to the model cache and read/write access only to Laravel's storage/cache/database locations. Run decoder/transcription workers with no outbound network, no privilege, memory limits, and disk quotas. Keep FFmpeg and Python dependencies security-patched.

Environment settings are in `.env.example` and `config/subtitles.php`:

```dotenv
SUBTITLE_FFMPEG=/usr/bin/ffmpeg
SUBTITLE_FFPROBE=/usr/bin/ffprobe
SUBTITLE_WHISPER=/opt/whisper/bin/whisper
SUBTITLE_MODEL_DIRECTORY=/opt/whisper/models
SUBTITLE_WHISPER_MODEL=base.en
SUBTITLE_WHISPER_DEVICE=cpu
SUBTITLE_THREADS=2
SUBTITLE_MAX_UPLOAD_KB=512000
SUBTITLE_MAX_DURATION=600
SUBTITLE_MAX_OUTPUT_BYTES=2147483648
SUBTITLE_RETENTION_DAYS=7
SUBTITLE_FONT="DejaVu Sans"
```

```sh
composer install
php artisan migrate --force
php artisan config:cache
php artisan subtitles:doctor
php artisan queue:work subtitles --queue=subtitles --timeout=7200 --tries=1 --sleep=3 --max-jobs=1
# Run the queue worker under Supervisor/systemd so it restarts after each job.
# Install the normal Laravel scheduler cron (once per minute):
# * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

Do **not** use `queue:listen`, `dispatchSync`, or a web-request worker. `subtitles` is a dedicated database queue connection with retry-after 7500 seconds, greater than the job timeout 7200s. Each subprocess has an extraction/probe timeout of 180s or a transcription/render timeout of 3000s. Use process-group-aware service supervision so killed workers also terminate FFmpeg/Whisper children. Run one or a deliberately bounded number of workers. Existing queue settings are unchanged. Configure PHP `upload_max_filesize` ≥500M, `post_max_size` >500M and matching reverse-proxy upload/body timeouts. Queue processing is separate from upload request timeouts. Storage and FFmpeg must be on a local filesystem; this pipeline does not support S3 disks.

## Lifecycle and security

- Type/extension/MIME/size validation; actual container/streams/duration/resolution/frame-rate/colour checks in ffprobe before transcription. MP4/MOV demuxing is forced, protocols restricted to local files. Never interpolate upload names into commands. Symfony Process argument arrays are used; the ASS filter uses a fixed filename in a UUID-owned private working directory.
- Private disk `storage/app/subtitles-private`; no public storage links or unauthenticated artifact URLs. Preview/download/status/show all check owner identity and normal active-account middleware. Preview supports HTTP byte ranges. Output download names use server-generated UUIDs.
- Per-owner transactional upload token prevents double submission. The worker takes a cache lease plus an atomic `uploaded` state claim; refresh/duplicate delivery cannot rerender completed/failed/in-progress jobs. Failed videos are not blindly retried. Upload a new video after resolving dependency/limit errors.
- Meaningful stages: uploaded, extracting audio, transcribing, **awaiting review**, **approved / queued**, generating subtitles, rendering, completed, failed. Polls every four seconds, backs off on errors, stops during human review and on terminal states/session expiry; approval resumes polling. No fake percentage. Jobs continue if the browser is closed.
- Audio WAV/Whisper JSON/ASS/partial render removed after success **and failure**. The original is retained on failure until retention expires. Full output is published only after FFmpeg success and output-duration validation, via atomic rename. Bounded command diagnostics (last 64 KiB) stay private; end-user failures are sanitized. Transcription text is not sent to an API.
- `subtitles:maintain` runs every ten minutes. It idempotently re-dispatches stranded uploaded rows, fails uploads waiting over a day, marks abandoned processing jobs failed after the worker lease/timeout, re-dispatches approved renders stranded over five minutes (never unapproved drafts), fails approved queue waits over a day, and deletes terminal records/artifacts **and waiting review drafts** after the configured retention age. Waiting review is exempt from processing timeouts; the upload-age retention deadline still applies. Orphan directories from deleted/expired accounts are removed after 24h (not during a new upload transaction). Maintenance respects per-video locks. A disabled scheduler means retention/recovery does not run.
- No content-editing UI or automatic Whisper text correction is included. Review output before publishing, particularly names/accents/pauses. No recognizable/word-timed speech fails honestly instead of inventing captions.

## Files

New: `app/Models/SubtitleVideo.php`, `app/Jobs/ProcessSubtitleVideo.php`, `app/Http/Requests/StoreSubtitleVideoRequest.php`, `app/Http/Controllers/SubtitleVideoController.php`, `app/Services/Subtitles/*`, subtitle migration/config, two Blade views, dedicated JS/CSS, fixtures/tests and standalone smoke scripts.

Integration-only edits: `routes/web.php`, `routes/console.php`, `config/queue.php`, `config/filesystems.php`, layout sidebar/stacks, `.env.example`, and README link. No prompting method, extension behavior, provider credentials or existing TTS settings were changed.

## Validation and limits of evidence

Executed in this workspace:

- PHP-WASM 3.1.60 syntax checks for new/edited PHP, controllers, requests, job, migration, config, routes, and tests.
- Standalone **real PHP generator** assertions for ASS timestamp rounding, one-event sentence output, wrapping, and override escaping.
- Real FFmpeg **7.0.2 static**, libass/libx264 (obtained via the `imageio-ffmpeg` Python wheel, outside Git): generated 4-second test MP4s, burned real generated ASS, decoded frames at 0.5/0.9/2.3/3.0 seconds. Tested 720×1280, 360×640 and 1280×720. All three passed: safe margins, fixed sentence bounds (within 2px for chroma-edge differences), moving yellow highlight, unchanged original SHA256, equal compressed AAC audio packet hashes. Portrait frames were visually inspected. Audio was a test tone, **not known speech**. This verifies rendering, not Whisper recognition or real-world A/V word accuracy.
- `node --check public/js/subtitles.js`, Python smoke syntax checks, and `git diff --check`: passed.
- Existing extension regressions: `node --test chrome-extension/tests/*.test.js` — **29 passed, 0 failed**. Extension code and root `prompt.txt` are unchanged.

Reproducible render smoke, no models or Python dependencies needed:

```sh
python3 scripts/check-subtitle-render.py --ffmpeg /usr/bin/ffmpeg
# Alternative when native PHP is unavailable:
python3 scripts/check-subtitle-render.py --ffmpeg /path/to/ffmpeg \
  --php-command 'npx --yes --package=@php-wasm/cli@3.1.60 php-wasm-cli'
```

Laravel tests to run on the server/dependency-equipped CI:

```sh
php artisan test --filter=AssSubtitleGeneratorTest
php artisan test --filter=SubtitleStudioTest
php artisan test --filter=VideoSubtitleProcessorTest
SUBTITLE_TEST_FFMPEG=/usr/bin/ffmpeg php artisan test tests/Integration/SubtitleRenderTest.php
php artisan test  # includes existing workflow regressions
```

**Outstanding:** Composer/vendor/PHPUnit and Whisper/PyTorch/models are absent here; native PHP 8.4 is now available for syntax and standalone service checks. No end-to-end Laravel upload → queue → Whisper → download run or production server/browser test has been completed. The Laravel authorization/queue/cleanup tests are written but not executed in this workspace. A real English speech video must be tested on the deployment server, including long sentences, silence/no-audio, landscape/portrait/rotation, browser preview compatibility and a downloaded full-quality output. Existing workflow tests must also pass there. Do not treat the feature as production-accepted until those checks pass. There was no supplied approved-reference video to compare pixel-for-pixel; styling follows the written specification and the tested fixture above.


## Mandatory human review (new)

The transcription job now stops at `awaiting_review`, persisting original timed words and an editable draft in private database columns. No ASS file or captioned video is produced at this stage. Temporary audio/Whisper JSON files are cleaned up, not the persisted transcript or original video. Read the full draft, page through 80 timed words at a time, and use the original video / per-word replay buttons to listen again. You may take your time within the displayed retention deadline; this is a durable pause, not an artificial delay or an auto-approval timer. Waiting reviews count toward the two-active-video quota.

- Text-only corrections preserve the original ordered timestamps. Up to 120 characters per row, nonempty, no control characters/newlines. A replacement phrase shares that original token's timing; it does not acquire newly aligned word timings. For substantial rewrites or timing problems, upload a new transcription. ASR timing/spelling quality is not guaranteed.
- **Save draft** persists changes and does not dispatch rendering. Unsaved edits survive paging in that tab, not a page reload. Reload warns before discarding unsaved changes. Editing clears the approval checkbox.
- **Approve transcript & render video** requires a separate explicit checkbox confirmation and submits the entire currently edited draft. Server-side ownership, list/count/text validation, row locks and revision checks reject stale concurrent edits. Layout errors remain in review for correction. The checkbox is an owner's attestation, not proof that a human actually read every word.
- Approval freezes an immutable snapshot, revision, timestamp, approving owner and SHA-256 fingerprint. Repeated identical approval requests are idempotent; later edits are rejected. A separate `RenderSubtitleVideo` job claims only approved rows and the processor independently verifies the approval before any media command. Merely dispatching a render job, saving a draft, closing the page or running maintenance cannot approve a transcript. The renderer reads the frozen snapshot, never a live mutable draft.
- Transcript/original endpoints are authenticated, owner-only and private/no-store; captions and original text are inserted as plain text in the editor. Existing ASS escaping protects corrected text too. No provider keys, public transcript URLs or remote transcription services are introduced.
- Retention is measured from upload creation, not last edit. Expired review/save/approval requests return 410; the scheduler removes expired drafts and originals. Browser/editor access still requires a valid active account/session. There is no automatic approval or automatic extension of account lifetime.
- Previously completed videos remain downloadable; they are not retroactively certified as having passed this new review gate.

### Deploying the approval gate

1. Stop the old subtitle workers **before enabling the new workflow**, using process-group-aware supervision. Do not leave an old, already-loaded monolithic worker rendering without this new gate after cutover. `queue:restart` alone only exits after its current job; coordinate/drain or safely terminate existing work before activation.
2. Deploy and run `php artisan migrate` (includes `2026_11_02_000002_add_mandatory_subtitle_review`). Restart the dedicated workers with the new code, and keep the ten-minute scheduler enabled. New uploaded/queued transcription jobs enter review. Old in-progress records interrupted during cutover may need an administrator-assisted fresh upload; do not resume old worker code to finish them.
3. Verify a real upload stops at review with **no** `captions.ass`/`output.mp4`; save/reload spelling edits, close/reopen the page, then confirm and approve. Verify the rendered wording matches exactly and other accounts cannot access the transcript, original or approval endpoint. Check stale-tab edits, expiry, rendering failures, broker recovery and concurrent duplicate deliveries on the actual deployment.

### Review verification in this workspace

- `php scripts/check-subtitle-review.php`: real pure transcript/ASS-service assertions (no framework or media processes).
- `node --test tests/js/subtitle-review.test.js`: editor tests with lightweight DOM/request doubles, not a browser.
- New `tests/Feature/SubtitleReviewTest.php`, updated pipeline/Studio tests and `tests/Unit/TranscriptReviewTest.php` cover ownership, drafts, approval/retry/revision guards, direct-dispatch bypass, snapshot tampering, retention/recovery and late failure callbacks. Run these using the normal PHPUnit/Laravel environment after installing Composer dependencies.

Native PHP 8.4 is available for this change's syntax/service checks, but Composer/vendor dependencies are still unavailable here. **The Laravel/HTTP/database/queue tests and real browser/Whisper/FFmpeg review-to-render pipeline have not been executed for this change.** Earlier ASS-render smoke checks do not establish end-to-end approval-gate correctness.

## Approval controls recovery (November 2026)

The transcript form uses explicit full-draft validation instead of native form
validation silently preventing its submit handler. Missing confirmation or an
invalid word (including on another page) produces visible feedback; confirmation
is still required both in the browser and on the server. Saving a draft never
approves it. Approval shows an in-progress message and disables repeat clicks.
Transcript/save/approval HTTP requests time out after 30 seconds instead of
leaving controls locked indefinitely. A timeout is not proof that approval failed:
the app checks job status before retrying, and the server's exact-wording
idempotency behavior is unchanged. Edits remain in the tab after a failed request.
Expired sessions, throttling, malformed responses and missing revision/status
confirmations are reported rather than silently hiding review or corrupting the
local revision. Retention and server word/timing/layout checks remain intact.

The extension is still only a local prompt handoff, not a subtitle uploader,
transcription tool or approval agent. Version 1.7.1 exposes app-site reconnection
even with an existing stored prompt, and reports popup action failures rather
than leaving the main button disabled. Reload the unpacked extension to update.
No provider, prompt-generation, queue-worker or subtitle-rendering settings change.

Targeted controls tests:

```sh
npm test
BROWSER_EXECUTABLE=/path/to/chromium node --test tests/ui/subtitle-controls.test.mjs
php scripts/check-subtitle-review.php
php scripts/subtitle-ass-smoke.php
```

The standalone Chromium tests use the actual transcript Blade form and browser
modules with local HTTP fixtures. They check approval validation, lost-response
reconciliation, editable error/retry behavior, and extension reconnect without
changing the stored prompt. They do **not** run Whisper, a Laravel queue, or
FFmpeg; the existing deployment/worker prerequisites above still apply.
