<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Coupon;
use App\Models\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The business page's blocks: validation on the way in, resolution on the
 * way out.
 *
 * validate() turns whatever the editor sent into the exact shape in
 * config/blocks.php, dropping unknown fields and refusing unknown types, so
 * what reaches the database is always renderable. resolve() fills the data-
 * driven blocks (deals, events, contact) from the business's live records,
 * so the app and the crawlable page show the same thing from the same JSON.
 */
class PageBlocks
{
    private const IMAGE_PATH = '/^page-media\/[A-Za-z0-9_\-.\/]+\.(jpe?g|png|gif|webp)$/i';

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function validate(mixed $blocks): array
    {
        if (!is_array($blocks)) {
            throw ValidationException::withMessages(['blocks' => ['Blocks must be a list.']]);
        }
        if (count($blocks) > config('blocks.max_blocks', 20)) {
            throw ValidationException::withMessages(['blocks' => ['A page can have at most ' . config('blocks.max_blocks', 20) . ' blocks.']]);
        }

        $types = config('blocks.types');
        $clean = [];

        foreach (array_values($blocks) as $i => $block) {
            if (!is_array($block) || !isset($block['type']) || !isset($types[$block['type']])) {
                throw ValidationException::withMessages(["blocks.$i.type" => ['Unknown block type.']]);
            }

            $out = [
                'type' => $block['type'],
                'id' => self::blockId($block['id'] ?? null),
            ];

            foreach ($types[$block['type']]['fields'] as $field => $spec) {
                $out[$field] = self::field($spec, $block[$field] ?? null, "blocks.$i.$field");
            }

            $clean[] = $out;
        }

        return $clean;
    }

    /**
     * The blocks with live data filled in and image paths turned into URLs.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function resolve(Business $business, ?array $blocks = null): array
    {
        $blocks = $blocks ?? $business->page_blocks ?? [];
        if ($blocks === []) {
            return [];
        }

        $owner = $business->relationLoaded('owner') ? $business->owner : $business->owner()->first();
        $location = $business->relationLoaded('primaryLocation') ? $business->primaryLocation : $business->primaryLocation()->first();

        return array_map(function (array $block) use ($business, $owner, $location) {
            switch ($block['type']) {
                case 'hero':
                    $block['image_url'] = self::imageUrl($block['image'] ?? null) ?: ($business->cover_path ? asset('storage/' . $business->cover_path) : null);
                    $block['headline'] = $block['headline'] ?: $business->name;
                    break;

                case 'gallery':
                    $block['image_urls'] = array_values(array_filter(array_map([self::class, 'imageUrl'], $block['images'] ?? [])));
                    break;

                case 'deals':
                    $block['items'] = Coupon::query()
                        ->where('business_id', $business->id)
                        ->active()
                        ->valid()
                        ->orderByDesc('is_featured')
                        ->orderByDesc('created_at')
                        ->limit($block['limit'] ?? 6)
                        ->get()
                        ->map(fn (Coupon $c) => [
                            'id' => $c->id,
                            'slug' => $c->slug,
                            'title' => $c->title,
                            'description' => $c->description,
                            'discount_type' => $c->discount_type,
                            'discount_amount' => $c->discount_amount,
                            'discount_percentage' => $c->discount_percentage,
                            'formatted_discount' => $c->formatted_discount,
                            'expires_at' => $c->expires_at,
                            'banner_image_url' => $c->banner_image_url,
                            'is_flash' => (bool) $c->is_flash,
                        ])->values()->all();
                    break;

                case 'events':
                    $block['items'] = Event::query()
                        ->where('business_id', $business->id)
                        ->published()
                        ->upcoming()
                        ->orderBy('starts_at')
                        ->limit($block['limit'] ?? 4)
                        ->get()
                        ->map(fn (Event $e) => [
                            'id' => $e->id,
                            'slug' => $e->slug,
                            'title' => $e->title,
                            'starts_at' => $e->starts_at,
                            'ends_at' => $e->ends_at,
                            'venue_name' => $e->venue_name,
                            'city' => $e->city,
                            'image_url' => $e->image_url,
                            'public_url' => $e->public_url,
                        ])->values()->all();
                    break;

                case 'contact':
                    $lat = $location?->latitude ?: $owner?->latitude;
                    $lng = $location?->longitude ?: $owner?->longitude;
                    $block['phone'] = $business->phone ?: $owner?->phone;
                    $block['address'] = collect([
                        $location?->address ?: $owner?->address,
                        $location?->city ?: $owner?->city,
                        $location?->state ?: $owner?->state,
                        $location?->zipcode ?: $owner?->zipcode,
                    ])->filter()->implode(', ');
                    $block['website'] = $business->website;
                    $block['map_url'] = ($block['show_map'] ?? true) && $lat && $lng
                        ? "https://www.google.com/maps/dir/?api=1&destination={$lat},{$lng}"
                        : null;
                    break;
            }

            return $block;
        }, $blocks);
    }

    /** The starter templates, with ids on every block so the editor can key them. */
    public static function templates(): array
    {
        return array_map(function (array $template) {
            $template['blocks'] = array_map(function (array $block) {
                return self::validate([$block])[0];
            }, $template['blocks']);

            return $template;
        }, config('blocks.templates', []));
    }

    /** The type catalog for the editor's "add block" sheet. */
    public static function catalog(): array
    {
        return collect(config('blocks.types'))->map(fn ($t, $key) => [
            'type' => $key,
            'label' => $t['label'],
            'description' => $t['description'],
            'icon' => $t['icon'],
        ])->values()->all();
    }

    private static function field(string $spec, mixed $value, string $key): mixed
    {
        [$kind, $arg] = array_pad(explode(':', $spec, 2), 2, null);

        switch ($kind) {
            case 'string':
            case 'text':
                $value = is_scalar($value) ? trim(strip_tags((string) $value)) : '';
                if (Str::length($value) > (int) $arg) {
                    throw ValidationException::withMessages([$key => ["Keep this under {$arg} characters."]]);
                }

                return $value === '' ? null : $value;

            case 'int':
                [$min, $max] = array_map('intval', explode(',', $arg));
                if ($value === null || $value === '') {
                    return $min;
                }
                if (!is_numeric($value)) {
                    throw ValidationException::withMessages([$key => ['Must be a number.']]);
                }

                return max($min, min($max, (int) $value));

            case 'bool':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);

            case 'url':
                $value = is_string($value) ? trim($value) : '';
                if ($value === '') {
                    return null;
                }
                if (!preg_match('/^https?:\/\//i', $value) || !filter_var($value, FILTER_VALIDATE_URL)) {
                    throw ValidationException::withMessages([$key => ['Enter a full web address starting with http:// or https://.']]);
                }

                return Str::limit($value, 500, '');

            case 'path':
                $value = is_string($value) ? trim($value) : '';
                if ($value === '') {
                    return null;
                }
                if (!preg_match(self::IMAGE_PATH, $value)) {
                    throw ValidationException::withMessages([$key => ['Upload the image through the editor.']]);
                }

                return $value;

            case 'paths':
                $list = is_array($value) ? array_values($value) : [];
                if (count($list) > (int) $arg) {
                    throw ValidationException::withMessages([$key => ["Up to {$arg} images."]]);
                }
                foreach ($list as $path) {
                    if (!is_string($path) || !preg_match(self::IMAGE_PATH, $path)) {
                        throw ValidationException::withMessages([$key => ['Upload the images through the editor.']]);
                    }
                }

                return $list;

            case 'rows':
                $rows = is_array($value) ? array_values($value) : [];
                if (count($rows) > (int) $arg) {
                    throw ValidationException::withMessages([$key => ["Up to {$arg} rows."]]);
                }
                $out = [];
                foreach ($rows as $row) {
                    $label = is_array($row) && isset($row['label']) ? trim(strip_tags((string) $row['label'])) : '';
                    $val = is_array($row) && isset($row['value']) ? trim(strip_tags((string) $row['value'])) : '';
                    if ($label === '' && $val === '') {
                        continue;
                    }
                    $out[] = ['label' => Str::limit($label, 40, ''), 'value' => Str::limit($val, 60, '')];
                }

                return $out;
        }

        return null;
    }

    private static function blockId(mixed $id): string
    {
        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{4,40}$/', $id) ? $id : Str::lower(Str::random(10));
    }

    private static function imageUrl(?string $path): ?string
    {
        return $path && preg_match(self::IMAGE_PATH, $path) ? asset('storage/' . $path) : null;
    }
}
