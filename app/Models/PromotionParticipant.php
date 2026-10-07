<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionParticipant extends Model
{
    protected $fillable = ['promotion_id', 'business_id', 'coupon_id', 'status', 'role', 'unlocked_by_participant_id', 'sort'];

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function unlockedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'unlocked_by_participant_id');
    }
}
