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
 * @property string $endpoint
 * @property string $p256dh_key
 * @property string $auth_token
 * @property bool $active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushSubscription active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushSubscription newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushSubscription newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushSubscription query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushSubscription whereActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushSubscription whereAuthToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushSubscription whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushSubscription whereEndpoint($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushSubscription whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushSubscription whereP256dhKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushSubscription whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushSubscription whereUserId($value)
 * @mixin \Eloquent
 */
class PushSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'endpoint',
        'p256dh_key',
        'auth_token',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    /**
     * Get the user that owns the subscription.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope for active subscriptions.
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
