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
     * Businesses this user owns.
     *
     * A hasMany rather than a hasOne from the outset: ownership becomes
     * many-to-many through business_members in Phase 3b, and callers written
     * against a collection will not need rewriting then.
     */
    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class, 'owner_user_id');
    }

    /**
     * Memberships giving this user standing in a business.
     */
    public function businessMemberships(): HasMany
    {
        return $this->hasMany(BusinessMember::class);
    }

    /**
     * Businesses this user may act for, via an active membership.
     */
    public function memberBusinesses()
    {
        return $this->belongsToMany(Business::class, 'business_members')
            ->withPivot(['role', 'permissions', 'status'])
            ->wherePivot('status', 'active')
            ->withTimestamps();
    }

    /**
     * Every business this user may act for, with what they may do there.
     * Sent to the app on /user so it can show the right tools.
     *
     * @return array<int, array{business_id:int,name:?string,slug:?string,role:string,abilities:array<string>}>
     */
    public function businessAccess(): array
    {
        return $this->businessMemberships()
            ->active()
            ->with('business')
            ->get()
            ->filter(fn (BusinessMember $m) => $m->business !== null)
            ->map(fn (BusinessMember $m) => [
                'business_id' => (int) $m->business_id,
                'name' => $m->business->name,
                'slug' => $m->business->slug,
                'role' => $m->role,
                'abilities' => $m->abilities(),
            ])
            ->values()
            ->all();
    }

    /**
     * The business this user is currently acting as.
     */
    public function currentBusiness(): ?Business
    {
        return \App\Support\BusinessResolver::forUser($this);
    }

    /**
     * This user's membership of a given business, if any.
     */
    public function membershipFor(Business|int|null $business): ?BusinessMember
    {
        $businessId = $business instanceof Business ? $business->id : $business;

        if (!$businessId) {
            return null;
        }

        return $this->businessMemberships()
            ->where('business_id', $businessId)
            ->active()
            ->first();
    }

    /**
     * Does this user hold an ability for a business?
     *
     * Super admins are handled by a Gate::before hook rather than here, so this
     * stays a pure membership question.
     *
     * The legacy fallback matters during the rollout: a user whose role is
     * still 'business' and who owns the business, but for whom no membership
     * row exists yet, is treated as its owner. Without it, any account
     * promoted to business after the backfill would lose access.
     */
    public function hasBusinessAbility(string $ability, Business|int|null $business): bool
    {
        $membership = $this->membershipFor($business);

        if ($membership) {
            return $membership->hasAbility($ability);
        }

        $businessId = $business instanceof Business ? $business->id : $business;

        if ($businessId && $this->role === 'business') {
            $ownsIt = Business::whereKey($businessId)->where('owner_user_id', $this->id)->exists();

            if ($ownsIt) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the user's subscription grants.
     */
    public function userSubscriptions(): HasMany
    {
        return $this->hasMany(UserSubscription::class);
    }

    /**
     * The subscription currently entitling this user to business features, if
     * any. Includes a past_due subscription still inside its grace period —
     * see UserSubscription::grantsAccess().
     */
    public function activeSubscription(): ?UserSubscription
    {
        return $this->userSubscriptions()
            ->grantingAccess()
            ->with('subscription')
            ->latest('starts_at')
            ->first();
    }

    public function hasActiveSubscription(): bool
    {
        return $this->activeSubscription() !== null;
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
     * Businesses this shopper has saved.
     */
    public function favoriteBusinesses()
    {
        return $this->belongsToMany(User::class, 'business_favorites', 'user_id', 'business_id')
            ->withTimestamps();
    }

    /**
     * Shoppers who have saved this business.
     */
    public function favoritedByShoppers()
    {
        return $this->belongsToMany(User::class, 'business_favorites', 'business_id', 'user_id')
            ->withTimestamps();
    }

    /**
     * Get the business tags for the user (business).
     */
    public function businessTags()
    {
        try {
            // Check if tables exist before defining relationship
            if (\Illuminate\Support\Facades\Schema::hasTable('business_tags') && 
                \Illuminate\Support\Facades\Schema::hasTable('business_assign_tags')) {
                return $this->belongsToMany(BusinessTag::class, 'business_assign_tags', 'user_id', 'business_tag_id')
                    ->withTimestamps();
            }
        } catch (\Exception $e) {
            // If schema check fails, return empty relationship
        }
        
        // Return empty relationship if tables don't exist
        return $this->belongsToMany(BusinessTag::class, 'business_assign_tags', 'user_id', 'business_tag_id')
            ->whereRaw('1 = 0'); // Always return empty
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
        // Haversine, in miles. Repeated in the WHERE rather than filtered with
        // HAVING: HAVING on a query with no GROUP BY is a MySQL extension, and
        // SQLite rejects it outright ("HAVING clause on a non-aggregate
        // query") — which made this endpoint, and anything testing it,
        // impossible to run locally.
        $haversine = '(3959 * acos(cos(radians(?)) * cos(radians(latitude))'
            . ' * cos(radians(longitude) - radians(?))'
            . ' + sin(radians(?)) * sin(radians(latitude))))';

        return $query->select('*')
            ->selectRaw("{$haversine} AS distance", [$latitude, $longitude, $latitude])
            ->whereRaw("{$haversine} <= ?", [$latitude, $longitude, $latitude, $radius])
            // Lowercase: that is the value every write path and RoleMiddleware
            // use. 'Business' only ever matched because MySQL's default
            // collation is case-insensitive; it matches nothing on SQLite or
            // under a binary collation.
            ->where('role', 'business')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('distance')
            ->limit($limit);
    }
}
