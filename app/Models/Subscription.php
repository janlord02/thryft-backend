<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 *
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string $price
 * @property string $billing_cycle
 * @property array|null $features
 * @property bool $is_popular
 * @property bool $status
 * @property bool $on_show
 * @property int|null $max_users
 * @property int|null $max_storage_gb
 * @property int $sort_order
 * @property string|null $stripe_price_id
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read string $formatted_price
 * @property-read string $billing_cycle_label
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\UserSubscription> $userSubscriptions
 * @property-read int|null $user_subscriptions_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 * @property-read int|null $users_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription visible()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription popular()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription ordered()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription query()
 * @mixin \Eloquent
 */
class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'price',
        'billing_cycle',
        'features',
        'is_popular',
        'status',
        'on_show',
        'sort_order',
        'stripe_price_id',
        'metadata',
    ];

    protected $casts = [
        'features' => 'array',
        'metadata' => 'array',
        'is_popular' => 'boolean',
        'status' => 'boolean',
        'on_show' => 'boolean',
        'price' => 'decimal:2',
    ];

    protected $appends = [
        'formatted_price',
        'billing_cycle_label',
    ];

    /**
     * Get the user subscriptions for this subscription plan.
     */
    public function userSubscriptions(): HasMany
    {
        return $this->hasMany(UserSubscription::class);
    }

    /**
     * Get the users who have this subscription.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_subscriptions')
            ->withPivot([
                'status',
                'starts_at',
                'ends_at',
                'cancelled_at',
                'amount_paid',
                'payment_method',
                'transaction_id',
                'subscription_data'
            ])
            ->withTimestamps();
    }

    /**
     * Scope for active subscriptions.
     */
    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    /**
     * Scope for visible subscriptions.
     */
    public function scopeVisible($query)
    {
        return $query->where('on_show', true);
    }

    /**
     * Scope for popular subscriptions.
     */
    public function scopePopular($query)
    {
        return $query->where('is_popular', true);
    }

    /**
     * Scope for ordered subscriptions.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Get formatted price attribute.
     */
    public function getFormattedPriceAttribute(): string
    {
        if ($this->price == 0) {
            return 'Free';
        }

        return '$' . number_format($this->price, 2);
    }

    /**
     * Get billing cycle label attribute.
     */
    public function getBillingCycleLabelAttribute(): string
    {
        return match ($this->billing_cycle) {
            'monthly' => 'per month',
            'yearly' => 'per year',
            'lifetime' => 'one-time',
            'weekly' => 'per week',
            'daily' => 'per day',
            default => $this->billing_cycle,
        };
    }

    /**
     * Check if subscription is free.
     */
    public function isFree(): bool
    {
        return $this->price == 0;
    }

    /**
     * Check if subscription is active.
     */
    public function isActive(): bool
    {
        return $this->status;
    }

    /**
     * Check if subscription is visible to users.
     */
    public function isVisible(): bool
    {
        return $this->on_show;
    }

    /**
     * Get active subscriber count.
     */
    public function getActiveSubscriberCount(): int
    {
        return $this->userSubscriptions()
            ->where('status', 'active')
            ->count();
    }

    /**
     * Get total revenue for this subscription.
     */
    public function getTotalRevenue(): float
    {
        return $this->userSubscriptions()
            ->whereIn('status', ['active', 'cancelled', 'expired'])
            ->sum('amount_paid');
    }

    /**
     * Get subscription features as formatted list.
     */
    public function getFormattedFeatures(): array
    {
        if (!$this->features) {
            return [];
        }

        return array_map(function ($feature) {
            return is_string($feature) ? $feature : $feature['name'] ?? 'Unknown feature';
        }, $this->features);
    }
}
