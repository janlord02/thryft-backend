<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Coupon extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'code',
        'description',
        'qr_code',
        'discount_amount',
        'discount_percentage',
        'discount_type',
        'minimum_amount',
        'usage_limit',
        'used_count',
        'per_user_limit',
        'starts_at',
        'expires_at',
        'is_active',
        'is_featured',
        'terms_conditions',
    ];

    protected $casts = [
        'discount_amount' => 'decimal:2',
        'discount_percentage' => 'decimal:2',
        'minimum_amount' => 'decimal:2',
        'usage_limit' => 'integer',
        'used_count' => 'integer',
        'per_user_limit' => 'integer',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'terms_conditions' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($coupon) {
            if (empty($coupon->code)) {
                $coupon->code = strtoupper(Str::random(8));
            }
        });
    }

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'coupon_products');
    }

    public function claimedCoupons(): HasMany
    {
        return $this->hasMany(ClaimedCoupon::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeValid($query)
    {
        $now = now();
        return $query->where(function ($q) use ($now) {
            $q->whereNull('starts_at')
                ->orWhere('starts_at', '<=', $now);
        })->where(function ($q) use ($now) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>=', $now);
        });
    }

    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('title', 'like', '%' . $search . '%')
                ->orWhere('code', 'like', '%' . $search . '%')
                ->orWhere('description', 'like', '%' . $search . '%');
        });
    }

    // Accessors
    public function getFormattedDiscountAttribute()
    {
        if ($this->discount_type === 'percentage') {
            return $this->discount_percentage . '%';
        }
        return '$' . number_format((float) $this->discount_amount, 2);
    }

    public function getStatusAttribute()
    {
        if (!$this->is_active) {
            return 'inactive';
        }

        $now = now();
        if ($this->starts_at && $this->starts_at > $now) {
            return 'scheduled';
        }

        if ($this->expires_at && $this->expires_at < $now) {
            return 'expired';
        }

        if ($this->usage_limit && $this->used_count >= $this->usage_limit) {
            return 'limit_reached';
        }

        return 'active';
    }

    public function getIsValidAttribute()
    {
        return $this->status === 'active';
    }

    // Methods
    public function canBeUsed()
    {
        // Check if coupon is active
        if (!$this->is_active) {
            return false;
        }

        // Check if coupon has started
        $now = now();
        if ($this->starts_at && $this->starts_at > $now) {
            return false;
        }

        // Check if coupon has expired
        if ($this->expires_at && $this->expires_at < $now) {
            return false;
        }

        // Check if usage limit has been reached
        if ($this->usage_limit && $this->used_count >= $this->usage_limit) {
            return false;
        }

        return true;
    }

    public function incrementUsage()
    {
        $this->increment('used_count');
    }

    public function generateQRCode()
    {
        // This would integrate with a QR code generation library
        // For now, we'll just return a placeholder
        return 'qr_code_' . $this->id . '.png';
    }
}
