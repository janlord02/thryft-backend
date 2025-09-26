<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * 
 *
 * @property int $id
 * @property string $title
 * @property string $message
 * @property string $type
 * @property array<array-key, mixed>|null $data
 * @property string $channel
 * @property bool $urgent
 * @property \Illuminate\Support\Carbon|null $scheduled_at
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read string $type_color
 * @property-read string $type_icon
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 * @property-read int|null $users_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Notification newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Notification newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Notification query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Notification scheduled()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Notification unread()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Notification urgent()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Notification whereChannel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Notification whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Notification whereData($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Notification whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Notification whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Notification whereScheduledAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Notification whereSentAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Notification whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Notification whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Notification whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Notification whereUrgent($value)
 * @mixin \Eloquent
 */
class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'message',
        'type',
        'data',
        'channel',
        'urgent',
        'scheduled_at',
        'sent_at',
    ];

    protected $casts = [
        'data' => 'array',
        'urgent' => 'boolean',
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    /**
     * Get the users that received this notification.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'notification_user')
            ->withPivot(['read', 'read_at', 'email_sent', 'push_sent', 'email_sent_at', 'push_sent_at'])
            ->withTimestamps();
    }

    /**
     * Scope for unread notifications.
     */
    public function scopeUnread($query)
    {
        return $query->whereHas('users', function ($q) {
            $q->where('read', false);
        });
    }

    /**
     * Scope for urgent notifications.
     */
    public function scopeUrgent($query)
    {
        return $query->where('urgent', true);
    }

    /**
     * Scope for scheduled notifications.
     */
    public function scopeScheduled($query)
    {
        return $query->whereNotNull('scheduled_at')->where('scheduled_at', '<=', now());
    }

    /**
     * Get notification type color.
     */
    public function getTypeColorAttribute(): string
    {
        return match ($this->type) {
            'info' => 'info',
            'warning' => 'warning',
            'error' => 'negative',
            'success' => 'positive',
            'urgent' => 'negative',
            default => 'grey',
        };
    }

    /**
     * Get notification icon.
     */
    public function getTypeIconAttribute(): string
    {
        return match ($this->type) {
            'info' => 'info',
            'warning' => 'warning',
            'error' => 'error',
            'success' => 'check_circle',
            'urgent' => 'priority_high',
            default => 'notifications',
        };
    }
}
