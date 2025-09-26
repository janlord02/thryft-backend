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
 * @property string $type
 * @property bool $database_enabled
 * @property bool $email_enabled
 * @property bool $push_enabled
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationPreference newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationPreference newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationPreference query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationPreference whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationPreference whereDatabaseEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationPreference whereEmailEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationPreference whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationPreference wherePushEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationPreference whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationPreference whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NotificationPreference whereUserId($value)
 * @mixin \Eloquent
 */
class NotificationPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'database_enabled',
        'email_enabled',
        'push_enabled',
    ];

    protected $casts = [
        'database_enabled' => 'boolean',
        'email_enabled' => 'boolean',
        'push_enabled' => 'boolean',
    ];

    /**
     * Get the user that owns the preference.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if a specific channel is enabled for this notification type.
     */
    public function isChannelEnabled(string $channel): bool
    {
        return match ($channel) {
            'database' => $this->database_enabled,
            'email' => $this->email_enabled,
            'push' => $this->push_enabled,
            default => false,
        };
    }
}
