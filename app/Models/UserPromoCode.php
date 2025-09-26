<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $promo_code_id
 * @property int|null $subscription_id
 * @property string $status
 * @property Carbon $assigned_at
 * @property Carbon|null $used_at
 * @property Carbon|null $expires_at
 * @property float|null $discount_applied
 * @property float|null $original_amount
 * @property float|null $final_amount
 * @property int $uses_count
 * @property int|null $assigned_by
 * @property string|null $assignment_notes
 * @property array|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read PromoCode $promoCode
 * @property-read Subscription|null $subscription
 * @property-read User|null $assignedBy
 * @property-read string $status_label
 * @property-read bool $is_expired
 * @property-read bool $can_be_used
 */
class UserPromoCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'promo_code_id',
        'subscription_id',
        'status',
        'assigned_at',
        'used_at',
        'expires_at',
        'discount_applied',
        'original_amount',
        'final_amount',
        'uses_count',
        'assigned_by',
        'assignment_notes',
        'metadata',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'used_at' => 'datetime',
        'expires_at' => 'datetime',
        'discount_applied' => 'decimal:2',
        'original_amount' => 'decimal:2',
        'final_amount' => 'decimal:2',
        'metadata' => 'array',
    ];

    protected $appends = [
        'status_label',
        'is_expired',
        'can_be_used',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    // Computed Properties
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'assigned' => 'Available',
            'used' => 'Used',
            'expired' => 'Expired',
            'revoked' => 'Revoked',
            default => ucfirst($this->status),
        };
    }

    public function getIsExpiredAttribute(): bool
    {
        if ($this->expires_at) {
            return Carbon::now()->isAfter($this->expires_at);
        }

        return $this->promoCode?->is_expired ?? false;
    }

    public function getCanBeUsedAttribute(): bool
    {
        return $this->status === 'assigned'
            && !$this->is_expired
            && $this->uses_count < ($this->promoCode?->max_uses_per_user ?? 1);
    }

    // Query Scopes
    public function scopeAssigned(Builder $query): Builder
    {
        return $query->where('status', 'assigned');
    }

    public function scopeUsed(Builder $query): Builder
    {
        return $query->where('status', 'used');
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', 'expired');
    }

    public function scopeRevoked(Builder $query): Builder
    {
        return $query->where('status', 'revoked');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'assigned')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', Carbon::now());
            });
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForPromoCode(Builder $query, int $promoCodeId): Builder
    {
        return $query->where('promo_code_id', $promoCodeId);
    }

    // Helper Methods
    public function markAsUsed(float $originalAmount, float $discountApplied, float $finalAmount, ?int $subscriptionId = null): void
    {
        $this->update([
            'status' => 'used',
            'used_at' => Carbon::now(),
            'subscription_id' => $subscriptionId,
            'original_amount' => $originalAmount,
            'discount_applied' => $discountApplied,
            'final_amount' => $finalAmount,
            'uses_count' => $this->uses_count + 1,
        ]);

        // Increment total usage on the promo code
        $this->promoCode?->incrementUsage();
    }

    public function revoke(string $reason = null): void
    {
        $this->update([
            'status' => 'revoked',
            'metadata' => array_merge($this->metadata ?? [], [
                'revoked_at' => Carbon::now()->toISOString(),
                'revoked_reason' => $reason,
            ]),
        ]);
    }

    public function extend(Carbon $newExpiryDate, string $reason = null): void
    {
        $this->update([
            'expires_at' => $newExpiryDate,
            'metadata' => array_merge($this->metadata ?? [], [
                'extended_at' => Carbon::now()->toISOString(),
                'extension_reason' => $reason,
                'previous_expiry' => $this->expires_at?->toISOString(),
            ]),
        ]);
    }
}
