<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $discount_type
 * @property float|null $discount_value
 * @property float|null $max_discount_amount
 * @property Carbon $starts_at
 * @property Carbon $expires_at
 * @property int|null $max_uses
 * @property int $max_uses_per_user
 * @property int $total_uses
 * @property array|null $applicable_subscriptions
 * @property float|null $minimum_amount
 * @property bool $is_active
 * @property bool $is_public
 * @property array|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $discount_display
 * @property-read string $status_label
 * @property-read bool $is_valid
 * @property-read bool $is_expired
 * @property-read bool $is_maxed_out
 * @property-read int $remaining_uses
 */
class PromoCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'discount_type',
        'discount_value',
        'max_discount_amount',
        'starts_at',
        'expires_at',
        'max_uses',
        'max_uses_per_user',
        'total_uses',
        'applicable_subscriptions',
        'minimum_amount',
        'is_active',
        'is_public',
        'metadata',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
        'is_public' => 'boolean',
        'applicable_subscriptions' => 'array',
        'metadata' => 'array',
        'discount_value' => 'decimal:2',
        'max_discount_amount' => 'decimal:2',
        'minimum_amount' => 'decimal:2',
    ];

    protected $appends = [
        'discount_display',
        'status_label',
        'is_valid',
        'is_expired',
        'is_maxed_out',
        'remaining_uses',
    ];

    // Relationships
    public function userPromoCodes(): HasMany
    {
        return $this->hasMany(UserPromoCode::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_promo_codes')
            ->withPivot(['status', 'assigned_at', 'used_at', 'discount_applied', 'uses_count'])
            ->withTimestamps();
    }

    public function subscriptions(): BelongsToMany
    {
        return $this->belongsToMany(Subscription::class, 'user_promo_codes');
    }

    // Computed Properties
    public function getDiscountDisplayAttribute(): string
    {
        return match ($this->discount_type) {
            'percentage' => $this->discount_value . '%',
            'fixed_amount' => '$' . number_format($this->discount_value, 2),
            'free_access' => 'Free Access',
            default => 'N/A',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        if (!$this->is_active) {
            return 'Inactive';
        }

        if ($this->is_expired) {
            return 'Expired';
        }

        if ($this->is_maxed_out) {
            return 'Max Uses Reached';
        }

        if (Carbon::now()->isBefore($this->starts_at)) {
            return 'Scheduled';
        }

        return 'Active';
    }

    public function getIsValidAttribute(): bool
    {
        return $this->is_active
            && !$this->is_expired
            && !$this->is_maxed_out
            && Carbon::now()->isBetween($this->starts_at, $this->expires_at);
    }

    public function getIsExpiredAttribute(): bool
    {
        return Carbon::now()->isAfter($this->expires_at);
    }

    public function getIsMaxedOutAttribute(): bool
    {
        return $this->max_uses !== null && $this->total_uses >= $this->max_uses;
    }

    public function getRemainingUsesAttribute(): ?int
    {
        if ($this->max_uses === null) {
            return null; // Unlimited
        }

        return max(0, $this->max_uses - $this->total_uses);
    }

    // Query Scopes
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    public function scopePrivate(Builder $query): Builder
    {
        return $query->where('is_public', false);
    }

    public function scopeValid(Builder $query): Builder
    {
        $now = Carbon::now();
        return $query->where('is_active', true)
            ->where('starts_at', '<=', $now)
            ->where('expires_at', '>=', $now)
            ->where(function ($q) {
                $q->whereNull('max_uses')
                    ->orWhereRaw('total_uses < max_uses');
            });
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<', Carbon::now());
    }

    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('starts_at', '>', Carbon::now());
    }

    public function scopeForSubscription(Builder $query, int $subscriptionId): Builder
    {
        return $query->where(function ($q) use ($subscriptionId) {
            $q->whereNull('applicable_subscriptions')
                ->orWhereJsonContains('applicable_subscriptions', $subscriptionId);
        });
    }

    // Helper Methods
    public function canBeUsedBy(User $user, ?Subscription $subscription = null): array
    {
        $errors = [];

        // Check if promo is valid
        if (!$this->is_valid) {
            $errors[] = 'Promo code is not valid or has expired';
        }

        // Check if it's public or user has been assigned
        if (!$this->is_public) {
            $userPromo = $this->userPromoCodes()->where('user_id', $user->id)->first();
            if (!$userPromo || $userPromo->status !== 'assigned') {
                $errors[] = 'You are not authorized to use this promo code';
            }
        }

        // Check subscription applicability
        if ($subscription && $this->applicable_subscriptions) {
            if (!in_array($subscription->id, $this->applicable_subscriptions)) {
                $errors[] = 'This promo code is not applicable to the selected subscription';
            }
        }

        // Check minimum amount
        if ($subscription && $this->minimum_amount && $subscription->price < $this->minimum_amount) {
            $errors[] = "Minimum purchase amount of $" . number_format($this->minimum_amount, 2) . " required";
        }

        // Check user usage limit
        $userUsageCount = $this->userPromoCodes()
            ->where('user_id', $user->id)
            ->where('status', 'used')
            ->sum('uses_count');

        if ($userUsageCount >= $this->max_uses_per_user) {
            $errors[] = 'You have already used this promo code the maximum number of times';
        }

        return [
            'can_use' => empty($errors),
            'errors' => $errors,
        ];
    }

    public function calculateDiscount(float $originalAmount): array
    {
        $discountAmount = 0;

        switch ($this->discount_type) {
            case 'percentage':
                $discountAmount = ($originalAmount * $this->discount_value) / 100;
                if ($this->max_discount_amount) {
                    $discountAmount = min($discountAmount, $this->max_discount_amount);
                }
                break;

            case 'fixed_amount':
                $discountAmount = min($this->discount_value, $originalAmount);
                break;

            case 'free_access':
                $discountAmount = $originalAmount;
                break;
        }

        $finalAmount = max(0, $originalAmount - $discountAmount);

        return [
            'original_amount' => $originalAmount,
            'discount_amount' => $discountAmount,
            'final_amount' => $finalAmount,
            'discount_percentage' => $originalAmount > 0 ? ($discountAmount / $originalAmount) * 100 : 0,
        ];
    }

    public function incrementUsage(): void
    {
        $this->increment('total_uses');
    }
}
