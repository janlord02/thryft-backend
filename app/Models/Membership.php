<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Membership extends Model
{
    protected $fillable = ['plan_id', 'business_id', 'user_id', 'code', 'starts_at', 'ends_at', 'status'];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'plan_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('status', 'active')->where('starts_at', '<=', now())->where('ends_at', '>', now());
    }

    public function isCurrent(): bool
    {
        return $this->status === 'active' && $this->starts_at->isPast() && $this->ends_at->isFuture();
    }

    public function statusNote(): string
    {
        return match (true) {
            $this->status === 'cancelled' => 'Cancelled',
            $this->ends_at->isPast() => 'Ended ' . $this->ends_at->toFormattedDateString(),
            default => 'Member until ' . $this->ends_at->toFormattedDateString(),
        };
    }

    /** Whether $userId holds a current membership at $businessId. */
    public static function holds(int $userId, int $businessId): bool
    {
        return static::query()->current()->where('user_id', $userId)->where('business_id', $businessId)->exists();
    }

    public function toWallet(): array
    {
        return [
            'type' => 'membership',
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->plan->name,
            'benefits' => $this->plan->benefits,
            'is_active' => $this->isCurrent(),
            'status_note' => $this->statusNote(),
            'ends_at' => $this->ends_at,
            'business' => $this->business->walletSummary(),
        ];
    }
}
