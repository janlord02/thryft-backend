<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Coupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        // Dual-written alongside user_id during the business extraction; see
        // App\Support\BusinessResolver.
        'business_id',
        'location_id',
        'title',
        'code',
        'description',
        'redeem_instructions',
        'banner_image',
        'qr_code',
        'discount_amount',
        'discount_percentage',
        'discount_type',
        'minimum_amount',
        'usage_limit',
        'claim_limit',
        'used_count',
        'claimed_count',
        'redeemed_count',
        'per_user_limit',
        'starts_at',
        'expires_at',
        'is_active',
        'is_featured',
        'followers_notified_at',
        'terms_conditions',
    ];

    protected $casts = [
        'discount_amount' => 'decimal:2',
        'discount_percentage' => 'decimal:2',
        'minimum_amount' => 'decimal:2',
        'usage_limit' => 'integer',
        'claim_limit' => 'integer',
        'used_count' => 'integer',
        'claimed_count' => 'integer',
        'redeemed_count' => 'integer',
        'per_user_limit' => 'integer',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'followers_notified_at' => 'datetime',
        'terms_conditions' => 'array',
    ];

    protected $appends = [
        'status',
        'formatted_discount',
        'is_valid',
        'banner_image_url',
        'redeem_instructions_text',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($coupon) {
            if (empty($coupon->code)) {
                $coupon->code = strtoupper(Str::random(8));
            }
        });

        // Slugs are the public URL segment. Generated AFTER insert because the
        // id is part of the slug — it guarantees uniqueness without probing,
        // and stops titles producing an enumerable URL space. Without this,
        // every coupon created after the slug migration had slug = NULL and
        // route('public.deal') threw UrlGenerationException, 500-ing the
        // business page that listed it.
        static::created(function ($coupon) {
            if (empty($coupon->slug)) {
                $base = Str::limit(Str::slug((string) $coupon->title), 60, '');
                $coupon->slug = ($base !== '' ? $base : 'deal') . '-' . $coupon->id;
                $coupon->saveQuietly();
            }
        });
    }

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
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

        // Exhausted either way: nobody else can take the offer, or nobody can
        // redeem what they already took.
        if ($this->claim_limit && $this->claimed_count >= $this->claim_limit) {
            return 'limit_reached';
        }

        if ($this->usage_limit && $this->redeemed_count >= $this->usage_limit) {
            return 'limit_reached';
        }

        return 'active';
    }

    public function getIsValidAttribute()
    {
        return $this->status === 'active';
    }

    public function getBannerImageUrlAttribute()
    {
        if ($this->banner_image) {
            return asset('storage/' . $this->banner_image);
        }
        return null;
    }

    public function getRedeemInstructionsTextAttribute()
    {
        $default = 'Show this QR code to the business to redeem your coupon.';
        if ($this->redeem_instructions && trim($this->redeem_instructions) !== '') {
            return trim($this->redeem_instructions);
        }
        return $default;
    }

    // Methods

    /**
     * Is this offer currently within its active window?
     *
     * Shared precondition for both claiming and redeeming; neither counter is
     * consulted here.
     */
    public function isWithinActiveWindow(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $now = now();

        if ($this->starts_at && $this->starts_at > $now) {
            return false;
        }

        if ($this->expires_at && $this->expires_at < $now) {
            return false;
        }

        return true;
    }

    /**
     * Can a customer still take this offer?
     *
     * Advisory only — it is a read, so it can go stale the instant it returns.
     * The authoritative check is tryIncrementClaimCount().
     */
    public function canBeClaimed(): bool
    {
        if (!$this->isWithinActiveWindow()) {
            return false;
        }

        return !($this->claim_limit && $this->claimed_count >= $this->claim_limit);
    }

    /**
     * Can a claim against this offer still be redeemed?
     *
     * Advisory only; see tryIncrementRedeemCount().
     */
    public function canBeRedeemed(): bool
    {
        if (!$this->isWithinActiveWindow()) {
            return false;
        }

        return !($this->usage_limit && $this->redeemed_count >= $this->usage_limit);
    }

    /**
     * Atomically take one claim slot. Returns false if the cap was already
     * reached or the coupon was deactivated.
     *
     * A single conditional UPDATE is the enforcement mechanism, deliberately
     * not a SELECT ... FOR UPDATE: locking is a no-op on SQLite, so a
     * lock-based version would pass the test suite while being unprotected in
     * production. This behaves identically on MySQL and SQLite.
     *
     * CAUTION: Laravel does not enable PDO::MYSQL_ATTR_FOUND_ROWS, so update()
     * reports CHANGED rows rather than MATCHED rows. Relying on the return
     * value is only sound because this statement always changes a value when
     * its WHERE matches. Never copy this pattern onto an update that might
     * write identical values.
     */
    public function tryIncrementClaimCount(): bool
    {
        $affected = static::query()
            ->whereKey($this->getKey())
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('claim_limit')
                    ->orWhereColumn('claimed_count', '<', 'claim_limit');
            })
            ->update([
                'claimed_count' => DB::raw('claimed_count + 1'),
                // used_count is dual-written for one release so the counter
                // split stays reversible. Dropped in Phase 2.
                'used_count' => DB::raw('used_count + 1'),
                'updated_at' => now(),
            ]);

        return $affected === 1;
    }

    /**
     * Atomically take one redemption slot. Returns false if the redemption cap
     * was already reached. See tryIncrementClaimCount() for why this is a
     * conditional UPDATE rather than a lock.
     */
    public function tryIncrementRedeemCount(): bool
    {
        $affected = static::query()
            ->whereKey($this->getKey())
            ->where(function ($query) {
                $query->whereNull('usage_limit')
                    ->orWhereColumn('redeemed_count', '<', 'usage_limit');
            })
            ->update([
                'redeemed_count' => DB::raw('redeemed_count + 1'),
                'updated_at' => now(),
            ]);

        return $affected === 1;
    }

    public function generateQRCode()
    {
        // This would integrate with a QR code generation library
        // For now, we'll just return a placeholder
        return 'qr_code_' . $this->id . '.png';
    }
}
