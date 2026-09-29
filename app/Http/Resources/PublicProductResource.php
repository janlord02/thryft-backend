<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A product as seen by an anonymous visitor. ALLOWLIST — see
 * PublicBusinessResource for the rationale.
 */
class PublicProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'slug' => $this->resource->slug,
            'description' => $this->resource->description,
            'image_url' => $this->resource->image ? asset('storage/' . $this->resource->image) : null,
            'is_featured' => (bool) $this->resource->is_featured,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->resource->category?->id,
                'name' => $this->resource->category?->name,
            ]),
            'tags' => $this->whenLoaded('tags', fn () => $this->resource->tags->map(fn ($tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
            ])->values()),
        ];
    }
}
