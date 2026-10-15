<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = Validator::make($request->all(), [
            'provider' => ['required', 'in:gemini,openai'],
            'model' => ['required', 'string', 'max:150', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._\/:\-]*\z/'],
            'base_url' => ['nullable', 'string', 'url', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:4096'],
            'clear_key' => ['nullable', 'boolean'],
        ])->validate();

        $settings = AiSetting::current();
        $clearKey = $request->boolean('clear_key');
        $newKey = trim((string) ($validated['api_key'] ?? ''));

        if ($validated['provider'] === 'openai') {
            $baseUrl = rtrim((string) ($validated['base_url'] ?? ''), '/');
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
        } else {
            $baseUrl = null;
        }

        if ($settings->provider !== $validated['provider'] && $newKey === '' && ! $clearKey) {
            throw ValidationException::withMessages([
                'api_key' => 'Enter a key for the newly selected provider. Keys are not reused across providers.',
            ]);
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
}
