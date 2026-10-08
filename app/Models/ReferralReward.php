<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A free month earned through referrals. Unapplied while applied_at is null.
 */
class ReferralReward extends Model
{
    protected $fillable = [
        'business_id',
        'reason',
        'method',
        'amount_cents',
        'stripe_balance_transaction_id',
        'extended_to',
        'applied_at',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'extended_to' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'reward_id');
    }
}
