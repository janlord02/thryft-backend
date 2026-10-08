<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyProgram extends Model
{
    protected $fillable = ['business_id', 'title', 'reward', 'stamps_required', 'terms', 'is_active'];

    protected $casts = [
        'stamps_required' => 'integer',
        'is_active' => 'boolean',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function cards(): HasMany
    {
        return $this->hasMany(LoyaltyCard::class, 'program_id');
    }
}
