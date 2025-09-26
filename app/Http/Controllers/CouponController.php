<?php

namespace App\Http\Controllers;

use App\Models\Coupon;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CouponController extends Controller
{
    /**
     * Display a listing of coupons
     */
    public function index(Request $request)
    {
        $query = Coupon::with(['user', 'products'])
            ->byUser(Auth::id());

        // Apply filters
        if ($request->has('search') && $request->search) {
            $query->search($request->search);
        }

        if ($request->has('status') && $request->status) {
            switch ($request->status) {
                case 'active':
                    $query->active()->valid();
                    break;
                case 'inactive':
                    $query->where('is_active', false);
                    break;
                case 'expired':
                    $query->where('expires_at', '<', now());
                    break;
                case 'featured':
                    $query->featured();
                    break;
            }
        }

        $coupons = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json([
            'status' => 'success',
            'data' => $coupons,
        ]);
    }

    /**
     * Store a newly created coupon
     */
    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'code' => 'nullable|string|max:20|unique:coupons,code',
            'description' => 'nullable|string',
            'discount_amount' => 'nullable|numeric|min:0',
            'discount_percentage' => 'nullable|numeric|min:0|max:100',
            'discount_type' => 'required|in:fixed,percentage',
            'minimum_amount' => 'nullable|numeric|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'per_user_limit' => 'nullable|integer|min:1',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after:starts_at',
            'is_active' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer|exists:products,id',
            'terms_conditions' => 'nullable|array',
        ]);

        return DB::transaction(function () use ($request) {
            $coupon = Coupon::create([
                'user_id' => Auth::id(),
                'title' => $request->title,
                'code' => $request->code ?: strtoupper(Str::random(8)),
                'description' => $request->description,
                'discount_amount' => $request->discount_amount,
                'discount_percentage' => $request->discount_percentage,
                'discount_type' => $request->discount_type,
                'minimum_amount' => $request->minimum_amount,
                'usage_limit' => $request->usage_limit,
                'per_user_limit' => $request->per_user_limit ?? 1,
                'starts_at' => $request->starts_at,
                'expires_at' => $request->expires_at,
                'is_active' => $request->is_active ?? true,
                'is_featured' => $request->is_featured ?? false,
                'terms_conditions' => $request->terms_conditions,
            ]);

            // Attach products if provided
            if ($request->has('product_ids') && is_array($request->product_ids)) {
                $coupon->products()->attach($request->product_ids);
            }

            // Generate QR code
            $coupon->qr_code = $coupon->generateQRCode();
            $coupon->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Coupon created successfully',
                'data' => $coupon->load('products'),
            ], 201);
        });
    }

    /**
     * Display the specified coupon
     */
    public function show(Coupon $coupon)
    {
        // Ensure user can only view their own coupons
        if ($coupon->user_id !== Auth::id()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Coupon not found',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $coupon->load(['user', 'products']),
        ]);
    }

    /**
     * Update the specified coupon
     */
    public function update(Request $request, Coupon $coupon)
    {
        // Ensure user can only update their own coupons
        if ($coupon->user_id !== Auth::id()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Coupon not found',
            ], 404);
        }

        $request->validate([
            'title' => 'required|string|max:255',
            'code' => 'nullable|string|max:20|unique:coupons,code,' . $coupon->id,
            'description' => 'nullable|string',
            'discount_amount' => 'nullable|numeric|min:0',
            'discount_percentage' => 'nullable|numeric|min:0|max:100',
            'discount_type' => 'required|in:fixed,percentage',
            'minimum_amount' => 'nullable|numeric|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'per_user_limit' => 'nullable|integer|min:1',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after:starts_at',
            'is_active' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer|exists:products,id',
            'terms_conditions' => 'nullable|array',
        ]);

        return DB::transaction(function () use ($request, $coupon) {
            $coupon->update([
                'title' => $request->title,
                'code' => $request->code ?: $coupon->code,
                'description' => $request->description,
                'discount_amount' => $request->discount_amount,
                'discount_percentage' => $request->discount_percentage,
                'discount_type' => $request->discount_type,
                'minimum_amount' => $request->minimum_amount,
                'usage_limit' => $request->usage_limit,
                'per_user_limit' => $request->per_user_limit ?? 1,
                'starts_at' => $request->starts_at,
                'expires_at' => $request->expires_at,
                'is_active' => $request->is_active ?? true,
                'is_featured' => $request->is_featured ?? false,
                'terms_conditions' => $request->terms_conditions,
            ]);

            // Sync products
            if ($request->has('product_ids')) {
                $coupon->products()->sync($request->product_ids ?? []);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Coupon updated successfully',
                'data' => $coupon->load('products'),
            ]);
        });
    }

    /**
     * Remove the specified coupon
     */
    public function destroy(Coupon $coupon)
    {
        // Ensure user can only delete their own coupons
        if ($coupon->user_id !== Auth::id()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Coupon not found',
            ], 404);
        }

        return DB::transaction(function () use ($coupon) {
            // Delete QR code file if exists
            if ($coupon->qr_code) {
                Storage::disk('public')->delete($coupon->qr_code);
            }

            $coupon->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Coupon deleted successfully',
            ]);
        });
    }

    /**
     * Toggle featured status
     */
    public function toggleFeatured(Coupon $coupon)
    {
        // Ensure user can only modify their own coupons
        if ($coupon->user_id !== Auth::id()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Coupon not found',
            ], 404);
        }

        $coupon->update(['is_featured' => !$coupon->is_featured]);

        return response()->json([
            'status' => 'success',
            'message' => $coupon->is_featured ? 'Coupon added to featured' : 'Coupon removed from featured',
            'data' => $coupon,
        ]);
    }

    /**
     * Get products for coupon selection
     */
    public function getProducts()
    {
        $products = Product::active()
            ->byUser(Auth::id())
            ->with(['category', 'tags'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $products,
        ]);
    }

    /**
     * Validate coupon code (for redemption)
     */
    public function validate(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $coupon = Coupon::where('code', $request->code)
            ->active()
            ->valid()
            ->first();

        if (!$coupon) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid or expired coupon',
            ], 404);
        }

        if (!$coupon->canBeUsed()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Coupon usage limit reached',
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'data' => $coupon->load('products'),
        ]);
    }

    /**
     * Redeem coupon
     */
    public function redeem(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $coupon = Coupon::where('code', $request->code)
            ->active()
            ->valid()
            ->first();

        if (!$coupon) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid or expired coupon',
            ], 404);
        }

        if (!$coupon->canBeUsed()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Coupon usage limit reached',
            ], 400);
        }

        $coupon->incrementUsage();

        return response()->json([
            'status' => 'success',
            'message' => 'Coupon redeemed successfully',
            'data' => $coupon->load('products'),
        ]);
    }
}
