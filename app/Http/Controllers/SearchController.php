<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Product;
use App\Models\Coupon;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SearchController extends Controller
{
    /**
     * Unified search across businesses, products, coupons, and tags
     */
    public function search(Request $request)
    {
        try {
            $query = $request->get('q', '');
            $type = $request->get('type', 'all'); // all, businesses, products, coupons, tags
            $limit = min($request->get('limit', 20), 50); // Max 50 results
            $latitude = $request->get('latitude');
            $longitude = $request->get('longitude');
            $radius = (float) $request->get('radius', 15); // miles
            $tags = $request->get('tags', ''); // Comma-separated tag IDs

            // Parse tag IDs
            $tagIds = [];
            if (!empty($tags)) {
                $tagIds = array_filter(array_map('intval', explode(',', $tags)));
            }

            if (empty($query) || strlen($query) < 2) {
                return response()->json([
                    'status' => 'success',
                    'data' => [
                        'businesses' => [],
                        'products' => [],
                        'coupons' => [],
                        'tags' => [],
                        'total' => 0
                    ]
                ]);
            }

            $results = [
                'businesses' => [],
                'products' => [],
                'coupons' => [],
                'tags' => [],
                'total' => 0
            ];

            // Precompute bounding box if coordinates provided
            $useLocation = is_numeric($latitude) && is_numeric($longitude);
            $minLat = $maxLat = $minLng = $maxLng = null;
            if ($useLocation) {
                $lat = (float) $latitude;
                $lng = (float) $longitude;
                $latDelta = $radius / 69.0; // ~69 miles per degree latitude
                $lngDelta = $radius / (cos(deg2rad($lat)) * 69.0);
                $minLat = $lat - $latDelta;
                $maxLat = $lat + $latDelta;
                $minLng = $lng - $lngDelta;
                $maxLng = $lng + $lngDelta;
            }

            // Detect column names for coordinates
            $usersHasLat = Schema::hasColumn('users', 'latitude');
            $usersHasLng = Schema::hasColumn('users', 'longitude');

            // Search businesses
            if ($type === 'all' || $type === 'businesses') {
                $businessQuery = User::where('role', 'business')
                    ->where(function ($q) use ($query) {
                        $q->where('business_name', 'LIKE', "%{$query}%")
                            ->orWhere('name', 'LIKE', "%{$query}%")
                            ->orWhere('business_description', 'LIKE', "%{$query}%")
                            ->orWhere('bio', 'LIKE', "%{$query}%")
                            // Include businesses whose products match the query (name/description/tags)
                            ->orWhereHas('products', function ($productQuery) use ($query) {
                                $productQuery->where(function ($pq) use ($query) {
                                    $pq->where('name', 'LIKE', "%{$query}%")
                                        ->orWhere('description', 'LIKE', "%{$query}%")
                                        ->orWhereHas('tags', function ($tq) use ($query) {
                                            $tq->where('name', 'LIKE', "%{$query}%");
                                        });
                                });
                            });
                    });

                if ($useLocation && $usersHasLat && $usersHasLng && $minLat !== null && $maxLat !== null && $minLng !== null && $maxLng !== null) {
                    $businessQuery->whereBetween('latitude', [$minLat, $maxLat])
                        ->whereBetween('longitude', [$minLng, $maxLng]);
                }

                $results['businesses'] = $businessQuery->limit($limit)->get()->map(function ($business) {
                    return [
                        'id' => $business->id,
                        'name' => $business->business_name ?: $business->name,
                        'description' => $business->business_description ?: $business->bio,
                        'profile_image_url' => $business->profile_image_url,
                        'address' => $business->address,
                        'city' => $business->city,
                        'state' => $business->state,
                        'phone' => $business->phone,
                        'email' => $business->email,
                        'distance' => null,
                        'type' => 'business'
                    ];
                });
            }

            // Search products
            if ($type === 'all' || $type === 'products') {
                $productQuery = Product::with(['category', 'user', 'tags'])
                    ->where('is_active', true)
                    ->where(function ($q) use ($query) {
                        $q->where('name', 'LIKE', "%{$query}%")
                            ->orWhere('description', 'LIKE', "%{$query}%")
                            ->orWhereHas('tags', function ($tagQuery) use ($query) {
                                $tagQuery->where('name', 'LIKE', "%{$query}%");
                            });
                    });

                if (!empty($tagIds)) {
                    $productQuery->whereHas('tags', function ($tagQuery) use ($tagIds) {
                        $tagQuery->whereIn('id', $tagIds);
                    });
                }

                if ($useLocation && $usersHasLat && $usersHasLng && $minLat !== null && $maxLat !== null && $minLng !== null && $maxLng !== null) {
                    $productQuery->whereHas('user', function ($businessQuery) use ($minLat, $maxLat, $minLng, $maxLng) {
                        $businessQuery->whereBetween('latitude', [$minLat, $maxLat])
                            ->whereBetween('longitude', [$minLng, $maxLng]);
                    });
                }

                $results['products'] = $productQuery->limit($limit)->get()->map(function ($product) {
                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'description' => $product->description,
                        'image' => $product->image ? asset('storage/' . $product->image) : null,
                        'category' => $product->category ? [
                            'id' => $product->category->id,
                            'name' => $product->category->name,
                            'icon' => $product->category->icon,
                            'color' => $product->category->color,
                        ] : null,
                        'business' => $product->user ? [
                            'id' => $product->user->id,
                            'name' => $product->user->business_name ?: $product->user->name,
                            'city' => $product->user->city,
                            'state' => $product->user->state,
                        ] : null,
                        'tags' => ($product->tags ? $product->tags->map(function ($tag) {
                            return [
                                'id' => $tag->id,
                                'name' => $tag->name,
                                'color' => $tag->color,
                            ];
                        }) : collect()),
                        'is_featured' => $product->is_featured,
                        'type' => 'product'
                    ];
                });
            }

            // Search coupons
            if ($type === 'all' || $type === 'coupons') {
                $couponQuery = Coupon::with(['products.category', 'products.user', 'products.tags'])
                    ->where('is_active', true)
                    ->where(function ($q) use ($query) {
                        $q->where('code', 'LIKE', "%{$query}%")
                            ->orWhere('description', 'LIKE', "%{$query}%")
                            ->orWhereHas('products', function ($productQuery) use ($query) {
                                $productQuery->where('name', 'LIKE', "%{$query}%")
                                    ->orWhere('description', 'LIKE', "%{$query}%")
                                    ->orWhereHas('tags', function ($tagQuery) use ($query) {
                                        $tagQuery->where('name', 'LIKE', "%{$query}%");
                                    });
                            });
                    });

                if (!empty($tagIds)) {
                    $couponQuery->where(function ($q) use ($tagIds) {
                        $q->whereHas('products.tags', function ($tagQuery) use ($tagIds) {
                            $tagQuery->whereIn('id', $tagIds);
                        })->orWhereDoesntHave('products'); // Include coupons without products
                    });
                }

                if ($useLocation && $usersHasLat && $usersHasLng && $minLat !== null && $maxLat !== null && $minLng !== null && $maxLng !== null) {
                    $couponQuery->where(function ($q) use ($minLat, $maxLat, $minLng, $maxLng) {
                        $q->whereHas('products.user', function ($businessQuery) use ($minLat, $maxLat, $minLng, $maxLng) {
                            $businessQuery->whereBetween('latitude', [$minLat, $maxLat])
                                ->whereBetween('longitude', [$minLng, $maxLng]);
                        })->orWhereDoesntHave('products'); // Include coupons without products
                    });
                }

                $results['coupons'] = $couponQuery->limit($limit)->get()->map(function ($coupon) {
                    $primaryProduct = ($coupon->products && $coupon->products->count() > 0) ? $coupon->products->first() : null;
                    return [
                        'id' => $coupon->id,
                        'code' => $coupon->code,
                        'description' => $coupon->description,
                        'banner_image_url' => $coupon->banner_image_url,
                        'discount_type' => $coupon->discount_type,
                        'discount_amount' => $coupon->discount_amount,
                        'discount_percentage' => $coupon->discount_percentage,
                        'discount_display' => $coupon->discount_display,
                        'expires_at' => $coupon->expires_at,
                        'usage_limit' => $coupon->usage_limit,
                        'used_count' => $coupon->used_count,
                        'product' => $primaryProduct ? [
                            'id' => $primaryProduct->id,
                            'name' => $primaryProduct->name,
                            'image' => $primaryProduct->image ? asset('storage/' . $primaryProduct->image) : null,
                            'category' => $primaryProduct->category ? [
                                'id' => $primaryProduct->category->id,
                                'name' => $primaryProduct->category->name,
                                'icon' => $primaryProduct->category->icon,
                                'color' => $primaryProduct->category->color,
                            ] : null,
                            'business' => ($primaryProduct && $primaryProduct->user) ? [
                                'id' => $primaryProduct->user->id,
                                'name' => $primaryProduct->user->business_name ?: $primaryProduct->user->name,
                                'city' => $primaryProduct->user->city,
                                'state' => $primaryProduct->user->state,
                            ] : null,
                        ] : null,
                        'type' => 'coupon'
                    ];
                });
            }

            // Search tags
            if ($type === 'all' || $type === 'tags') {
                $results['tags'] = Tag::where('name', 'LIKE', "%{$query}%")
                    ->limit($limit)
                    ->get()
                    ->map(function ($tag) {
                        return [
                            'id' => $tag->id,
                            'name' => $tag->name,
                            'color' => $tag->color,
                            'description' => $tag->description,
                            'type' => 'tag'
                        ];
                    });
            }

            $results['total'] = count($results['businesses']) + count($results['products']) + count($results['coupons']) + count($results['tags']);

            return response()->json([
                'status' => 'success',
                'data' => $results
            ]);
        } catch (\Throwable $e) {
            Log::error('Search error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            $payload = [
                'status' => 'error',
                'message' => 'Search failed',
            ];

            if (config('app.debug')) {
                $payload['error'] = $e->getMessage();
                $payload['line'] = $e->getLine();
                $payload['file'] = $e->getFile();
            }

            return response()->json($payload, 500);
        }
    }

    /**
     * Get search suggestions/autocomplete
     */
    public function suggestions(Request $request)
    {
        try {
            $query = $request->get('q', '');
            $limit = min($request->get('limit', 10), 20);

            if (empty($query) || strlen($query) < 2) {
                return response()->json([
                    'status' => 'success',
                    'data' => []
                ]);
            }

            $suggestions = [];

            $businessNames = User::where('role', 'business')
                ->where(function ($q) use ($query) {
                    $q->where('business_name', 'LIKE', "%{$query}%")
                        ->orWhere('name', 'LIKE', "%{$query}%");
                })
                ->limit($limit)
                ->get(['business_name', 'name'])
                ->map(function ($business) {
                    return $business->business_name ?: $business->name;
                })
                ->unique()
                ->values();

            foreach ($businessNames as $name) {
                $suggestions[] = [
                    'text' => $name,
                    'type' => 'business',
                    'icon' => 'store'
                ];
            }

            $productNames = Product::where('is_active', true)
                ->where(function ($q) use ($query) {
                    $q->where('name', 'LIKE', "%{$query}%")
                        ->orWhereHas('tags', function ($tq) use ($query) {
                            $tq->where('name', 'LIKE', "%{$query}%");
                        });
                })
                ->limit($limit)
                ->pluck('name')
                ->unique()
                ->values();

            foreach ($productNames as $name) {
                $suggestions[] = [
                    'text' => $name,
                    'type' => 'product',
                    'icon' => 'inventory'
                ];
            }

            $tagNames = Tag::where('name', 'LIKE', "%{$query}%")
                ->limit($limit)
                ->pluck('name')
                ->unique()
                ->values();

            foreach ($tagNames as $name) {
                $suggestions[] = [
                    'text' => $name,
                    'type' => 'tag',
                    'icon' => 'local_offer'
                ];
            }

            $couponCodes = Coupon::where('is_active', true)
                ->where('code', 'LIKE', "%{$query}%")
                ->limit($limit)
                ->pluck('code')
                ->unique()
                ->values();

            foreach ($couponCodes as $code) {
                $suggestions[] = [
                    'text' => $code,
                    'type' => 'coupon',
                    'icon' => 'local_offer'
                ];
            }

            $suggestions = collect($suggestions)
                ->unique('text')
                ->take($limit)
                ->values()
                ->toArray();

            return response()->json([
                'status' => 'success',
                'data' => $suggestions
            ]);
        } catch (\Throwable $e) {
            Log::error('Search suggestions error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $payload = [
                'status' => 'error',
                'message' => 'Suggestions failed',
            ];

            if (config('app.debug')) {
                $payload['error'] = $e->getMessage();
            }

            return response()->json($payload, 500);
        }
    }
}
