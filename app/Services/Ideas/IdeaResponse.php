<?php

namespace App\Services\Ideas;

use App\Services\PromptGenerationException;

/** Whitelist AI planning fields and bind source links exclusively to server-fetched evidence. */
final class IdeaResponse
{
    public function decode(string $text, array $evidence, string $type, array $allowedTypes): array
    {
        $text = trim($text);
        if (preg_match('/\A```(?:json)?\s*(.*?)\s*```\z/is', $text, $match)) $text = $match[1];
        try { $data = json_decode($text, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { $this->invalid(); }
        if (! is_array($data) || array_keys($data) !== ['ideas'] || ! is_array($data['ideas'])
            || ! array_is_list($data['ideas']) || count($data['ideas']) !== 10 || ! in_array($type, $allowedTypes, true)) $this->invalid();
        $sources = [];
        foreach ($evidence as $source) $sources[$source['id']] = $source;
        $ideas = []; $titles = [];
        foreach ($data['ideas'] as $idea) {
            if (! is_array($idea) || count($idea) !== 5 || array_diff(array_keys($idea), ['title', 'hook', 'angle', 'source_id', 'answers']) !== []
                || ! is_int($idea['source_id'] ?? null) || ! isset($sources[$idea['source_id']]) || ! is_array($idea['answers'] ?? null)
                || count($idea['answers']) !== 4 || array_diff(array_keys($idea['answers']), ['topic', 'learning_objective', 'creative_direction', 'character_idea']) !== []) $this->invalid();
            foreach (['title' => 100, 'hook' => 300, 'angle' => 500] as $field => $limit) $this->string($idea[$field] ?? null, $limit, true);
            $title = mb_strtolower(trim($idea['title']));
            if (isset($titles[$title])) $this->invalid();
            $titles[$title] = true;
            foreach (['topic' => 250, 'learning_objective' => 500, 'creative_direction' => 1500, 'character_idea' => 500] as $field => $limit) {
                $this->string($idea['answers'][$field] ?? null, $limit, in_array($field, ['topic', 'creative_direction'], true) || ($field === 'learning_objective' && $type === 'educational'));
            }
            $idea['source'] = $sources[$idea['source_id']];
            $idea['video_type'] = $type;
            if ($type !== 'educational') $idea['answers']['learning_objective'] = '';
            $ideas[] = $idea;
        }
        return $ideas;
    }

    private function string(mixed $value, int $max, bool $required): void
    {
        if ($value === null && ! $required) return;
        if (! is_string($value) || ($required && trim($value) === '') || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)) $this->invalid();
    }

    private function invalid(): never
    {
        throw new PromptGenerationException('The AI idea list was incomplete, unreadable or had invalid sources. Please retry; nothing was applied to a project.');
    }
}
