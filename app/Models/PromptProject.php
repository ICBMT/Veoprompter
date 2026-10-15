<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromptProject extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'mode',
        'brief',
        'content',
        'provider',
        'model',
        'reference_frame_attached',
    ];

    protected function casts(): array
    {
        return [
            'brief' => 'array',
            'reference_frame_attached' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
