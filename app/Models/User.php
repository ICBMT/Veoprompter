<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'expires_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function promptProjects(): HasMany
    {
        return $this->hasMany(PromptProject::class);
    }

    public function studioProjects(): HasMany
    {
        return $this->hasMany(StudioProject::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lessThanOrEqualTo(now());
    }

    public function isOnline(): bool
    {
        return ! $this->isExpired()
            && $this->last_seen_at !== null
            && $this->last_seen_at->greaterThanOrEqualTo(now()->subMinutes(3));
    }
}
