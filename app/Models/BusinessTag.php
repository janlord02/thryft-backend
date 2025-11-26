<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;

class BusinessTag extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon',
        'color',
        'usage_count',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'usage_count' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($tag) {
            if (empty($tag->slug)) {
                $tag->slug = Str::slug($tag->name);
            }
        });

        static::updating(function ($tag) {
            if ($tag->isDirty('name') && empty($tag->slug)) {
                $tag->slug = Str::slug($tag->name);
            }
        });
    }

    public function businesses(): BelongsToMany
    {
        try {
            // Check if table exists before defining relationship
            if (Schema::hasTable('business_assign_tags')) {
                return $this->belongsToMany(User::class, 'business_assign_tags', 'business_tag_id', 'user_id')
                    ->where('role', 'Business')
                    ->withTimestamps();
            }
        } catch (\Exception $e) {
            // If schema check fails, return empty relationship
        }
        
        // Return empty relationship if table doesn't exist
        return $this->belongsToMany(User::class, 'business_assign_tags', 'business_tag_id', 'user_id')
            ->whereRaw('1 = 0'); // Always return empty
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where('name', 'like', '%' . $search . '%');
    }

    public function scopePopular($query)
    {
        return $query->orderBy('usage_count', 'desc');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function incrementUsage()
    {
        $this->increment('usage_count');
    }

    public function decrementUsage()
    {
        $this->decrement('usage_count');
    }
}

