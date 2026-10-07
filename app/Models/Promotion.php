<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Promotion extends Model
{
    public const TYPES = ['partner', 'bundle', 'campaign'];

    protected $fillable = ['organizer_business_id', 'slug', 'type', 'title', 'description', 'image_path', 'starts_at', 'ends_at', 'status'];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Promotion $p) {
            if (!$p->slug) {
                $base = Str::slug($p->title) ?: 'promotion';
                $slug = $base;
                while (static::query()->where('slug', $slug)->exists()) {
                    $slug = $base . '-' . Str::lower(Str::random(4));
                }
                $p->slug = $slug;
            }
        });
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'organizer_business_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(PromotionParticipant::class)->orderBy('sort')->orderBy('id');
    }

    /** Live and inside its dates. */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->where('status', 'live')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? asset('storage/' . $this->image_path) : null;
    }

    public function getPublicUrlAttribute(): string
    {
        return route('public.promotion', ['slug' => $this->slug]);
    }
}
