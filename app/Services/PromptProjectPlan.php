<?php

namespace App\Services;

/**
 * Canonical, parsed representation used by text QA and bounded Auto-Fix.
 *
 * Copy-ready clip blocks are the source of truth for duration, dialogue, state,
 * and handoff fields. Overview and clip-plan references remain dependent views;
 * callers must validate or synchronize them against this representation.
 */
final class PromptProjectPlan
{
    /**
     * @param array<int, array<string, mixed>> $clips
     * @param array<string, mixed> $durationLimits
     * @param array<string, mixed> $projectRuntimeLimits
     * @param array<int, array<string, mixed>> $overviewDurations
     * @param array<int, array<string, mixed>> $overviewRuntimes
     * @param array<int, array<string, mixed>> $clipPlanDurations
     * @param array<string, mixed> $savedSettings
     */
    public function __construct(
        public readonly array $clips,
        public readonly array $durationLimits,
        public readonly array $projectRuntimeLimits,
        public readonly array $overviewDurations,
        public readonly array $overviewRuntimes,
        public readonly array $clipPlanDurations,
        public readonly array $savedSettings = [],
    ) {}

    public function clipCount(): int
    {
        return count($this->clips);
    }

    /** @return array<int, int> Canonical clip order. */
    public function clipOrder(): array
    {
        return array_map(static fn (array $clip): int => (int) ($clip['number'] ?? 0), $this->clips);
    }

    /** @return array<int, float> Clip number => canonical duration in seconds. */
    public function clipDurations(): array
    {
        $durations = [];
        foreach ($this->clips as $clip) {
            $number = (int) ($clip['number'] ?? 0);
            $duration = $clip['duration_seconds'] ?? null;
            if ($number > 0 && is_numeric($duration)) {
                $durations[$number] = (float) $duration;
            }
        }

        return $durations;
    }

    public function totalRuntimeSeconds(): ?float
    {
        if ($this->clips === [] || count($this->clipDurations()) !== count($this->clips)) {
            return null;
        }

        return array_sum($this->clipDurations());
    }

    public function hasCompleteSequentialClipDurations(): bool
    {
        if ($this->clips === [] || $this->totalRuntimeSeconds() === null) {
            return false;
        }

        foreach ($this->clips as $index => $clip) {
            if ((int) ($clip['number'] ?? 0) !== $index + 1) {
                return false;
            }
        }

        return true;
    }
}
