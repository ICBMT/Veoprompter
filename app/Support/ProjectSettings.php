<?php

namespace App\Support;

use App\Models\AiSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

final class ProjectSettings
{
    /** @return array<string, string> */
    public static function videoTypes(): array
    {
        return [
            'educational' => 'Educational explainer',
            'cinematic-story' => 'Cinematic story / short film',
            'documentary' => 'Documentary / true story',
            'brand-promo' => 'Product or brand film',
            'music-video' => 'Music video',
            'travel' => 'Travel / mood reel',
            'custom' => 'Other / custom video',
        ];
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'audience' => 'Curious kids and families',
            'video_type' => 'educational',
            'visual_style' => '3d-family-animation',
            'style_detail' => '',
            'character_type' => 'ai-choice',
            'character_details' => '',
            'clip_count' => 4,
            'clip_duration' => 8,
            'extend_duration' => 8,
            'aspect_ratio' => '16:9',
            'educational_depth' => 'clear',
            'narration' => '1',
            'language' => 'English',
            'narrator_voice' => 'feminine',
            'narrator_accent' => 'American English',
            'narrator_tone' => 'warm and friendly',
            'music' => false,
            'sound_effects' => true,
        ];
    }

    /** @return array{clip_duration: bool, extend_duration: bool} */
    public static function globalDurationOptions(): array
    {
        $settings = AiSetting::query()->find(1);

        return [
            'clip_duration' => (bool) ($settings?->allow_clip_duration_choice ?? true),
            'extend_duration' => (bool) ($settings?->allow_extension_duration_choice ?? true),
        ];
    }

    /** @return array{clip_duration: bool, extend_duration: bool} */
    public static function durationOptionsFor(User $user): array
    {
        $globalOptions = self::globalDurationOptions();

        return [
            'clip_duration' => $globalOptions['clip_duration'] && (bool) ($user->can_choose_clip_duration ?? true),
            'extend_duration' => $globalOptions['extend_duration'] && (bool) ($user->can_choose_extension_duration ?? true),
        ];
    }

    /** @param array{clip_duration?: bool, extend_duration?: bool} $durationOptions
     *  @return array<string, int>
     */
    public static function forcedDurationFields(array $durationOptions): array
    {
        $forced = [];
        if (! ($durationOptions['clip_duration'] ?? true)) {
            $forced['clip_duration'] = 8;
        }
        if (! ($durationOptions['extend_duration'] ?? true)) {
            $forced['extend_duration'] = 8;
        }

        return $forced;
    }

    /** @param array<string, mixed> $settings
     *  @param array{clip_duration?: bool, extend_duration?: bool} $durationOptions
     *  @return array<string, mixed>
     */
    public static function applyDurationOptions(array $settings, array $durationOptions = []): array
    {
        $settings = array_replace(self::defaults(), $settings);
        $settings['clip_duration'] = ($durationOptions['clip_duration'] ?? true)
            ? (int) ($settings['clip_duration'] ?? 8)
            : 8;
        $settings['extend_duration'] = ($durationOptions['extend_duration'] ?? true)
            ? (int) ($settings['extend_duration'] ?? 8)
            : 8;

        return $settings;
    }

    /** @param array{clip_duration?: bool, extend_duration?: bool} $durationOptions
     *  @return array<string, array<int, mixed>>
     */
    public static function rules(array $durationOptions = []): array
    {
        return [
            'audience' => ['required', 'string', 'max:160'],
            'video_type' => ['required', Rule::in(array_keys(self::videoTypes()))],
            'visual_style' => ['required', Rule::in(['3d-family-animation', '2d-illustration', 'cinematic-realism', 'stop-motion', 'custom'])],
            'style_detail' => ['nullable', 'required_if:visual_style,custom', 'string', 'max:500'],
            'character_type' => ['required', Rule::in(['ai-choice', 'human-male', 'human-female', 'robot', 'fictional'])],
            'character_details' => ['nullable', 'required_if:character_type,fictional', 'string', 'max:500'],
            'clip_count' => ['required', 'integer', 'min:2', 'max:8'],
            'clip_duration' => ($durationOptions['clip_duration'] ?? true)
                ? ['required', 'integer', 'min:4', 'max:20']
                : ['required', 'integer', Rule::in([8])],
            'extend_duration' => ($durationOptions['extend_duration'] ?? true)
                ? ['required', 'integer', 'min:4', 'max:20']
                : ['required', 'integer', Rule::in([8])],
            'aspect_ratio' => ['required', Rule::in(['16:9', '9:16', '1:1', '4:3'])],
            'educational_depth' => ['required', Rule::in(['clear', 'balanced', 'deep-dive'])],
            'narration' => ['required', Rule::in(['1', '0'])],
            'language' => ['nullable', 'required_if:narration,1', Rule::in(['English', 'Urdu', 'Spanish', 'French', 'Arabic', 'Hindi'])],
            'narrator_voice' => ['nullable', 'required_if:narration,1', Rule::in(['feminine', 'masculine'])],
            'narrator_accent' => ['nullable', 'required_if:narration,1', Rule::in(['American English', 'British English', 'Pakistani English', 'Australian English', 'Neutral'])],
            'narrator_tone' => ['nullable', 'required_if:narration,1', Rule::in(['warm and friendly', 'curious and lively', 'calm and thoughtful', 'confident and clear'])],
            'music' => ['nullable', 'boolean'],
            'sound_effects' => ['nullable', 'boolean'],
        ];
    }

    /** @param array<string, mixed> $validated @param array<string, mixed> $existing
     *  @return array<string, mixed>
     */
    public static function fromValidated(array $validated, array $existing = [], array $durationOptions = []): array
    {
        $settings = array_replace(self::defaults(), $existing, Arr::only($validated, array_keys(self::defaults())));
        $settings['clip_count'] = (int) $settings['clip_count'];
        $settings['clip_duration'] = (int) $settings['clip_duration'];
        $settings['extend_duration'] = (int) $settings['extend_duration'];
        $settings['music'] = in_array($settings['music'], [true, 1, '1'], true);
        $settings['sound_effects'] = in_array($settings['sound_effects'], [true, 1, '1'], true);

        return self::applyDurationOptions($settings, $durationOptions);
    }
}
