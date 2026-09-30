<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An update from a business that is neither a coupon nor an event: a new
 * product, a menu change, a reopening, holiday hours.
 */
class Announcement extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'title',
        'body',
        'image_path',
        'link_url',
        'status',
        'published_at',
        'followers_notified_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'followers_notified_at' => 'datetime',
    ];

    protected $appends = ['image_url'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? asset('storage/' . $this->image_path) : null;
    }
}
