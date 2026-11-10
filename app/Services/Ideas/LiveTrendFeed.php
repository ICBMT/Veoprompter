<?php

namespace App\Services\Ideas;

use App\Services\PromptGenerationException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class LiveTrendFeed
{
    public const REGIONS = ['PK' => ['Pakistan', 'Asia/Karachi'], 'US' => ['United States', 'America/New_York'],
        'GB' => ['United Kingdom', 'Europe/London'], 'IN' => ['India', 'Asia/Kolkata']];

    public function fetch(string $region): array
    {
        if (! isset(self::REGIONS[$region])) {
            throw new PromptGenerationException('Choose a supported trend region.');
        }
        $today = CarbonImmutable::now(self::REGIONS[$region][1])->toDateString();
        return Cache::remember('video-ideas:feed:'.$region.':'.$today, 900, function () use ($region, $today) {
            try {
                $response = Http::accept('application/rss+xml')->withUserAgent('VeoPrompter/1.0 (video idea discovery)')
                    ->connectTimeout(5)->timeout(15)->withOptions(['allow_redirects' => false])
                    ->get('https://trends.google.com/trending/rss', ['geo' => $region]);
                if (! $response->successful() || strlen($response->body()) > 1000000) {
                    throw new \RuntimeException('Feed unavailable');
                }
                $evidence = $this->parse($response->body(), $region);
                if ($evidence === []) {
                    throw new \RuntimeException('No current-day evidence');
                }
                return ['region' => $region, 'date' => $today, 'timezone' => self::REGIONS[$region][1],
                    'fetched_at' => now()->toIso8601String(), 'source' => 'Google Trends search-trend RSS', 'evidence' => $evidence];
            } catch (Throwable) {
                throw new PromptGenerationException('Live trends for today are unavailable in this region. Try another region or try again later. No AI guesses were labelled as verified trends.');
            }
        });
    }

    public function parse(string $xml, string $region): array
    {
        if (! isset(self::REGIONS[$region])) return [];
        return (new TrendRssParser)->parse($xml, $region, self::REGIONS[$region][1], CarbonImmutable::now());
    }
}
