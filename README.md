# VeoPrompter

VeoPrompter is a Laravel 13 prompt studio for creating detailed, continuity-first educational video prompts for Google Flow and compatible video tools. It does **not** generate images or video. It sends the user's brief to an administrator-configured AI provider and returns a structured, copy-ready text plan.

## What it does

- Creates new educational story plans with a character bible, character-reference image prompt, world and object bible, clip plan, and independently copyable clip prompts.
- Continues a video from a written final-frame description and, optionally, a supplied still image.
- Carries exact character, set, camera, audio, and lighting details from one clip to the next; every continuation prompt includes the required verbatim handoff instruction from the master specification.
- Offers controls for clip count and duration, audience, educational depth, visual style, aspect ratio, narration, accent, and sound.
- Keeps provider credentials in the server-side database, encrypted using Laravel's `APP_KEY`. Admin settings are never exposed to the browser.
- Allows anyone to use the prompt studio; only administrator accounts can access the provider Control room.

Generated prompts improve the odds of consistency, but cannot guarantee a video model will reproduce every detail exactly. Use reference images and final-frame continuation in Flow when available.

## Requirements

- PHP 8.3 or newer with Laravel's required extensions (including PDO SQLite for the default local database)
- Composer 2
- A Google Gemini API key, or an OpenAI-compatible API key and public HTTPS endpoint
- Node.js and npm are optional for the app itself; the application UI ships as local CSS and JavaScript

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

Open `http://127.0.0.1:8000`. Set `APP_URL` to the public HTTPS address when deploying. Keep `APP_DEBUG=false` in production.

## Create the first administrator

There is intentionally no public administrator sign-up page. Create an administrator from the server terminal:

```bash
php artisan veoprompter:admin:create "Site Owner" admin@example.com
```

The command prompts for a password without echoing it; use at least 12 characters. If the email already belongs to an account, the command enables administrator access without changing that account's password. Sign in at `/login`, then open **Control room**.

## Configure an AI provider

In **Control room → AI provider connection**:

### Google Gemini

- Provider: **Google Gemini**
- Model: `gemini-2.5-flash` (or another model available to your key)
- Paste the API key from your Google AI Studio project

VeoPrompter calls Gemini's `generateContent` endpoint from the Laravel server. The optional extension-frame image is sent as an inline image in the same request.

### OpenAI-compatible API

- Provider: **OpenAI-compatible API**
- Base URL: for example `https://api.openai.com/v1` (the app appends `/chat/completions`)
- Model: for example `gpt-4.1-mini`
- Paste the provider API key

The endpoint must use public HTTPS. The selected provider/model must support image input if you want to attach a final frame in extension mode.

The API key field is write-only in the UI: after saving, VeoPrompter shows only whether a key exists. Stored credentials use Laravel's encrypted cast. **Back up `APP_KEY` securely and do not change it after saving provider keys**; ciphertext cannot be decrypted with a different key. Never commit `.env` or share a key in chat or a prompt.

## Security and data handling

- `/admin` and provider updates require an authenticated administrator; non-admin requests are rejected.
- The API key is sent only from the server to the configured provider over HTTPS. It is not placed in page markup, browser JavaScript, generated output, or application logs.
- Prompt requests are rate-limited to 8 per minute per client IP. Uploaded extension frames are limited to 8 MB and are forwarded to the provider for that request; VeoPrompter does not save them.
- User prompts and AI responses are not persisted by this initial version.
- Use HTTPS in production, restrict server/database access, set `APP_DEBUG=false`, and protect backups containing the database and `APP_KEY`.

## Tests

```bash
php artisan test
```

The test suite covers public studio access, admin-only configuration, encrypted credential storage, provider request construction, and response handling.

## Project layout

- `resources/prompts/master-spec.txt` — extracted, full master prompting method from the original `prompt.txt` reference.
- `app/Services/PromptGenerationService.php` — server-side system instructions and Gemini/OpenAI-compatible adapters.
- `app/Http/Controllers/Admin/AiSettingsController.php` — admin-only provider configuration.
- `resources/views/studio/` and `public/css`, `public/js` — prompt studio interface and client-side copy/download helpers.
