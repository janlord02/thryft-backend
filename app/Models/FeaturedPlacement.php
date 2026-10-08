<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeaturedPlacement extends Model
{
    protected $fillable = [
        'business_id', 'target_type', 'target_id', 'business_tag_id', 'latitude', 'longitude',
        'radius_miles', 'days', 'amount_cents', 'status', 'stripe_payment_intent_id', 'starts_at', 'ends_at',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'radius_miles' => 'integer',
        'days' => 'integer',
        'amount_cents' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function scopeShowing(Builder $q): Builder
    {
        return $q->where('status', 'active')->where('starts_at', '<=', now())->where('ends_at', '>', now());
    }

    /** Payment went through: start now (or at the chosen start) and run for its days. Idempotent. */
    public function activate(): void
    {
        if ($this->status === 'active') {
            return;
        }
        $start = $this->starts_at && $this->starts_at->isFuture() ? $this->starts_at : now();
        $this->update(['status' => 'active', 'starts_at' => $start, 'ends_at' => $start->copy()->addDays($this->days)]);
    }

    /** Miles from a point to this placement's centre. */
    public function milesFrom(float $lat, float $lng): float
    {
        $rad = M_PI / 180;
        $a = sin(($this->latitude - $lat) * $rad / 2) ** 2
            + cos($lat * $rad) * cos($this->latitude * $rad) * sin(($this->longitude - $lng) * $rad / 2) ** 2;

        return 3959 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
