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
            'quality_review' => 'array',
            'repair_history' => 'array',
            'reference_frame_attached' => 'boolean',
        ];
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
