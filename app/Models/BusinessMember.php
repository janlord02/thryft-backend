<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person's standing within a business.
 *
 * Membership is checked against the database on every request, so revoking a
 * member takes effect immediately without having to hunt down and delete their
 * Sanctum tokens.
 */
class BusinessMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'user_id',
        'role',
        'permissions',
        'status',
        'invited_at',
        'accepted_at',
    ];

    protected $casts = [
        'permissions' => 'array',
        'invited_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    /**
     * Every ability this member holds: their role's set, plus any per-member
     * grants. Owners get the wildcard.
     *
     * @return array<string>
     */
    public function abilities(): array
    {
        $fromRole = config("permissions.roles.{$this->role}", []);

        if (in_array('*', $fromRole, true)) {
            return config('permissions.abilities', []);
        }

        return array_values(array_unique(array_merge($fromRole, $this->permissions ?? [])));
    }

    public function hasAbility(string $ability): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        return in_array($ability, $this->abilities(), true);
    }
}
