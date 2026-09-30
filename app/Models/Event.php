<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Something a business hosts at a time and place.
 *
 * Lifecycle: draft → published (→ cancelled). Only published events are
 * listed, crawlable, or open to registration. The slug is the public URL
 * segment (/e/{slug}) and is fixed at creation so shared links keep working
 * after a title edit.
 */
class Event extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'location_id',
        'slug',
        'title',
        'description',
        'image_path',
        'starts_at',
        'ends_at',
        'venue_name',
        'address',
        'city',
        'latitude',
        'longitude',
        'capacity',
        'registered_count',
        'registration_enabled',
        'status',
        'published_at',
        'followers_notified_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'published_at' => 'datetime',
        'followers_notified_at' => 'datetime',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'capacity' => 'integer',
        'registered_count' => 'integer',
        'registration_enabled' => 'boolean',
    ];

    protected $appends = ['image_url', 'spots_left', 'is_full', 'public_url'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (Event $event) {
            if (empty($event->slug)) {
                $event->slug = static::generateSlug($event->title ?? 'event');
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    /** Not over yet: ends_at when set, otherwise starts_at. */
    public function scopeUpcoming(Builder $query): Builder
    {
        $now = now();

        return $query->where(function (Builder $q) use ($now) {
            $q->where('ends_at', '>=', $now)
                ->orWhere(function (Builder $q2) use ($now) {
                    $q2->whereNull('ends_at')->where('starts_at', '>=', $now);
                });
        });
    }

    public function isOver(): bool
    {
        $end = $this->ends_at ?? $this->starts_at;

        return $end !== null && $end->isPast();
    }

    public function isFull(): bool
    {
        return $this->capacity !== null && $this->registered_count >= $this->capacity;
    }

    /** Seats still open, or null when uncapped. */
    public function spotsLeft(): ?int
    {
        if ($this->capacity === null) {
            return null;
        }

        return max(0, (int) $this->capacity - (int) $this->registered_count);
    }

    public function isOpenForRegistration(): bool
    {
        return $this->status === 'published'
            && $this->registration_enabled
            && !$this->isOver()
            && !$this->isFull();
    }

    /**
     * Take one seat. Conditional UPDATE so two concurrent registrations cannot
     * both take the last one; returns false when the event is full.
     */
    public function tryTakeSeat(): bool
    {
        $affected = static::query()
            ->whereKey($this->id)
            ->where(function (Builder $q) {
                $q->whereNull('capacity')->orWhereColumn('registered_count', '<', 'capacity');
            })
            ->update(['registered_count' => DB::raw('registered_count + 1'), 'updated_at' => now()]);

        if ($affected === 1) {
            $this->registered_count++;
        }

        return $affected === 1;
    }

    /** Give a seat back. Guarded so a double cancel never goes negative. */
    public function releaseSeat(): void
    {
        $affected = static::query()
            ->whereKey($this->id)
            ->where('registered_count', '>', 0)
            ->update(['registered_count' => DB::raw('registered_count - 1'), 'updated_at' => now()]);

        if ($affected === 1) {
            $this->registered_count = max(0, $this->registered_count - 1);
        }
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? asset('storage/' . $this->image_path) : null;
    }

    public function getSpotsLeftAttribute(): ?int
    {
        return $this->spotsLeft();
    }

    public function getIsFullAttribute(): bool
    {
        return $this->isFull();
    }

    public function getPublicUrlAttribute(): ?string
    {
        return $this->slug ? route('public.event', ['slug' => $this->slug]) : null;
    }

    public static function generateSlug(string $title): string
    {
        $base = Str::limit(Str::slug($title), 80, '') ?: 'event';
        $slug = $base . '-' . Str::lower(Str::random(6));

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . Str::lower(Str::random(6));
        }

        return $slug;
    }
}
