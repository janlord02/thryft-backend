<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A business as a first-class entity, rather than a users row wearing a
 * role === 'business' hat.
 *
 * During the transition, ids of migrated businesses deliberately equal their
 * owner's users.id (see the backfill migration). Businesses created after the
 * migration get ids from 1,000,000 up, so the two ranges do not overlap and a
 * stale code path that conflates them fails loudly.
 */
class Business extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'owner_user_id',
        'slug',
        'referral_code',
        'name',
        'description',
        'phone',
        'email',
        'website',
        'logo_path',
        'cover_path',
        'page_blocks',
        'page_updated_at',
        'sells_gift_certificates',
        'kind',
        'open_to_partnerships',
        'partnership_interests',
        'partnership_pitch',
        'status',
    ];

    protected $casts = [
        'page_blocks' => 'array',
        'page_updated_at' => 'datetime',
        'sells_gift_certificates' => 'boolean',
        'open_to_partnerships' => 'boolean',
        'partnership_interests' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (Business $business) {
            if (empty($business->slug)) {
                $business->slug = static::generateSlug($business->name ?? 'business');
            }
            if (empty($business->referral_code)) {
                $business->referral_code = static::generateReferralCode($business->name ?? '');
            }
        });
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class);
    }

    /**
     * A short, shareable, permanent code: up to six letters of the name plus
     * four random characters, e.g. FERNWO7K2Q. Fixed at creation so printed
     * material never goes stale.
     */
    public static function generateReferralCode(string $name): string
    {
        $base = Str::upper(Str::limit(preg_replace('/[^A-Za-z0-9]/', '', $name), 6, '')) ?: 'THRYFT';

        do {
            $code = $base . Str::upper(Str::random(4));
        } while (static::withTrashed()->where('referral_code', $code)->exists());

        return $code;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(BusinessLocation::class);
    }

    public function primaryLocation()
    {
        return $this->hasOne(BusinessLocation::class)->where('is_primary', true);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class);
    }

    public function claimedCoupons(): HasMany
    {
        return $this->hasMany(ClaimedCoupon::class, 'business_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Is this business paid up?
     *
     * Entitlement belongs to the BUSINESS, not to whoever happens to be making
     * the request. Checking the acting user's own subscription — as the gate
     * originally did — meant a staff member needed to buy their own plan
     * before they could work a till, which makes staff accounts unusable.
     *
     * Subscriptions currently hang off the owner's user record, so that is
     * where this looks. When they move onto businesses directly, only this
     * method changes.
     */
    public function hasActiveSubscription(): bool
    {
        return $this->owner?->hasActiveSubscription() ?? false;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * The users-table names that code written before the extraction reads
     * off a claimed coupon's business. Kept so those readers keep working
     * now that the relation resolves to a Business.
     */
    public function getBusinessNameAttribute(): ?string
    {
        return $this->name;
    }

    public function getProfileImageUrlAttribute(): ?string
    {
        if ($this->logo_path) {
            return asset('storage/' . $this->logo_path);
        }

        return $this->owner?->profile_image_url;
    }

    /**
     * Slugs become public URL segments in Phase 4, so they must be unique.
     * Probe for a free suffix rather than assuming; unlike the backfill, there
     * is no id available yet at creation time.
     */
    public static function generateSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name), 80, '') ?: 'business';
        $slug = $base;
        $suffix = 2;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $suffix++;
        }

        return $slug;
    }

    /** Every location, main one first, in the shape the app and the public page show. */
    public function publicLocations(): array
    {
        return $this->locations()
            ->orderByDesc('is_primary')
            ->orderBy('label')
            ->get()
            ->map(fn (BusinessLocation $l) => [
                'id' => $l->id,
                'label' => $l->label,
                'is_primary' => (bool) $l->is_primary,
                'address' => $l->address,
                'city' => $l->city,
                'state' => $l->state,
                'zipcode' => $l->zipcode,
                'country' => $l->country,
                'latitude' => $l->latitude,
                'longitude' => $l->longitude,
                'hours' => $l->hours ?: [],
            ])->values()->all();
    }

    /** Who a wallet item is from, as the shopper's wallet shows it. */
    public function walletSummary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'owner_user_id' => $this->owner_user_id,
            // The uploaded logo only: the owner's default avatar is a stranger's face here.
            'logo_url' => $this->logo_path ? asset('storage/' . $this->logo_path) : null,
        ];
    }

    public function isOrganization(): bool
    {
        return $this->kind === 'organization';
    }

    /** Businesses that belong to this organization. */
    public function memberBusinesses(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(self::class, 'organization_members', 'organization_id', 'business_id')
            ->withPivot('status')
            ->withTimestamps();
    }

    /** Organizations this business belongs to. */
    public function organizations(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(self::class, 'organization_members', 'business_id', 'organization_id')
            ->withPivot('status')
            ->withTimestamps();
    }

    /**
     * What a business page shows about working with others: an
     * organization's member businesses, and running promotions this
     * business organizes or takes part in.
     */
    public function communityPayload(): array
    {
        $members = $this->isOrganization()
            ? $this->memberBusinesses()->wherePivot('status', 'active')->where('businesses.status', 'active')->with(['primaryLocation', 'owner'])->orderBy('name')->get()
                ->map(fn (Business $b) => \App\Http\Controllers\PartnershipController::cardFor($b))->values()->all()
            : [];

        $promotions = Promotion::query()->running()
            ->where(fn ($q) => $q->where('organizer_business_id', $this->id)
                ->orWhereHas('participants', fn ($p) => $p->where('business_id', $this->id)->where('status', 'accepted')))
            ->orderByRaw('ends_at IS NULL, ends_at')
            ->limit(10)
            ->get()
            ->map(fn (Promotion $p) => ['slug' => $p->slug, 'title' => $p->title, 'type' => $p->type, 'ends_at' => $p->ends_at, 'public_url' => $p->public_url])
            ->values()->all();

        return ['kind' => $this->kind, 'members' => $members, 'promotions' => $promotions];
    }

    /**
     * The business behind an id from the consumer app, which addresses a
     * business by its owner's user id. That is tried first: a businesses.id
     * that happens to equal the number would otherwise be someone else's.
     */
    public static function fromAppId(int $id): ?self
    {
        return static::query()->where('owner_user_id', $id)->first()
            ?? static::query()->whereKey($id)->first();
    }
}
