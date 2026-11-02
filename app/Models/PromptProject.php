<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromptProject extends Model
{
    use HasFactory;

    protected $fillable = [
        'studio_project_id',
        'parent_prompt_id',
        'story_part',
        'story_batch',
        'story_status',
        'story_context',
        'continuation_key',
        'title',
        'mode',
        'brief',
        'content',
        'original_content',
        'repair_history',
        'quality_review',
        'provider',
        'model',
        'reference_frame_attached',
    ];

    protected function casts(): array
    {
        return [
            'brief' => 'array',
            'story_context' => 'array',
            'story_part' => 'integer',
            'story_batch' => 'integer',
            'quality_review' => 'array',
            'repair_history' => 'array',
            'reference_frame_attached' => 'boolean',
        ];
    }

    public function parentPrompt(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_prompt_id');
    }

    public function continuations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(self::class, 'parent_prompt_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function studioProject(): BelongsTo
    {
        return $this->belongsTo(StudioProject::class);
    }
}
