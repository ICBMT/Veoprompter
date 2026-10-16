<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;

class AiSetting extends Model
{
    protected $fillable = [
        'provider',
        'model',
        'base_url',
        'api_key',
        'tts_model',
        'tts_api_key',
        'allow_clip_duration_choice',
        'allow_extension_duration_choice',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'tts_api_key' => 'encrypted',
            'allow_clip_duration_choice' => 'boolean',
            'allow_extension_duration_choice' => 'boolean',
        ];
    }

    public static function current(): static
    {
        return static::query()->firstOrCreate([
            'id' => 1,
        ], [
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
        ]);
    }

    public function apiKeyConfigured(): bool
    {
        return filled($this->api_key);
    }

    public function ttsApiKeyConfigured(): bool
    {
        try {
            return filled($this->tts_api_key);
        } catch (DecryptException) {
            return false;
        }
    }

    public function ttsReady(): bool
    {
        return $this->ttsApiKeyConfigured() && filled($this->tts_model);
    }

    public function isReady(): bool
    {
        return $this->apiKeyConfigured()
            && filled($this->model)
            && ($this->provider !== 'openai' || filled($this->base_url));
    }
}
