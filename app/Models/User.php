<?php

namespace App\Models;

use App\Notifications\VerifyEmailNotification;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Notification;
use App\Models\Coupon;

/**
 *
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $profile_image
 * @property string|null $phone
 * @property string|null $bio
 * @property string|null $two_factor_secret
 * @property bool $two_factor_enabled
 * @property \Illuminate\Support\Carbon|null $two_factor_confirmed_at
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property string $role
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ActivityLog> $activityLogs
 * @property-read int|null $activity_logs_count
 * @property-read mixed $profile_image_url
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\NotificationPreference> $notificationPreferences
 * @property-read int|null $notification_preferences_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Notification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PushSubscription> $pushSubscriptions
 * @property-read int|null $push_subscriptions_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Sanctum\PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereBio($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereProfileImage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTwoFactorConfirmedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTwoFactorEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTwoFactorSecret($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'firstname',
        'lastname',
        'address',
        'city',
        'state',
        'zipcode',
        'country',
        'latitude',
        'longitude',
        'business_name',
        'business_description',
        'email',
        'password',
        'role',
        'profile_image',
        'phone',
        'bio',
        'two_factor_secret',
        'two_factor_enabled',
        'two_factor_confirmed_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = [
        'profile_image_url',
        'full_name',
        'display_name',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_enabled' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Send the email verification notification.
     *
     * @return void
     */
    public function sendEmailVerificationNotification()
    {
        $this->notify(new VerifyEmailNotification);
    }

    /**
     * Get the user's profile image URL.
     */
    public function getProfileImageUrlAttribute()
    {
        if ($this->profile_image) {
            return asset('storage/' . $this->profile_image);
        }
        return asset('images/default-avatar.svg');
    }

    /**
     * Get the user's full name.
     */
    public function getFullNameAttribute()
    {
        if ($this->firstname && $this->lastname) {
            return trim($this->firstname . ' ' . $this->lastname);
        }

        if ($this->name) {
            return $this->name;
        }

        return $this->email;
    }

    /**
     * Get the user's display name (firstname + lastname or name or email).
     */
    public function getDisplayNameAttribute()
    {
        return $this->getFullNameAttribute();
    }

    /**
     * Check if 2FA is enabled and confirmed.
     */
    public function hasTwoFactorEnabled()
    {
        return $this->two_factor_enabled && $this->two_factor_confirmed_at;
    }

    /**
     * Get the activity logs for the user.
     */
    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }

    /**
     * Get the notifications for the user.
     */
    public function notifications()
    {
        return $this->belongsToMany(Notification::class, 'notification_user')
            ->withPivot(['read', 'read_at', 'email_sent', 'push_sent', 'email_sent_at', 'push_sent_at'])
            ->withTimestamps();
    }

    /**
     * Get the unread notifications for the user.
     */
    public function unreadNotifications()
    {
        return $this->notifications()->wherePivot('read', false);
    }

    /**
     * Get the push subscriptions for the user.
     */
    public function pushSubscriptions()
    {
        return $this->hasMany(PushSubscription::class);
    }

    /**
     * Get the notification preferences for the user.
     */
    public function notificationPreferences()
    {
        return $this->hasMany(NotificationPreference::class);
    }

    /**
     * Get the products for the user (business).
     */
    public function products()
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Get the coupons created by the user (business).
     */
    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class);
    }

    /**
     * Get the claimed coupons for the user.
     */
    public function claimedCoupons()
    {
        return $this->hasMany(ClaimedCoupon::class);
    }

    /**
     * Get the products that the user has favorited.
     */
    public function favoriteProducts()
    {
        return $this->belongsToMany(Product::class, 'product_favorites')
            ->withTimestamps();
    }

    /**
     * Get notification preference for a specific type.
     */
    public function getNotificationPreference(string $type): ?NotificationPreference
    {
        return $this->notificationPreferences()->where('type', $type)->first();
    }

    /**
     * Check if user has unread notifications.
     */
    public function hasUnreadNotifications(): bool
    {
        return $this->unreadNotifications()->exists();
    }

    /**
     * Get unread notifications count.
     */
    public function getUnreadNotificationsCount(): int
    {
        return $this->unreadNotifications()->count();
    }

    /**
     * Scope for finding nearby businesses based on latitude and longitude
     */
    public function scopeNearbyBusinesses($query, $latitude, $longitude, $radius, $limit)
    {
        return $query->select('*')
            ->selectRaw("(3959 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) AS distance", [$latitude, $longitude, $latitude])
            ->having('distance', '<=', $radius)
            ->where('role', 'Business')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('distance')
            ->limit($limit);
    }
}
