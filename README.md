# VeoPrompter

VeoPrompter is a Laravel 13 prompt studio for creating detailed, continuity-first video prompts for Google Flow and compatible video tools. It supports educational explainers, cinematic stories and short films, documentaries, product or brand videos, music videos, travel reels, and custom creative briefs. It creates **text prompts only**—never images or videos—and preserves the prompting method in the repository's root [`prompt.txt`](prompt.txt). The runtime copy at `resources/prompts/master-spec.txt` is tested against that source so the master specification stays in sync.

## What it does

- Creates educational story plans with the existing accuracy check, character and world bibles, a character-reference **image prompt**, continuity maps, and independently copyable clip prompts; non-educational modes use format-aware treatments without forcing lessons or mascots.
- Continues a video from a written final-frame description and, optionally, a supplied still image.
- Carries character, scene, camera, object, lighting, and sound details across clips; continuation prompts include the required verbatim handoff instruction from the master specification.
- Requires sign-in before opening the prompt workspace or generating a prompt.
- Lets signed-in users create projects with reusable preferences for default video type, audience, visual style, character type/profile, clip count, video and extension duration, aspect ratio, learning depth for explainers, narration, language, voice, accent, tone, music, and sound effects. Preferences can be edited later and are applied server-side to every generation in that project.
- Lets administrators control clip-duration and extension-duration choices globally and per user account. When either control is off, the corresponding question is hidden and prompts use the 8-second default.
- Saves each completed generation to the selected project and the user's private prompt library, where users can search, copy, download, revisit, and delete prompt kits.
- Generates device-independent Gemini TTS previews for all 240 language × voice × accent × tone combinations. Each combination is generated on first request, converted to browser-friendly WAV, and cached privately for later playback; preview audio is illustrative and is not attached to the final video prompt.
- Gives administrators a registered-user directory with recent online/offline presence and access to each user's saved prompt outputs. Administrators can create customer or admin test accounts that expire three hours after creation.
- Keeps provider configuration in an administrator-only Control room. Regular users cannot add or change AI providers.
- Stores prompt-generation and dedicated Google TTS credentials encrypted with Laravel's `APP_KEY`; no provider key is returned to the browser.

Generated prompts improve the odds of consistency but cannot guarantee that a video model will reproduce every detail exactly. Use reference images and final-frame continuation in Flow where available.

## Chrome extension for Google Flow

The optional Manifest V3 extension lives in [`chrome-extension/`](chrome-extension/). It splits a generated story prompt into the project context (Sections 1–7) and one clipboard-ready part per clip. Sections 9–10 stay attached to the final clip. Prompts without the expected clip format are kept together as a single part. **Google Flow does not need to be open while the extension parses or copies the sequence.**

For local testing:

1. Open `chrome://extensions`, enable **Developer mode**, and choose **Load unpacked**. Select this repository's `chrome-extension/` folder.
2. Open the VeoPrompter site in Chrome, open the extension popup, and choose **Connect this site**. Approve the site-specific permission. This enables the Send to Flow button on that origin; you only need to connect once per site origin.
3. Generate a prompt in a project and click **Send to Flow**. The prompt is stored in the extension's local Chrome storage, not uploaded to another service.
4. Open the extension popup on any tab and use its single changing **Copy** action to copy Sections 1–7, then each clip in order. Paste each part into Flow when convenient and send it yourself. The extension copies text to the clipboard; it does not connect to Flow, submit messages, or start video generation.
5. Use **Go back one part** to rewind the sequence and copy that part again, or **Start this prompt over** to return to the project context. Sending a new prompt from VeoPrompter replaces the active prompt stored in the extension.

The extension's page access is limited to the VeoPrompter site you connect. It also uses clipboard-write permission only when you click Copy; it does not request access to Flow's website.

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
9. Use **Control room → Project duration choices** to enable or disable the two duration questions for all accounts. Both global switches default on. From **User management → Manage account**, an administrator can also disable either choice for a specific account. A choice is available only when both its global and per-account switches are on; otherwise it is hidden and the effective duration is fixed at eight seconds.

Public account registration is enabled. Sign-in is required to open projects and generate prompts. Configure your deployment's privacy notice and account-retention policy for your audience. Administrators can review user activity and create three-hour temporary test accounts from **User management**.

## Configure an AI provider

### Google Gemini

- Provider: **Google Gemini**
- Model: `gemini-2.5-flash` (or another model supported by your key)
- API key: a key created for your Google AI Studio project

VeoPrompter calls Gemini's `generateContent` endpoint from the Laravel server.

### Gemini text-to-speech previews

- Configure a separate Google API key in **Control room → Voice sample generation**. It must have access to a Gemini TTS model.
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
```

The test suite covers the master prompt's synchronization with root `prompt.txt`, sign-in-gated project workflows, reusable owner-scoped settings, private generation history, three-hour test-account expiry, presence reporting, admin-only provider configuration, encrypted credentials, model-list discovery, test-connection requests, and provider request construction. Run the suite in an environment with PHP, Composer dependencies, and PDO SQLite installed.

## Project layout

- `prompt.txt` — the source master prompting method.
- `resources/prompts/master-spec.txt` — the runtime copy of that specification, covered by a synchronization test.
- `app/Services/PromptGenerationService.php` — server-side prompt instructions and Gemini/OpenAI-compatible adapters.
- `app/Http/Controllers/StudioController.php` — authenticated project-scoped input validation, generation, and history persistence.
- `app/Http/Controllers/StudioProjectController.php` and `app/Support/ProjectSettings.php` — saved project preferences and owner-scoped project workflows.
- `app/Http/Controllers/AuthController.php`, `PasswordResetController.php`, `AccountController.php`, and `PresenceController.php` — account lifecycle, recovery, and activity heartbeats.
- `app/Http/Controllers/PromptHistoryController.php` — private prompt library, search, and owner-checked detail/deletion.
- `app/Http/Controllers/Admin/AiSettingsController.php` and `UserManagementController.php` — administrator-only provider setup, registered-user activity, prompt review, and temporary test accounts.
- `resources/views/` and `public/css`, `public/js` — responsive studio, account, and history interface with copy/download helpers. GSAP 3.15.0 and ScrollTrigger are self-hosted under `public/js/vendor`; Inter 5.3.0 variable fonts are self-hosted under `public/fonts` with their OFL license. The motion layer respects reduced-motion preferences.
