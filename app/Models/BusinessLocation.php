<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A physical location belonging to a business.
 *
 * Exists from the start of the extraction even though multi-location is a much
 * later roadmap item: putting the address here now means that feature is UI
 * work rather than another migration over products, coupons and events.
 */
class BusinessLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'label',
        'address',
        'city',
        'state',
        'zipcode',
        'country',
        'latitude',
        'longitude',
        'hours',
        'is_primary',
    ];

    protected $casts = [
        'hours' => 'array',
        'is_primary' => 'boolean',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    public function getFullAddressAttribute(): string
    {
        return collect([$this->address, $this->city, $this->state, $this->zipcode, $this->country])
            ->filter()
            ->implode(', ');
    }
}
