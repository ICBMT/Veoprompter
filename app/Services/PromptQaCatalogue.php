<?php

namespace App\Services;

/** Versioned catalogue shared by prevention documentation, final QA classification and regression coverage. */
final class PromptQaCatalogue
{
    public static function all(): array
    {
        static $entries;
        return $entries ??= json_decode(file_get_contents(__DIR__.'/../../resources/prompts/qa-catalogue.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function kind(string $code): string
    {
        return self::all()[$code]['kind'] ?? 'review';
    }
}
