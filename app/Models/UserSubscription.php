<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 *
 *
 * @property int $id
 * @property int $user_id
 * @property int $subscription_id
 * @property string $status
 * @property \Illuminate\Support\Carbon $starts_at
 * @property \Illuminate\Support\Carbon|null $ends_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property string $amount_paid
 * @property string|null $payment_method
 * @property string|null $transaction_id
 * @property array|null $subscription_data
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $user
 * @property-read \App\Models\Subscription $subscription
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSubscription active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSubscription expired()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSubscription cancelled()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSubscription newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSubscription newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSubscription query()
 * @mixin \Eloquent
 */
class UserSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'subscription_id',
        'status',
        'starts_at',
        'ends_at',
        'cancelled_at',
        'amount_paid',
        'payment_method',
        'transaction_id',
        'subscription_data',
    ];

    protected $casts = [
        'subscription_data' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'amount_paid' => 'decimal:2',
    ];

    /**
     * Get the user that owns this subscription.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the subscription plan.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Scope for active subscriptions.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where(function ($q) {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            });
    }

    /**
     * Scope for expired subscriptions.
     */
    public function scopeExpired($query)
    {
        return $query->where('status', 'expired')
            ->orWhere(function ($q) {
                $q->whereNotNull('ends_at')
                    ->where('ends_at', '<=', now());
            });
    }

    /**
     * Scope for cancelled subscriptions.
     */
    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    /**
     * Check if subscription is currently active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active' &&
            $this->starts_at <= now() &&
            ($this->ends_at === null || $this->ends_at > now());
    }

    /**
     * Check if subscription is expired.
     */
    public function isExpired(): bool
    {
        return $this->status === 'expired' ||
            ($this->ends_at !== null && $this->ends_at <= now());
    }

    /**
     * Check if subscription is cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Cancel the subscription.
     */
    public function cancel(): void
    {
        $this->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);
    }

    /**
     * Get the remaining days of the subscription.
     */
    public function getRemainingDays(): ?int
    {
        if (!$this->ends_at || $this->isExpired()) {
            return null;
        }

        return now()->diffInDays($this->ends_at, false);
    }
}
