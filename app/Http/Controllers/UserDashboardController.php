<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Product;
use App\Models\Coupon;
use App\Models\ClaimedCoupon;
use App\Services\NotificationService;
use Carbon\Carbon;

class UserDashboardController extends Controller
{
    /**
     * Get user's own activity
     */
    public function userActivity(Request $request)
    {
        try {
            $user = $request->user();
            $limit = $request->get('limit', 10);

            $activities = ActivityLog::where('user_id', $user->id)
                ->latest()
                ->take($limit)
                ->get()
                ->map(function ($activity) {
                    return [
                        'id' => $activity->id,
                        'title' => $activity->title,
                        'description' => $activity->description,
                        'icon' => $activity->icon,
                        'timestamp' => $activity->created_at,
                        'user' => [
                            'id' => $activity->user->id,
                            'name' => $activity->user->name,
                            'email' => $activity->user->email,
                        ],
                    ];
                });

            return response()->json([
                'status' => 'success',
                'data' => $activities,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to load user activity',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get nearby businesses based on user's location
     */
    public function nearbyBusinesses(Request $request)
    {
        try {
            $latitude = $request->get('latitude');
            $longitude = $request->get('longitude');
            $radius = $request->get('radius', 10); // Default 10 miles
            $limit = $request->get('limit', 12); // Default 12 businesses
            $page = $request->get('page', 1);
            $search = $request->get('search');
            $category = $request->get('category'); // Filter by category

            // Validate required parameters
            if (!$latitude || !$longitude) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Latitude and longitude are required',
                ], 400);
            }

            // Validate latitude and longitude ranges
            if ($latitude < -90 || $latitude > 90) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid latitude value',
                ], 400);
            }

            if ($longitude < -180 || $longitude > 180) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid longitude value',
                ], 400);
            }

            // Calculate offset for pagination
            $offset = ($page - 1) * $limit;

            // Build the query
            $query = User::nearbyBusinesses($latitude, $longitude, $radius, $limit + $offset);

            // Add search functionality if provided
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('business_name', 'like', "%{$search}%")
                        ->orWhere('business_description', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%")
                        ->orWhere('state', 'like', "%{$search}%");
                });
            }

            // Add category filtering if provided
            if ($category) {
                $query->whereHas('products', function ($q) use ($category) {
                    $q->where('category_id', $category)->where('is_active', true);
                });
            }

            // Get total count for pagination
            $totalQuery = User::nearbyBusinesses($latitude, $longitude, $radius, 1000); // Get a large number to count all
            if ($search) {
                $totalQuery->where(function ($q) use ($search) {
                    $q->where('business_name', 'like', "%{$search}%")
                        ->orWhere('business_description', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%")
                        ->orWhere('state', 'like', "%{$search}%");
                });
            }
            if ($category) {
                $totalQuery->whereHas('products', function ($q) use ($category) {
                    $q->where('category_id', $category)->where('is_active', true);
                });
            }
            $totalCount = $totalQuery->count();

            // Apply pagination
            $businesses = $query->skip($offset)->take($limit)->get();

            // Format the response
            $formattedBusinesses = $businesses->map(function ($business) {
                return [
                    'id' => $business->id,
                    'name' => $business->name,
                    'business_name' => $business->business_name,
                    'business_description' => $business->business_description,
                    'email' => $business->email,
                    'phone' => $business->phone,
                    'address' => $business->address,
                    'city' => $business->city,
                    'state' => $business->state,
                    'zipcode' => $business->zipcode,
                    'country' => $business->country,
                    'latitude' => $business->latitude,
                    'longitude' => $business->longitude,
                    'profile_image_url' => $business->profile_image_url,
                    'distance' => round($business->distance, 2),
                    'created_at' => $business->created_at,
                    'updated_at' => $business->updated_at,
                ];
            });

            // Calculate pagination info
            $hasMore = ($offset + $limit) < $totalCount;
            $totalPages = ceil($totalCount / $limit);

            return response()->json([
                'status' => 'success',
                'data' => $formattedBusinesses,
                'pagination' => [
                    'current_page' => $page,
                    'total_pages' => $totalPages,
                    'total_count' => $totalCount,
                    'per_page' => $limit,
                    'has_more' => $hasMore,
                ],
                'search_params' => [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'radius' => $radius,
                    'search' => $search,
                    'category' => $category,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to load nearby businesses',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get products for a specific business
     */
    public function businessProducts(Request $request, $businessId)
    {
        try {
            $user = $request->user();

            // Validate business exists and is a business user
            $business = User::where('id', $businessId)
                ->where('role', 'Business')
                ->first();

            if (!$business) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Business not found',
                ], 404);
            }

            // Get products with relationships
            $products = Product::with(['category', 'coupons'])
                ->where('user_id', $businessId)
                ->where('is_active', true)
                ->ordered()
                ->get();

            // Filter coupons to only show those that are available or claimed by current user
            $products = $products->map(function ($product) use ($user) {
                $product->coupons = $product->coupons->filter(function ($coupon) use ($user) {
                    // Check if current user has claimed this coupon
                    $userClaimedCoupon = ClaimedCoupon::where('user_id', $user->id)
                        ->where('coupon_id', $coupon->id)
                        ->whereIn('status', ['claimed', 'used'])
                        ->exists();

                    // Show coupon if:
                    // 1. User has claimed it, OR
                    // 2. Coupon is still available (not claimed by anyone or within limits)
                    return $userClaimedCoupon || $coupon->canBeUsed();
                });

                return $product;
            });

            // Format products with coupon information
            $formattedProducts = $products->map(function ($product) use ($user) {
                // Check if product is favorited by current user
                $isFavorite = $user->favoriteProducts()->where('product_id', $product->id)->exists();

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'description' => $product->description,
                    'image' => $product->image ? asset('storage/' . $product->image) : null,
                    'is_featured' => $product->is_featured,
                    'is_favorite' => $isFavorite,
                    'category' => $product->category ? [
                        'id' => $product->category->id,
                        'name' => $product->category->name,
                        'icon' => $product->category->icon,
                        'color' => $product->category->color,
                    ] : null,
                    'coupons' => $product->coupons->map(function ($coupon) use ($user) {
                        // Check if current user has already claimed this coupon
                        $userClaimedCoupon = ClaimedCoupon::where('user_id', $user->id)
                            ->where('coupon_id', $coupon->id)
                            ->whereIn('status', ['claimed', 'used'])
                            ->first();

                        $isClaimedByUser = $userClaimedCoupon ? true : false;
                        $claimedAt = $userClaimedCoupon ? $userClaimedCoupon->created_at : null;

                        return [
                            'id' => $coupon->id,
                            'code' => $coupon->code,
                            'title' => $coupon->title,
                            'description' => $coupon->description,
                            'banner_image_url' => $coupon->banner_image_url,
                            'discount_type' => $coupon->discount_type,
                            'discount_amount' => $coupon->discount_amount,
                            'discount_percentage' => $coupon->discount_percentage,
                            'discount_display' => $coupon->formatted_discount,
                            'minimum_amount' => $coupon->minimum_amount,
                            'usage_limit' => $coupon->usage_limit,
                            'used_count' => $coupon->used_count,
                            'per_user_limit' => $coupon->per_user_limit,
                            'starts_at' => $coupon->starts_at,
                            'expires_at' => $coupon->expires_at,
                            'is_active' => $coupon->is_active,
                            'is_valid' => $coupon->is_valid,
                            'can_be_used' => $coupon->canBeUsed(),
                            'is_claimed_by_user' => $isClaimedByUser,
                            'claimed_at' => $claimedAt,
                        ];
                    }),
                    'has_coupons' => $product->coupons->count() > 0,
                    'created_at' => $product->created_at,
                    'updated_at' => $product->updated_at,
                ];
            });

            // Separate products with and without coupons
            $productsWithCoupons = $formattedProducts->where('has_coupons', true)->values();
            $allProducts = $formattedProducts->values();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'business' => [
                        'id' => $business->id,
                        'name' => $business->name,
                        'business_name' => $business->business_name,
                        'business_description' => $business->business_description,
                        'email' => $business->email,
                        'phone' => $business->phone,
                        'address' => $business->address,
                        'city' => $business->city,
                        'state' => $business->state,
                        'zipcode' => $business->zipcode,
                        'country' => $business->country,
                        'latitude' => $business->latitude,
                        'longitude' => $business->longitude,
                        'profile_image_url' => $business->profile_image_url,
                    ],
                    'products' => [
                        'all' => $allProducts,
                        'with_coupons' => $productsWithCoupons,
                    ],
                    'stats' => [
                        'total_products' => $allProducts->count(),
                        'products_with_coupons' => $productsWithCoupons->count(),
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to load business products',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Claim a coupon
     */
    public function claimCoupon(Request $request)
    {
        try {
            $user = $request->user();
            $couponId = $request->get('coupon_id');
            $productId = $request->get('product_id');

            // Validate coupon exists and is claimable
            $coupon = Coupon::with(['user', 'products'])
                ->where('id', $couponId)
                ->where('is_active', true)
                ->first();

            if (!$coupon) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Coupon not found or inactive',
                ], 404);
            }

            // Check if coupon is valid
            if (!$coupon->canBeUsed()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Coupon is no longer available',
                ], 400);
            }

            // Check if user has already claimed this coupon
            $existingClaim = ClaimedCoupon::where('user_id', $user->id)
                ->where('coupon_id', $couponId)
                ->whereIn('status', ['claimed', 'used'])
                ->first();

            if ($existingClaim) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You have already claimed this coupon',
                ], 400);
            }

            // Check per-user limit
            $userClaimCount = ClaimedCoupon::where('user_id', $user->id)
                ->where('coupon_id', $couponId)
                ->count();

            if ($coupon->per_user_limit && $userClaimCount >= $coupon->per_user_limit) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You have reached the limit for this coupon',
                ], 400);
            }

            // Create claimed coupon record
            $claimedCoupon = ClaimedCoupon::create([
                'user_id' => $user->id,
                'coupon_id' => $coupon->id,
                'business_id' => $coupon->user_id,
                'product_id' => $productId,
                'coupon_code' => $coupon->code,
                'coupon_title' => $coupon->title,
                'coupon_description' => $coupon->description,
                'discount_type' => $coupon->discount_type,
                'discount_amount' => $coupon->discount_amount,
                'discount_percentage' => $coupon->discount_percentage,
                'minimum_amount' => $coupon->minimum_amount,
                'expires_at' => $coupon->expires_at,
                'status' => 'claimed',
            ]);

            // Increment coupon usage count
            $coupon->incrementUsage();

            // Send notification to business owner about coupon claim
            $this->notifyBusinessOfCouponClaim($claimedCoupon);

            return response()->json([
                'status' => 'success',
                'message' => 'Coupon claimed successfully!',
                'data' => [
                    'claimed_coupon' => $claimedCoupon->load(['business', 'product', 'coupon']),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to claim coupon',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get user's claimed coupons
     */
    public function getClaimedCoupons(Request $request)
    {
        try {
            $user = $request->user();
            $status = $request->get('status', 'all'); // all, claimed, used, expired
            $page = $request->get('page', 1);
            $limit = $request->get('limit', 20);

            $query = ClaimedCoupon::with(['business', 'product', 'coupon'])
                ->where('user_id', $user->id);

            // Filter by status
            if ($status !== 'all') {
                $query->where('status', $status);
            }

            $claimedCoupons = $query->orderBy('created_at', 'desc')
                ->paginate($limit, ['*'], 'page', $page);

            // Format the response
            $formattedCoupons = $claimedCoupons->map(function ($claimedCoupon) {
                return [
                    'id' => $claimedCoupon->id,
                    'user_id' => $claimedCoupon->user_id,
                    'coupon_code' => $claimedCoupon->coupon_code,
                    'coupon_title' => $claimedCoupon->coupon_title,
                    'coupon_description' => $claimedCoupon->coupon_description,
                    'discount_type' => $claimedCoupon->discount_type,
                    'discount_amount' => $claimedCoupon->discount_amount,
                    'discount_percentage' => $claimedCoupon->discount_percentage,
                    'discount_display' => $claimedCoupon->discount_display,
                    'minimum_amount' => $claimedCoupon->minimum_amount,
                    'expires_at' => $claimedCoupon->expires_at,
                    'status' => $claimedCoupon->status,
                    'used_at' => $claimedCoupon->used_at,
                    'usage_notes' => $claimedCoupon->usage_notes,
                    'is_expired' => $claimedCoupon->is_expired,
                    'is_usable' => $claimedCoupon->is_usable,
                    'banner_image_url' => $claimedCoupon->coupon ? $claimedCoupon->coupon->banner_image_url : null,
                    'business' => [
                        'id' => $claimedCoupon->business->id,
                        'name' => $claimedCoupon->business->business_name ?? $claimedCoupon->business->name,
                        'profile_image_url' => $claimedCoupon->business->profile_image_url,
                    ],
                    'product' => $claimedCoupon->product ? [
                        'id' => $claimedCoupon->product->id,
                        'name' => $claimedCoupon->product->name,
                        'image' => $claimedCoupon->product->image ? asset('storage/' . $claimedCoupon->product->image) : null,
                    ] : null,
                    'created_at' => $claimedCoupon->created_at,
                    'updated_at' => $claimedCoupon->updated_at,
                ];
            });

            return response()->json([
                'status' => 'success',
                'data' => $formattedCoupons,
                'pagination' => [
                    'current_page' => $claimedCoupons->currentPage(),
                    'total_pages' => $claimedCoupons->lastPage(),
                    'total_count' => $claimedCoupons->total(),
                    'per_page' => $claimedCoupons->perPage(),
                    'has_more' => $claimedCoupons->hasMorePages(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to load claimed coupons',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Validate QR code scan
     */
    public function validateScan(Request $request)
    {
        try {
            $user = $request->user();
            $claimedCouponId = $request->get('claimedCouponId');
            $couponCode = $request->get('couponCode');
            $userId = $request->get('userId');
            $businessId = $request->get('businessId');
            $timestamp = $request->get('timestamp');

            // Validate the claimed coupon
            $claimedCoupon = ClaimedCoupon::with(['user', 'business', 'product', 'coupon'])
                ->where('id', $claimedCouponId)
                ->where('coupon_code', $couponCode)
                ->where('business_id', $businessId)
                ->where('user_id', $userId)
                ->where('status', 'claimed')
                ->first();

            if (!$claimedCoupon) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid coupon or coupon not found',
                ], 404);
            }

            // Check if coupon is still valid (not expired)
            if ($claimedCoupon->is_expired) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Coupon has expired',
                ], 400);
            }

            // Check if coupon is already used
            if ($claimedCoupon->status === 'used') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Coupon has already been used',
                ], 400);
            }

            // Verify the business user is scanning their own coupon
            if ($user->id !== $businessId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You can only scan coupons for your own business',
                ], 403);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Coupon is valid and ready to use',
                'coupon' => [
                    'claimedCouponId' => $claimedCoupon->id,
                    'couponCode' => $claimedCoupon->coupon_code,
                    'couponTitle' => $claimedCoupon->coupon_title,
                    'discountDisplay' => $claimedCoupon->discount_display,
                    'customerName' => $claimedCoupon->user->name,
                    'productName' => $claimedCoupon->product ? $claimedCoupon->product->name : 'General Store Discount',
                    'minimumAmount' => $claimedCoupon->minimum_amount,
                    'expiresAt' => $claimedCoupon->expires_at,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to validate coupon',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Validate QR code with coupon code + user ID (direct validation)
     */
    public function validateQRDirect(Request $request)
    {
        try {
            $user = $request->user();
            $couponCode = $request->get('couponCode');
            $userId = $request->get('userId');
            $businessId = $request->get('businessId');
            $timestamp = $request->get('timestamp');

            // Debug logging
            \Log::info('QR Direct validation request', [
                'user_id' => $user ? $user->id : 'not authenticated',
                'user_business_name' => $user ? $user->business_name : 'N/A',
                'coupon_code' => $couponCode,
                'qr_user_id' => $userId,
                'qr_business_id' => $businessId,
                'timestamp' => $timestamp,
            ]);

            // Find the claimed coupon by coupon code and user ID
            $claimedCoupon = ClaimedCoupon::with(['user', 'business', 'product', 'coupon'])
                ->where('coupon_code', $couponCode)
                ->where('user_id', $userId)
                ->where('business_id', $businessId)
                ->where('status', 'claimed')
                ->first();

            if (!$claimedCoupon) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Coupon not found for this user',
                ], 404);
            }

            // Check if coupon is still valid (not expired)
            if ($claimedCoupon->is_expired) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Coupon has expired',
                ], 400);
            }

            // Check if coupon is already used
            if ($claimedCoupon->status === 'used') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Coupon has already been used',
                ], 400);
            }

            // Verify the business user is scanning their own coupon
            if ($user->id !== $businessId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You can only scan coupons for your own business',
                ], 403);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Coupon is valid and ready to use',
                'coupon' => [
                    'claimedCouponId' => $claimedCoupon->id,
                    'couponCode' => $claimedCoupon->coupon_code,
                    'couponTitle' => $claimedCoupon->coupon_title,
                    'discountDisplay' => $claimedCoupon->discount_display,
                    'customerName' => $claimedCoupon->user->name,
                    'customerEmail' => $claimedCoupon->user->email,
                    'productName' => $claimedCoupon->product ? $claimedCoupon->product->name : 'General Store Discount',
                    'businessName' => $claimedCoupon->business->business_name ?? $claimedCoupon->business->name,
                    'minimumAmount' => $claimedCoupon->minimum_amount,
                    'expiresAt' => $claimedCoupon->expires_at,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to validate coupon',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function validateManual(Request $request)
    {
        try {
            $user = $request->user();
            $couponCode = $request->get('couponCode');

            // Find all claimed coupons by code for this business
            $claimedCoupons = ClaimedCoupon::with(['user', 'business', 'product', 'coupon'])
                ->where('coupon_code', $couponCode)
                ->where('business_id', $user->id)
                ->where('status', 'claimed')
                ->get();

            if ($claimedCoupons->isEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Coupon not found or not claimed for your business. Please ensure you are logged in as the correct business and the coupon code is valid.',
                ], 404);
            }

            // If multiple customers have claimed the same coupon code
            if ($claimedCoupons->count() > 1) {
                $customers = $claimedCoupons->map(function ($coupon) {
                    return [
                        'claimedCouponId' => $coupon->id,
                        'customerName' => $coupon->user->name,
                        'customerEmail' => $coupon->user->email,
                        'claimedAt' => $coupon->created_at,
                        'isExpired' => $coupon->is_expired,
                        'isUsed' => $coupon->status === 'used',
                    ];
                });

                return response()->json([
                    'status' => 'multiple',
                    'message' => 'Multiple customers have claimed this coupon code. Please select which customer to validate.',
                    'customers' => $customers,
                ]);
            }

            // Single customer case
            $claimedCoupon = $claimedCoupons->first();

            // Check if coupon is still valid (not expired)
            if ($claimedCoupon->is_expired) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Coupon has expired',
                ], 400);
            }

            // Check if coupon is already used
            if ($claimedCoupon->status === 'used') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Coupon has already been used',
                ], 400);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Coupon is valid and ready to use',
                'coupon' => [
                    'claimedCouponId' => $claimedCoupon->id,
                    'couponCode' => $claimedCoupon->coupon_code,
                    'couponTitle' => $claimedCoupon->coupon_title,
                    'discountDisplay' => $claimedCoupon->discount_display,
                    'customerName' => $claimedCoupon->user->name,
                    'productName' => $claimedCoupon->product ? $claimedCoupon->product->name : 'General Store Discount',
                    'minimumAmount' => $claimedCoupon->minimum_amount,
                    'expiresAt' => $claimedCoupon->expires_at,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to validate coupon',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Validate specific customer's coupon
     */
    public function validateSpecificCustomer(Request $request)
    {
        try {
            $user = $request->user();
            $claimedCouponId = $request->get('claimedCouponId');

            // Find the specific claimed coupon
            $claimedCoupon = ClaimedCoupon::with(['user', 'business', 'product', 'coupon'])
                ->where('id', $claimedCouponId)
                ->where('business_id', $user->id)
                ->where('status', 'claimed')
                ->first();

            if (!$claimedCoupon) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Coupon not found or already used',
                ], 404);
            }

            // Check if coupon is still valid (not expired)
            if ($claimedCoupon->is_expired) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Coupon has expired',
                ], 400);
            }

            // Check if coupon is already used
            if ($claimedCoupon->status === 'used') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Coupon has already been used',
                ], 400);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Coupon is valid and ready to use',
                'coupon' => [
                    'claimedCouponId' => $claimedCoupon->id,
                    'couponCode' => $claimedCoupon->coupon_code,
                    'couponTitle' => $claimedCoupon->coupon_title,
                    'discountDisplay' => $claimedCoupon->discount_display,
                    'customerName' => $claimedCoupon->user->name,
                    'productName' => $claimedCoupon->product ? $claimedCoupon->product->name : 'General Store Discount',
                    'minimumAmount' => $claimedCoupon->minimum_amount,
                    'expiresAt' => $claimedCoupon->expires_at,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to validate coupon',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Search customers who claimed a specific coupon code
     */
    public function searchCustomers(Request $request)
    {
        try {
            $user = $request->user();
            $couponCode = $request->get('couponCode');
            // Accept both 'query' and 'searchQuery' for flexibility
            $searchQuery = $request->get('query') ?? $request->get('searchQuery');

            if (!$couponCode || !$searchQuery) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Coupon code and search query are required',
                ], 400);
            }

            // Find claimed coupons by code for this business with search
            $claimedCoupons = ClaimedCoupon::with(['user', 'business', 'product', 'coupon'])
                ->where('coupon_code', $couponCode)
                ->where('business_id', $user->id)
                ->where('status', 'claimed')
                ->whereHas('user', function ($query) use ($searchQuery) {
                    $query->where('name', 'LIKE', "%{$searchQuery}%")
                        ->orWhere('email', 'LIKE', "%{$searchQuery}%");
                })
                ->get();

            $customers = $claimedCoupons->map(function ($coupon) {
                return [
                    'claimedCouponId' => $coupon->id,
                    'customerName' => $coupon->user->name,
                    'customerEmail' => $coupon->user->email,
                    'claimedAt' => $coupon->created_at,
                    'isExpired' => $coupon->is_expired,
                    'isUsed' => $coupon->status === 'used',
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Customers found',
                'customers' => $customers,
                'total' => $customers->count(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to search customers',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mark coupon as used
     */
    public function markAsUsed(Request $request)
    {
        try {
            $user = $request->user();
            $claimedCouponId = $request->get('claimedCouponId');

            // Find the claimed coupon
            $claimedCoupon = ClaimedCoupon::where('id', $claimedCouponId)
                ->where('business_id', $user->id)
                ->where('status', 'claimed')
                ->first();

            if (!$claimedCoupon) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Coupon not found or already used',
                ], 404);
            }

            // Mark as used
            $claimedCoupon->markAsUsed('Scanned and validated by business');

            // Emit WebSocket event to notify user about coupon status change
            event(new \App\Events\CouponStatusChanged($claimedCoupon->fresh()));

            return response()->json([
                'status' => 'success',
                'message' => 'Coupon marked as used successfully',
                'data' => [
                    'claimedCoupon' => $claimedCoupon->fresh(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to mark coupon as used',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Toggle product favorite status for the authenticated user.
     */
    public function toggleProductFavorite(Request $request)
    {
        try {
            $request->validate([
                'product_id' => 'required|exists:products,id',
                'action' => 'required|in:add,remove',
            ]);

            $user = $request->user();
            $productId = $request->product_id;
            $action = $request->action;

            // Check if product exists
            $product = Product::findOrFail($productId);

            if ($action === 'add') {
                // Check if already favorited
                if ($user->favoriteProducts()->where('product_id', $productId)->exists()) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Product is already in favorites',
                    ], 400);
                }

                // Add to favorites
                $user->favoriteProducts()->attach($productId);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Product added to favorites',
                    'data' => [
                        'is_favorite' => true,
                    ],
                ]);
            } else {
                // Remove from favorites
                $user->favoriteProducts()->detach($productId);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Product removed from favorites',
                    'data' => [
                        'is_favorite' => false,
                    ],
                ]);
            }

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update favorite status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get user's favorite products.
     */
    public function getFavoriteProducts(Request $request)
    {
        try {
            $user = $request->user();
            $page = $request->get('page', 1);
            $limit = $request->get('limit', 12);

            $favoriteProducts = $user->favoriteProducts()
                ->with(['user', 'category', 'coupons'])
                ->paginate($limit, ['*'], 'page', $page);

            // Format the products data
            $formattedProducts = $favoriteProducts->getCollection()->map(function ($product) {
                // Debug logging
                \Log::info('Processing product:', [
                    'id' => $product->id,
                    'name' => $product->name,
                    'image' => $product->image,
                    'category' => $product->category ? [
                        'id' => $product->category->id,
                        'name' => $product->category->name,
                        'icon' => $product->category->icon,
                        'color' => $product->category->color,
                        'slug' => $product->category->slug,
                    ] : null,
                ]);
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'description' => $product->description,
                    'image' => $product->image ? asset('storage/' . $product->image) : null,
                    'image_debug' => [
                        'raw_image' => $product->image,
                        'full_url' => $product->image ? asset('storage/' . $product->image) : null,
                    ],
                    'is_featured' => $product->is_featured,
                    'is_favorite' => true, // Always true since these are favorites
                    'category' => $product->category ? [
                        'id' => $product->category->id,
                        'name' => $product->category->name,
                        'icon' => $product->category->icon,
                        'color' => $product->category->color,
                        'slug' => $product->category->slug,
                    ] : null,
                    'user' => $product->user ? [
                        'id' => $product->user->id,
                        'name' => $product->user->name,
                        'business_name' => $product->user->business_name,
                        'profile_image_url' => $product->user->profile_image_url,
                    ] : null,
                    'created_at' => $product->created_at,
                    'updated_at' => $product->updated_at,
                ];
            });

            return response()->json([
                'status' => 'success',
                'data' => $formattedProducts->values()->toArray(),
                'pagination' => [
                    'current_page' => $favoriteProducts->currentPage(),
                    'last_page' => $favoriteProducts->lastPage(),
                    'per_page' => $favoriteProducts->perPage(),
                    'total' => $favoriteProducts->total(),
                    'has_more' => $favoriteProducts->hasMorePages(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to load favorite products',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send notification to business owner when a coupon is claimed
     */
    private function notifyBusinessOfCouponClaim(ClaimedCoupon $claimedCoupon): void
    {
        try {
            $business = $claimedCoupon->business;
            $customer = $claimedCoupon->user;
            $product = $claimedCoupon->product;

            $notificationService = app(NotificationService::class);

            $title = "New Coupon Claimed! 🎉";
            $message = "Customer {$customer->name} has claimed your coupon '{$claimedCoupon->coupon_code}'" .
                ($product ? " for product '{$product->name}'" : "") .
                ". Discount: {$claimedCoupon->discount_display}";

            $notificationService->send(
                title: $title,
                message: $message,
                type: 'success',
                userIds: [$business->id], // Send to business owner
                data: [
                    'coupon_id' => $claimedCoupon->id,
                    'coupon_code' => $claimedCoupon->coupon_code,
                    'customer_name' => $customer->name,
                    'customer_email' => $customer->email,
                    'product_name' => $product ? $product->name : null,
                    'discount_display' => $claimedCoupon->discount_display,
                    'business_id' => $business->id,
                    'claimed_at' => $claimedCoupon->created_at->toISOString(),
                ],
                channel: 'business',
                urgent: false
            );

            \Log::info('Coupon claim notification sent to business', [
                'business_id' => $business->id,
                'business_name' => $business->name,
                'coupon_code' => $claimedCoupon->coupon_code,
                'customer_name' => $customer->name,
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to send coupon claim notification to business', [
                'business_id' => $claimedCoupon->business_id,
                'coupon_id' => $claimedCoupon->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get user's favorite businesses (businesses that have favorited products).
     */
    public function getFavoriteBusinesses(Request $request)
    {
        try {
            $user = $request->user();
            $page = $request->get('page', 1);
            $limit = $request->get('limit', 12);

            // Get businesses that have products favorited by the user
            $favoriteBusinesses = User::whereHas('products', function ($query) use ($user) {
                $query->whereHas('favoritedBy', function ($subQuery) use ($user) {
                    $subQuery->where('user_id', $user->id);
                });
            })
                ->with([
                    'products' => function ($query) use ($user) {
                        $query->whereHas('favoritedBy', function ($subQuery) use ($user) {
                            $subQuery->where('user_id', $user->id);
                        });
                    }
                ])
                ->paginate($limit, ['*'], 'page', $page);

            // Format the businesses data
            $formattedBusinesses = $favoriteBusinesses->getCollection()->map(function ($business) use ($user) {
                $favoriteProductsCount = $business->products->count();

                return [
                    'id' => $business->id,
                    'name' => $business->name,
                    'business_name' => $business->business_name,
                    'business_description' => $business->business_description,
                    'email' => $business->email,
                    'phone' => $business->phone,
                    'address' => $business->address,
                    'city' => $business->city,
                    'state' => $business->state,
                    'zipcode' => $business->zipcode,
                    'country' => $business->country,
                    'latitude' => $business->latitude,
                    'longitude' => $business->longitude,
                    'profile_image_url' => $business->profile_image_url,
                    'favorite_products_count' => $favoriteProductsCount,
                    'created_at' => $business->created_at,
                    'updated_at' => $business->updated_at,
                ];
            });

            return response()->json([
                'status' => 'success',
                'data' => $formattedBusinesses->values()->toArray(),
                'pagination' => [
                    'current_page' => $favoriteBusinesses->currentPage(),
                    'last_page' => $favoriteBusinesses->lastPage(),
                    'per_page' => $favoriteBusinesses->perPage(),
                    'total' => $favoriteBusinesses->total(),
                    'has_more' => $favoriteBusinesses->hasMorePages(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get favorite businesses',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Toggle business favorite status (remove business from favorites).
     */
    public function toggleBusinessFavorite(Request $request)
    {
        try {
            $request->validate([
                'business_id' => 'required|exists:users,id',
                'action' => 'required|in:add,remove',
            ]);

            $user = $request->user();
            $businessId = $request->business_id;
            $action = $request->action;

            // Check if business exists
            $business = User::findOrFail($businessId);

            if ($action === 'remove') {
                // Remove all products from this business from favorites
                $business->products()->each(function ($product) use ($user) {
                    $user->favoriteProducts()->detach($product->id);
                });

                return response()->json([
                    'status' => 'success',
                    'message' => 'Business removed from favorites',
                    'data' => [
                        'is_favorite' => false,
                    ],
                ]);
            } else {
                // For 'add' action, we don't add the business itself to favorites
                // Instead, we would need to add specific products
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot add business to favorites directly. Please favorite specific products.',
                ], 400);
            }

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update business favorite status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
