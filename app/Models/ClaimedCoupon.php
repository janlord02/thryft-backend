<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ClaimedCoupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'coupon_id',
        'business_id',
        'product_id',
        'coupon_code',
        'coupon_title',
        'coupon_description',
        'discount_type',
        'discount_amount',
        'discount_percentage',
        'minimum_amount',
        'expires_at',
        'status',
        'used_at',
        'usage_notes',
    ];

    protected $casts = [
        'discount_amount' => 'decimal:2',
        'discount_percentage' => 'decimal:2',
        'minimum_amount' => 'decimal:2',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(User::class, 'business_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // Scopes
    public function scopeClaimed($query)
    {
        return $query->where('status', 'claimed');
    }

    public function scopeUsed($query)
    {
        return $query->where('status', 'used');
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'expired');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['claimed', 'used']);
    }

    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByBusiness($query, $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    // Accessors
    public function getDiscountDisplayAttribute(): string
    {
        if ($this->discount_type === 'percentage') {
            return $this->discount_percentage . '%';
        }
        return '$' . number_format((float) $this->discount_amount, 2);
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->expires_at && $this->expires_at < now();
    }

    public function getIsUsableAttribute(): bool
    {
        return $this->status === 'claimed' && !$this->is_expired;
    }

    // Methods
    public function markAsUsed($notes = null)
    {
        $this->update([
            'status' => 'used',
            'used_at' => now(),
            'usage_notes' => $notes,
        ]);
    }

    public function markAsExpired()
    {
        $this->update(['status' => 'expired']);
    }

    public function cancel()
    {
        $this->update(['status' => 'cancelled']);
    }
}
