<?php

namespace App\Support;

use App\Models\AiSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

final class ProjectSettings
{
    public const AI_CHOICE = 'auto';
    public const AI_QUALITY_CLIP_COUNT = 'quality';
    public const AI_DURATION_MAX = 10;
    public const SPEECH_MODE_VOICEOVER = 'voiceover';
    public const SPEECH_MODE_CHARACTER_DIALOGUE = 'character-dialogue';
    public const MANUAL_DURATION_MIN = 4;
    public const MANUAL_DURATION_MAX = 20;
    public const MANUAL_CLIP_COUNT_MIN = 2;
    public const MANUAL_CLIP_COUNT_MAX = 8;

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
            'audience' => 'Curious children and families',
            'video_type' => 'educational',
            'visual_style' => '3d-family-animation',
            'style_detail' => '',
            'character_type' => 'ai-choice',
            'character_details' => '',
            'clip_count' => self::AI_CHOICE,
            'clip_duration' => self::AI_CHOICE,
            'extend_duration' => self::AI_CHOICE,
            'aspect_ratio' => '9:16',
            'educational_depth' => 'clear',
            'narration' => '1',
            'speech_mode' => self::SPEECH_MODE_VOICEOVER,
            'language' => 'English',
            'narrator_voice' => 'feminine',
            'narrator_accent' => 'American English',
            'narrator_tone' => 'warm and friendly',
            'music' => true,
            'sound_effects' => true,
        ];
    }

    public static function speechModeLabel(mixed $speechMode, mixed $narration = '1'): string
    {
        if ((string) $narration !== '1') {
            return 'No spoken audio';
        }

        return (string) $speechMode === self::SPEECH_MODE_CHARACTER_DIALOGUE
            ? 'On-screen character dialogue'
            : 'Voice-over narration';
    }

    /** @return array{clip_duration: bool, extend_duration: bool} */
    public static function globalDurationOptions(): array
    {
        $settings = AiSetting::query()->find(1);

        return [
            'clip_duration' => (bool) ($settings?->allow_clip_duration_choice ?? false),
            'extend_duration' => (bool) ($settings?->allow_extension_duration_choice ?? false),
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

    public static function isAiChoice(mixed $value): bool
    {
        return (string) $value === self::AI_CHOICE;
    }

    public static function isAiClipCountChoice(mixed $value): bool
    {
        return in_array((string) $value, [self::AI_CHOICE, self::AI_QUALITY_CLIP_COUNT], true);
    }

    public static function formatClipCount(mixed $value): string
    {
        return match ((string) $value) {
            self::AI_CHOICE => 'AI decides · minimum-practical count',
            self::AI_QUALITY_CLIP_COUNT => 'AI decides · maximum-quality count',
            default => is_numeric($value) ? ((int) $value).' clips' : '—',
        };
    }

    public static function formatDuration(mixed $value, bool $long = false): string
    {
        if (self::isAiChoice($value)) {
            return $long
                ? 'AI decides · up to '.self::AI_DURATION_MAX.' seconds'
                : 'AI decides · up to '.self::AI_DURATION_MAX.' sec';
        }

        if (! is_numeric($value)) {
            return '—';
        }

        return (int) $value.($long ? ' seconds' : ' sec');
    }

    /** @param array{clip_duration?: bool, extend_duration?: bool} $durationOptions
     *  @return array<string, int|string>
     */
    public static function forcedDurationFields(array $durationOptions): array
    {
        $forced = [];
        if (! ($durationOptions['clip_duration'] ?? true)) {
            $forced['clip_duration'] = self::AI_CHOICE;
        }
        if (! ($durationOptions['extend_duration'] ?? true)) {
            $forced['extend_duration'] = self::AI_CHOICE;
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
        if (! in_array($settings['speech_mode'] ?? null, [self::SPEECH_MODE_VOICEOVER, self::SPEECH_MODE_CHARACTER_DIALOGUE], true)) {
            $settings['speech_mode'] = self::SPEECH_MODE_VOICEOVER;
        }
        $settings['clip_count'] = self::normalizeClipCount($settings['clip_count'] ?? self::AI_CHOICE);
        $settings['clip_duration'] = ($durationOptions['clip_duration'] ?? true)
            ? self::normalizeChoice($settings['clip_duration'] ?? self::AI_CHOICE, self::AI_CHOICE)
            : self::AI_CHOICE;
        $settings['extend_duration'] = ($durationOptions['extend_duration'] ?? true)
            ? self::normalizeChoice($settings['extend_duration'] ?? self::AI_CHOICE, self::AI_CHOICE)
            : self::AI_CHOICE;

        return $settings;
    }

    private static function normalizeClipCount(mixed $value): int|string
    {
        if (self::isAiClipCountChoice($value)) {
            return (string) $value;
        }

        return is_numeric($value) ? (int) $value : self::AI_CHOICE;
    }

    private static function normalizeChoice(mixed $value, int|string $fallback): int|string
    {
        if (self::isAiChoice($value)) {
            return self::AI_CHOICE;
        }

        return is_numeric($value) ? (int) $value : $fallback;
    }

    /**
     * @return array<string, array{label: string, description: string, direction: string}>
     */
    public static function visualStyleOptions(): array
    {
        return [
            '3d-family-animation' => [
                'label' => 'Polished 3D family animation',
                'description' => 'Rounded characters, dimensional scenes, and polished lighting.',
                'direction' => 'Use polished, family-friendly 3D animation with dimensional forms, appealing stylized proportions, expressive acting, refined materials, and soft cinematic lighting. Keep the requested subject and format intact; style should shape the rendering, not change the content.',
            ],
            'disney-pixar-inspired' => [
                'label' => 'Disney/Pixar-style 3D animation',
                'description' => 'Feature-style 3D characters, rich worlds, and warm, expressive storytelling.',
                'direction' => 'Use an original, polished 3D family-feature animation look associated with Disney/Pixar: appealing silhouettes, expressive character acting, rich layered environments, refined materials, cinematic lighting, and warm visual storytelling. Create original designs; do not reproduce recognizable characters, film scenes, logos, or exact proprietary designs. Preserve the requested subject, audience, tone, and format.',
            ],
            'japanese-anime' => [
                'label' => 'Japanese anime-style animation',
                'description' => 'Expressive Japanese animation, elegant linework, and cinematic backgrounds.',
                'direction' => 'Use Japanese anime-inspired visual language: clean expressive linework, intentional cel shading with nuanced gradients, emotionally readable faces, composed or dynamic framing as the moment requires, and richly painted cinematic backgrounds. Keep character designs and details consistent; create original designs rather than copying a known series or character. Preserve the requested subject, audience, tone, and format.',
            ],
            '2d-illustration' => [
                'label' => 'Expressive 2D illustration',
                'description' => 'Drawn shapes, graphic compositions, and illustration textures.',
                'direction' => 'Use an expressive 2D illustrated look with deliberate shape language, clear graphic composition, tactile illustration textures, and a cohesive color palette. Keep the requested subject and format intact; style should shape the rendering, not change the content.',
            ],
            'cinematic-realism' => [
                'label' => 'Cinematic live-action realism',
                'description' => 'Natural materials, believable light, and a film-like camera language.',
                'direction' => 'Use grounded live-action cinematic realism: natural materials, believable lighting and shadows, plausible camera optics and movement, and restrained color grading. Keep the scene physically credible and preserve the requested subject and format.',
            ],
            'stop-motion' => [
                'label' => 'Tactile stop-motion',
                'description' => 'Handmade textures and a crafted, frame-by-frame feel.',
                'direction' => 'Use a tactile handcrafted stop-motion look with miniature-scale sets, visible material character, subtle frame-by-frame movement, and consistent practical lighting. Preserve the requested subject and format.',
            ],
            'custom' => [
                'label' => 'Custom visual style',
                'description' => 'Describe a look, palette, texture, or medium of your choice.',
                'direction' => 'Apply the user-supplied custom style consistently across the sequence while preserving the requested subject and format.',
            ],
        ];
    }

    public static function visualStyleLabel(?string $style, ?string $detail = null): string
    {
        if ($style === 'custom') {
            return filled($detail) ? trim($detail) : self::visualStyleOptions()['custom']['label'];
        }

        return self::visualStyleOptions()[$style ?? '']['label'] ?? self::visualStyleOptions()['custom']['label'];
    }

    public static function visualStyleDirection(?string $style, ?string $detail = null): string
    {
        $direction = self::visualStyleOptions()[$style ?? '']['direction']
            ?? self::visualStyleOptions()['custom']['direction'];

        if ($style === 'custom' && filled($detail)) {
            return $direction.' User-supplied style description: '.trim($detail);
        }

        return $direction;
    }

    /** @param array{clip_duration?: bool, extend_duration?: bool} $durationOptions
     *  @return array<string, array<int, mixed>>
     */
    public static function rules(array $durationOptions = []): array
    {
        return [
            'audience' => ['required', 'string', 'max:160'],
            'video_type' => ['required', Rule::in(array_keys(self::videoTypes()))],
            'visual_style' => ['required', Rule::in(array_keys(self::visualStyleOptions()))],
            'style_detail' => ['nullable', 'required_if:visual_style,custom', 'string', 'max:500'],
            'character_type' => ['required', Rule::in(['ai-choice', 'human-male', 'human-female', 'robot', 'fictional'])],
            'character_details' => ['nullable', 'required_if:character_type,fictional', 'string', 'max:500'],
            'clip_count' => ['required', Rule::in([self::AI_CHOICE, self::AI_QUALITY_CLIP_COUNT, ...range(self::MANUAL_CLIP_COUNT_MIN, self::MANUAL_CLIP_COUNT_MAX)])],
            'clip_duration' => ($durationOptions['clip_duration'] ?? true)
                ? ['required', Rule::in([self::AI_CHOICE, ...range(self::MANUAL_DURATION_MIN, self::MANUAL_DURATION_MAX)])]
                : ['required', Rule::in([self::AI_CHOICE])],
            'extend_duration' => ($durationOptions['extend_duration'] ?? true)
                ? ['required', Rule::in([self::AI_CHOICE, ...range(self::MANUAL_DURATION_MIN, self::MANUAL_DURATION_MAX)])]
                : ['required', Rule::in([self::AI_CHOICE])],
            'aspect_ratio' => ['required', Rule::in(['16:9', '9:16', '1:1', '4:3'])],
            'educational_depth' => ['required', Rule::in(['clear', 'balanced', 'deep-dive'])],
            'narration' => ['required', Rule::in(['1', '0'])],
            'speech_mode' => ['nullable', Rule::in([self::SPEECH_MODE_VOICEOVER, self::SPEECH_MODE_CHARACTER_DIALOGUE])],
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
        $settings['clip_count'] = self::normalizeClipCount($settings['clip_count'] ?? self::AI_CHOICE);
        $settings['clip_duration'] = self::normalizeChoice($settings['clip_duration'] ?? self::AI_CHOICE, self::AI_CHOICE);
        $settings['extend_duration'] = self::normalizeChoice($settings['extend_duration'] ?? self::AI_CHOICE, self::AI_CHOICE);
        $settings['music'] = in_array($settings['music'], [true, 1, '1'], true);
        $settings['sound_effects'] = in_array($settings['sound_effects'], [true, 1, '1'], true);

        return self::applyDurationOptions($settings, $durationOptions);
    }
}
