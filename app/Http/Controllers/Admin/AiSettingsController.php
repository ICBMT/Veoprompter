<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiSetting;
use App\Services\AiProviderSetupException;
use App\Services\AiProviderSetupService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AiSettingsController extends Controller
{
    public function edit(): View
    {
        $settings = AiSetting::current();

        return view('admin.settings', [
            'settings' => $settings,
            'aiReady' => $settings->isReady(),
            'ttsReady' => $settings->ttsReady(),
        ]);
    }

    public function updateDurationOptions(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'allow_clip_duration_choice' => ['required', 'boolean'],
            'allow_extension_duration_choice' => ['required', 'boolean'],
        ]);

        $settings = AiSetting::current();
        $settings->allow_clip_duration_choice = (bool) $validated['allow_clip_duration_choice'];
        $settings->allow_extension_duration_choice = (bool) $validated['allow_extension_duration_choice'];
        $settings->save();

        return redirect()->to(route('admin.settings', [], false))
            ->with('success', 'Duration controls saved. When a choice is disabled, AI matches each beat to its complexity, up to 10 seconds without padding.');
    }

    public function models(Request $request, AiProviderSetupService $providerSetup): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'provider' => ['required', 'in:gemini,openai'],
            'base_url' => ['nullable', 'string', 'url', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:4096'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Check the provider settings and try again.', 'errors' => $validator->errors()], 422);
        }
        $validated = $validator->validated();

        try {
            $baseUrl = $this->normalizeBaseUrl($validated['provider'], $validated['base_url'] ?? null);
        } catch (ValidationException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => $exception->errors()], 422);
        }

        try {
            $apiKey = $this->resolveApiKey($validated, $baseUrl);
            $models = $providerSetup->models($validated['provider'], $baseUrl, $apiKey);
        } catch (AiProviderSetupException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->statusCode);
        }

        return response()->json(['models' => $models]);
    }

    public function testConnection(Request $request, AiProviderSetupService $providerSetup): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'provider' => ['required', 'in:gemini,openai'],
            'model' => ['required', 'string', 'max:150', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._\/:\-]*\z/'],
            'base_url' => ['nullable', 'string', 'url', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:4096'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Check the provider settings and try again.', 'errors' => $validator->errors()], 422);
        }
        $validated = $validator->validated();

        try {
            $baseUrl = $this->normalizeBaseUrl($validated['provider'], $validated['base_url'] ?? null);
        } catch (ValidationException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => $exception->errors()], 422);
        }

        try {
            $apiKey = $this->resolveApiKey($validated, $baseUrl);
            $reply = $providerSetup->testConnection($validated['provider'], $baseUrl, $validated['model'], $apiKey);
        } catch (AiProviderSetupException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->statusCode);
        }

        return response()->json([
            'message' => 'Connection succeeded. The selected model returned a text response.',
            'reply' => Str::limit($reply, 240),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        try {
            $validated = Validator::make($request->all(), [
                'provider' => ['required', 'in:gemini,openai'],
                'model' => ['required', 'string', 'max:150', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._\/:\-]*\z/'],
                'base_url' => ['nullable', 'string', 'url', 'max:255'],
                'api_key' => ['nullable', 'string', 'max:4096'],
                'clear_key' => ['nullable', 'boolean'],
            ])->validate();

            $settings = AiSetting::current();
            $baseUrl = $this->normalizeBaseUrl($validated['provider'], $validated['base_url'] ?? null);
            $clearKey = $request->boolean('clear_key');
            $newKey = trim((string) ($validated['api_key'] ?? ''));
            $savedKey = $newKey === '' && ! $clearKey
                ? $this->savedApiKey($settings, $validated['provider'], $baseUrl)
                : null;

            if ($newKey === '' && ! $clearKey && $savedKey === null) {
                throw ValidationException::withMessages([
                    'api_key' => 'Enter an API key for this provider. A saved key can only be reused with its original provider and endpoint.',
                ]);
            }
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput($request->except('api_key'));
        } catch (AiProviderSetupException $exception) {
            return back()->withErrors(['api_key' => $exception->getMessage()])->withInput($request->except('api_key'));
        }

        $settings->provider = $validated['provider'];
        $settings->model = trim($validated['model']);
        $settings->base_url = $baseUrl;

        if ($newKey !== '') {
            $settings->api_key = $newKey;
        } elseif ($clearKey) {
            $settings->api_key = null;
        }

        $settings->save();

        return redirect()->to(route('admin.settings', [], false))->with('success', 'AI connection settings saved. Your secret key is encrypted at rest and will not be shown again.');
    }

    public function updateVoice(Request $request): RedirectResponse
    {
        try {
            $validated = Validator::make($request->all(), [
                'tts_model' => ['required', 'string', 'max:150', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._\/:\-]*\z/'],
                'tts_api_key' => ['nullable', 'string', 'max:4096'],
                'clear_tts_key' => ['nullable', 'boolean'],
            ])->validate();
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput($request->except('tts_api_key'));
        }

        $settings = AiSetting::current();
        $settings->tts_model = trim($validated['tts_model']);
        $newKey = trim((string) ($validated['tts_api_key'] ?? ''));

        if ($newKey !== '') {
            $settings->tts_api_key = $newKey;
        } elseif ($request->boolean('clear_tts_key')) {
            $settings->tts_api_key = null;
        }

        $settings->save();

        return redirect()->to(route('admin.settings', [], false))
            ->with('success', 'Google TTS settings saved. The speech key is encrypted at rest and is never sent to the browser.');
    }

    private function resolveApiKey(array $validated, ?string $baseUrl): string
    {
        $providedKey = trim((string) ($validated['api_key'] ?? ''));
        if ($providedKey !== '') {
            return $providedKey;
        }

        $settings = AiSetting::current();
        $savedKey = $this->savedApiKey($settings, $validated['provider'], $baseUrl);

        if ($savedKey !== null) {
            return $savedKey;
        }

        throw new AiProviderSetupException('Enter a key for the selected provider, then load models or test the connection. Saved keys are only reused for their original provider and endpoint.', 422);
    }

    private function savedApiKey(AiSetting $settings, string $provider, ?string $baseUrl): ?string
    {
        if ($settings->provider !== $provider) {
            return null;
        }

        if ($provider === 'openai' && rtrim((string) $settings->base_url, '/') !== rtrim((string) $baseUrl, '/')) {
            return null;
        }

        try {
            $savedKey = trim((string) $settings->api_key);
        } catch (DecryptException $exception) {
            throw new AiProviderSetupException('The saved API key cannot be decrypted. Re-enter it and save the connection again.', 422, $exception);
        }

        return $savedKey !== '' ? $savedKey : null;
    }

    private function normalizeBaseUrl(string $provider, ?string $url): ?string
    {
        if ($provider !== 'openai') {
            return null;
        }

        $baseUrl = rtrim(trim((string) $url), '/');
        $parts = parse_url($baseUrl);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $host = rtrim(trim($host, '[]'), '.');
        $isPrivateIp = filter_var($host, FILTER_VALIDATE_IP)
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        $reservedHost = $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.example')
            || str_ends_with($host, '.invalid');

        if (! str_starts_with($baseUrl, 'https://')
            || ! is_array($parts)
            || $host === ''
            || $reservedHost
            || $isPrivateIp
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw ValidationException::withMessages([
                'base_url' => 'Use a public HTTPS API base URL without embedded credentials or a local/private IP address.',
            ]);
        }

        return $baseUrl;
    }
}
