# VeoPrompter

VeoPrompter is a Laravel 13 prompt studio for creating detailed, continuity-first video prompts for Google Flow and compatible video tools. It supports educational explainers, cinematic stories and short films, documentaries, product or brand videos, music videos, travel reels, and custom creative briefs. It creates **text prompts only**—never images or videos—and preserves the prompting method in the repository's root [`prompt.txt`](prompt.txt). The runtime copy at `resources/prompts/master-spec.txt` is tested against that source so the master specification stays in sync.

## What it does

- Creates educational story plans with evidence-conscious accuracy guidance, a closed canonical cast and character/world bibles, a character-reference **image prompt**, continuity maps, and independently copyable clip prompts. Claims distinguish established facts, simplifications, uncertainty, and dispute; no source verification or citations are claimed unless sources were actually consulted. Each clip has one primary idea and labels literal scenes versus cutaways, diagrams, overlays, or illustrative metaphors. Every clip and shot resolves visible characters to the canonical bible, repeats their identity lock, and explicitly rules out accidental extras, duplicates, substitutions, or variants; non-educational modes stay format-aware without forcing lessons or mascots.
- Continues a video from either a written last-frame description or an attached still image; at least one starting-state source is required.
- Plans each clip handoff as Type A direct continuation, Type B visible action bridge, or Type C intentional, non-seamless narrative/temporal change. Every prior outgoing type must match the next incoming type, and the handoff compares cast, poses, objects, materials/process stage, environment, camera/motion, lighting, and sound. Every incoming continuity state copies the prior ending exactly. Type A/B openings also copy the prior presentation state; Type B changes begin only after that matched opening, while Type C visibly motivates its purposeful transition. Meaningful Type C clips count within the selected total and are never filler. Type A/B use the verbatim continuation instruction only when the actual prior frame will be supplied as an image input; otherwise prompts use text-only opening-state wording. Type C does not use that quote; Extend Video uses it only when a reference-frame image is actually attached. The overview advises using the actual prior rendered final frame as a reference when the target video tool supports it, without promising a pixel-perfect seam. The system tracks causal states so results become plausible next inputs and missing or impossible stages are not mislabeled as seamless.
- Times every spoken line to a same-clip visible action using clip-relative speech maps and a conservative words-per-second estimate. A deterministic server-side audit checks clip labels/counts, exact/manual duration settings, the 10-second AI limit, handoff/state strings, and speech windows; it requests one targeted corrective generation for repairable findings and returns any unresolved items separately from the copy-ready prompt. The review is persisted with prompt history. It is not a semantic fact-check or rendered-video test: source verification, physical feasibility, pronunciation, visual/narration alignment, and actual generated frames still require human review.
- Requires sign-in before opening the prompt workspace or generating a prompt.
- Lets signed-in users create projects with reusable preferences for default video type, audience, visual style, character type/profile, clip count and AI count strategy, video and extension duration, aspect ratio, learning depth for explainers, spoken-audio mode, language, narrator voice/accent/tone, music, and sound effects. Spoken audio can be off, a voice-over narrator, or in-scene character dialogue. Every spoken line maps to a visible action/result in that same clip; lines cannot narrate unseen, off-screen, prior, or future action. Invisible educational processes require an accurate, clearly identified in-clip visualization. New projects default to curious children and families, short educational animation, polished family-friendly 3D, an AI-directed minimum-practical clip count, vertical 9:16 framing, a female American English voice-over narrator, and subtle music/effects. Users can choose a fixed count from 2–8 or let AI use either the minimum practical count or a quality-focused count with more meaningful stages when they improve clarity; neither AI option has an arbitrary upper cap. AI-selected durations are chosen independently for each clip and extension: simple beats can stay around 2–3 seconds, moderately layered beats around 4–6, and complex coordinated action, transformations, camera moves, multiple subjects, or speech can receive more time up to 10 seconds. Durations are never padded, and fixed manual choices remain exact. Preferences can be edited later and are applied server-side to every generation in that project.
- Lets administrators control clip-duration and extension-duration questions globally and per user account. Both global controls default off; when a question is hidden, AI still budgets each beat according to its complexity—keeping simple actions short while allowing complex action or speech more time, up to 10 seconds, without padding.
- Saves each completed generation to the selected project and the user's private prompt library, where users can search, copy, download, revisit, and delete prompt kits.
- Generates device-independent Gemini TTS narrator previews for all 240 language × voice × accent × tone combinations in voice-over mode. Each combination is generated on first request, converted to browser-friendly WAV, and cached privately for later playback; preview audio is illustrative and is not attached to the final video prompt. Character-dialogue voices are instead described individually in the generated prompts and remain stable across clips.
- Gives administrators a registered-user directory with recent online/offline presence and access to each user's saved prompt outputs. Administrators can create customer or admin test accounts that expire three hours after creation.
- Keeps provider configuration in an administrator-only Control room. Regular users cannot add or change AI providers.
- Stores prompt-generation and dedicated Google TTS credentials encrypted with Laravel's `APP_KEY`; no provider key is returned to the browser.

Generated prompts improve the odds of consistency but cannot guarantee that a video model will reproduce every detail exactly. Use reference images and final-frame continuation in Flow where available. See [`docs/auto-fix-reliability.md`](docs/auto-fix-reliability.md) for the canonical QA/Auto-Fix contract, synthetic regression results, validation commands, and remaining limitations.

## Chrome extension for Google Flow

The optional Manifest V3 extension lives in [`chrome-extension/`](chrome-extension/). It splits a prompt into Sections 1–7 followed by every numbered clip in order, with continuity notes and final audits attached to the final clip. It understands both the app's standard Section 8 clip list and the newer section-per-clip prompt layout; the popup shows incoming Type A/B/C guidance and checks each prior `OUTGOING HANDOFF TYPE:` against the next `TRANSITION TYPE:`, flagging missing or mismatched contracts. For prompts using the structured contract, it also compares opening/ending state strings and checks time-coded speech windows, word-time estimates, and remaining clip duration. These local text checks do not verify factual truth or the generated video; the popup says so explicitly. Dedicated Type C transition clips are identified as intentional changes. When a prompt includes `VISIBLE CHARACTER CAST:` metadata, the popup shows that cast lock for the next part while leaving the full character identity instructions inside the copied prompt unchanged. Character-specific voice profiles likewise pass through unchanged; the extension does not synthesize character samples. AI-directed counts and durations need no special extension settings. Unrecognized formats stay together as one part. Flow can be closed while the extension stores or prepares a prompt.

For local testing:

1. Open `chrome://extensions`, enable **Developer mode**, and choose **Load unpacked**. Select this repository's `chrome-extension/` folder. If it is already installed unpacked, click **Reload** to apply updates.
2. Open the VeoPrompter site in Chrome, open the extension popup, and choose **Connect this site**. Approve the site-specific permission. This enables Send to Flow on the connected site; you only need to connect once per site origin.
3. Generate a prompt and click **Send to Flow**, or open any saved prompt in the prompt library and click its **Send to Flow** button. The prompt is stored in the extension's local Chrome storage, not uploaded to another service.
4. On the destination tab, open the extension popup and click **Copy** for the next part. The extension copies the text and arms a one-time paste on that tab. Click a text input, textarea, or editable field to insert the part at the cursor. In Flow, review the text and click Flow’s Send button yourself. Repeat for each clip; Sections 9–10 are included with the final clip.
5. If automatic insertion is unavailable, use the copied text with the browser’s normal **Paste** command. You can also prepare the sequence with Flow closed.
6. Use **Go back one part** to rewind or **Start this prompt over** to return to the project context. These controls update the saved sequence and cancel any pending one-time paste; text already inserted into a page is left unchanged. Sending a new prompt from VeoPrompter replaces the active prompt stored in the extension.

The app-site permission is limited to the VeoPrompter origin you connect. For one-time insertion, the extension uses Chrome’s temporary **activeTab** access only after you open the popup on the destination tab and click Copy; no persistent destination-site permission is requested. Clipboard-write access provides the manual-paste fallback. The extension never submits a prompt or starts video generation.

## Requirements

- PHP 8.3 or newer, with Laravel's required extensions (including PDO SQLite for the default local database)
- Composer 2
- A Gemini API key, or an OpenAI-compatible API key and public HTTPS endpoint for prompt generation
- A separate Google API key with Gemini text-to-speech access to enable generated voice previews
- A working mail transport in production if users should receive password-reset emails
- Node.js and npm are optional; the interface ships as local CSS and JavaScript

## Local installation

```bash
composer install
cp .env.example .env
php artisan key:generate
mkdir -p database
touch database/database.sqlite
php artisan migrate
php artisan serve --host=0.0.0.0
```

Open `http://127.0.0.1:8000`. The default development mailer writes reset emails to the application log; configure mail delivery to receive them in an inbox.

## Production deployment

1. Install the PHP/Composer requirements on a host that can run Laravel. Configure the web server's document root to the app's `public/` directory; do not expose the repository root.
2. Set production values in the host's secret/environment manager. At minimum, set `APP_ENV=production`, `APP_DEBUG=false`, the public HTTPS `APP_URL`, `SESSION_SECURE_COOKIE=true`, and a newly generated, private `APP_KEY`. Never regenerate or change the key after provider credentials have been saved: those credentials are encrypted with it.
3. Configure a persistent database. SQLite is convenient for a single-instance deployment: create `database/database.sqlite`, give the app permission to write the file and its directory, and include it in protected backups. Use a managed database for multi-instance deployments. Configure persistent session/cache/queue stores as appropriate for your host.
4. Configure `MAIL_MAILER` and the required `MAIL_*` settings for a real mail provider so password recovery can deliver reset links. Keep mail and AI credentials in the platform's secret manager or environment—not in Git.
5. Run the deployment commands:

   ```bash
   composer install --no-dev --optimize-autoloader
   php artisan migrate --force
   php artisan config:cache
   php artisan route:cache
   ```

   Ensure `storage/` and `bootstrap/cache/` are writable by the PHP process. If your deployment platform runs multiple web instances, share or externalize the database, sessions, cache, and any other state that must be consistent between them.
6. Create the first administrator from a secure server terminal (there is no public administrator-registration route):

   ```bash
   php artisan veoprompter:admin:create "Site Owner" admin@example.com
   ```

   The command asks for a password without echoing it; use at least 12 characters. If the address already belongs to an account, it enables admin access without changing that account's password. Sign in at `/login` and open **Control room**.
7. In **Control room → AI provider connection**, choose Google Gemini or an OpenAI-compatible provider and enter its API key. The model list loads after you finish typing; you can also click **Load available models** to use a key already saved for that same provider and endpoint. Select a model, click **Test selected model** to get a short text reply, then save the connection. The test does not save settings or create a prompt-history item. If an API does not expose a model catalogue, you can enter its model ID manually. The key is sent to this app's server for model discovery/testing; the browser never calls the provider directly. The optional extension-frame image is forwarded for prompt generation and not retained.
8. In **Control room → Voice sample generation**, enter a separate Google API key with Gemini TTS access and the enabled speech model ID. The key is encrypted and used only by the server. Each of the 240 supported preference combinations is generated only when first previewed and then cached privately; no bulk generation runs automatically.
9. Use **Control room → Project duration choices** to show or hide the two duration questions for all accounts. Both global switches default off. Enable a global switch and the matching account switch under **User management → Manage account** to show that question; users can then choose a fixed length or let AI decide. When AI selects timing, simple beats can stay short while coordinated action, transformations, camera movement, multiple subjects, or speech receive more time when warranted, up to 10 seconds and without padding. If either switch is off, duration is still chosen from the beat's complexity and never exceeds 10 seconds.

Public account registration is enabled. Sign-in is required to open projects and generate prompts. Configure your deployment's privacy notice and account-retention policy for your audience. Administrators can review user activity and create three-hour temporary test accounts from **User management**.

## Configure an AI provider

### Google Gemini

- Provider: **Google Gemini**
- Model: `gemini-2.5-flash` (or another model supported by your key)
- API key: a key created for your Google AI Studio project

VeoPrompter calls Gemini's `generateContent` endpoint from the Laravel server.

### Gemini text-to-speech previews

- Configure a separate Google API key in **Control room → Voice sample generation**. It must have access to a Gemini TTS model; samples preview the voice-over narrator only, not character-dialogue voices.
- Default model ID: `gemini-2.5-flash-preview-tts` (change it in the Control room if your project uses a different enabled TTS model).
- Samples are generated on demand from fixed short examples, normalized to WAV, and cached privately by model and all four preference values.
- The browser never receives the TTS credential and no system-installed speech voice is required.

### OpenAI-compatible API

- Provider: **OpenAI-compatible API**
- Base URL: for example `https://api.openai.com/v1` (the app appends `/chat/completions`)
- Model: for example `gpt-4.1-mini`
- API key: the key for the selected provider

The endpoint must use public HTTPS and may not include embedded credentials. The model selector fetches model IDs from the configured provider (Gemini results are filtered to models that support `generateContent`); if the provider does not support discovery, enter its model ID manually. The short test verifies that the selected key, endpoint, model, and text response work before saving. Image input is not required for the test; the selected provider/model must support image input only if you want to attach a final frame in extension mode.

The API key field is write-only in the UI: after saving, VeoPrompter shows only whether a key exists. A saved key is reused for model discovery or tests only with its original provider and endpoint; changing providers/endpoints requires re-entering it. Temporary keys entered for discovery/testing are not saved. Stored credentials use Laravel's encrypted cast. **Back up `APP_KEY` securely and do not change it after saving provider keys**; ciphertext cannot be decrypted with a different key.

## Accounts, history, and data handling

- Registration, sign-in, sign-out, profile updates, password changes, and password reset are available from the account pages. Passwords are hashed by Laravel; reset links are delivered through the configured mail transport.
- A valid signed-in account is required to create projects and generate prompts. Project preferences are stored separately from generation history, and each saved prompt kit is linked to its project. Users can edit project preferences without rewriting older generations.
- Prompt kits contain the submitted text brief, generated text response, provider/model label, and a flag indicating whether a reference frame was used. The uploaded image bytes are **not** stored. Users can open and delete their own kits; another customer cannot read or delete them through the application. Administrators can view saved prompts from the user-management area.
- Administrator-created test accounts (customer or admin role) have a server-enforced expiration exactly three hours after creation. Expired test accounts are logged out and cannot sign in again. Their generated password is shown only on the one-time account-creation result.
- Presence is a best-effort activity indicator: the browser refreshes the signed-in user's last-seen timestamp periodically, and admins see “online” when a user has been active within the last three minutes. It is not a guarantee that a person is watching the page.
- The admin setup form sends a key to this server over HTTPS for discovery/testing; provider requests then run from the server over HTTPS. Keys are not placed in page markup or prompts, returned in normal application responses, or written to application logs.
- Generation, registration, sign-in, and reset-link endpoints are rate-limited. Uploaded extension frames are limited to 8 MB and accepted as JPG, PNG, or WEBP.
- Use HTTPS in production, keep `APP_DEBUG=false`, restrict database/server access, and protect backups containing user prompt history and `APP_KEY`.

## Tests

```bash
php artisan test
node --test chrome-extension/tests/*.test.js
```

The test suite covers the master prompt's synchronization with root `prompt.txt`, canonical character rosters and per-shot identity locks, Type A/B/C handoffs, process-state continuity, source-honesty instructions, factual-review limitations, timed speech-map bounds and overrun correction, visual-representation labels, and persistence plus saved-history display of separate quality reviews, alongside sign-in-gated project workflows, reusable owner-scoped settings, voice continuity, clip-count strategies, duration policies, private history, temporary accounts, admin-only provider configuration, encrypted credentials, and provider request construction. The extension tests cover grouped and section-per-clip parsing, handoff/state mismatches and missing steps, Type B transition acceptance, timed narration overrun, cast-lock metadata, per-character voice profiles, unchanged copy text, and safe click-to-paste. Run the PHP suite with PHP, Composer dependencies, and PDO SQLite installed; run extension tests with Node using the command below.

## Project layout

- `prompt.txt` — the source master prompting method.
- `resources/prompts/master-spec.txt` — the runtime copy of that specification, covered by a synchronization test.
- `app/Services/PromptGenerationService.php` — server-side prompt instructions, one targeted correction call, and Gemini/OpenAI-compatible adapters.
- `app/Services/PromptOutputAuditor.php` — deterministic structure, handoff/state, duration, and speech-window checks; its human/source-review caveats are stored separately from copyable prompt text.
- `app/Http/Controllers/StudioController.php` — authenticated project-scoped input validation, generation, and history persistence.
- `app/Http/Controllers/StudioProjectController.php` and `app/Support/ProjectSettings.php` — saved project preferences and owner-scoped project workflows.
- `app/Http/Controllers/AuthController.php`, `PasswordResetController.php`, `AccountController.php`, and `PresenceController.php` — account lifecycle, recovery, and activity heartbeats.
- `app/Http/Controllers/PromptHistoryController.php` — private prompt library, search, and owner-checked detail/deletion.
- `app/Http/Controllers/Admin/AiSettingsController.php` and `UserManagementController.php` — administrator-only provider setup, registered-user activity, prompt review, and temporary test accounts.
- `resources/views/` and `public/css`, `public/js` — responsive studio, account, and history interface with copy/download helpers. GSAP 3.15.0 and ScrollTrigger are self-hosted under `public/js/vendor`; Inter 5.3.0 variable fonts are self-hosted under `public/fonts` with their OFL license. The motion layer respects reduced-motion preferences.
