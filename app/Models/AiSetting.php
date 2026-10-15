<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiSetting extends Model
{
    protected $fillable = [
        'provider',
        'model',
        'base_url',
        'api_key',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
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

    public function isReady(): bool
    {
        return $this->apiKeyConfigured()
            && filled($this->model)
            && ($this->provider !== 'openai' || filled($this->base_url));
    }
}
