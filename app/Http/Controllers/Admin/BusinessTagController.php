<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessTag;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class BusinessTagController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // Check if table exists before querying
        try {
            if (!Schema::hasTable('business_tags')) {
                return response()->json([
                    'status' => 'success',
                    'data' => [
                        'data' => [],
                        'current_page' => 1,
                        'per_page' => 15,
                        'total' => 0,
                        'last_page' => 1,
                    ],
                ]);
            }

            $query = BusinessTag::query();

            // Search functionality
            if ($request->has('search') && $request->search) {
                $query->where(function ($q) use ($request) {
                    $q->where('name', 'like', '%' . $request->search . '%')
                        ->orWhere('description', 'like', '%' . $request->search . '%');
                });
            }

            // Filter by status
            if ($request->has('status') && $request->status !== '') {
                $query->where('is_active', $request->status === 'active');
            }

            $tags = $query->ordered()->paginate(15);

            return response()->json([
                'status' => 'success',
                'data' => $tags,
            ]);
        } catch (\Exception $e) {
            // If table doesn't exist or query fails, return empty result
            Log::warning('Business tags query failed (table may not exist): ' . $e->getMessage());
            return response()->json([
                'status' => 'success',
                'data' => [
                    'data' => [],
                    'current_page' => 1,
                    'per_page' => 15,
                    'total' => 0,
                    'last_page' => 1,
                ],
            ]);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // Check if table exists
        if (!Schema::hasTable('business_tags')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Business tags table does not exist. Please run migrations first.',
            ], 500);
        }

        $request->validate([
            'name' => 'required|string|max:255|unique:business_tags,name',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:255',
            'color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        $tag = BusinessTag::create([
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'description' => $request->description,
            'icon' => $request->icon,
            'color' => $request->color,
            'is_active' => $request->boolean('is_active', true),
            'sort_order' => $request->sort_order ?? 0,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Business tag created successfully',
            'data' => $tag,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(BusinessTag $businessTag)
    {
        // Check if table exists
        if (!Schema::hasTable('business_tags')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Business tags table does not exist. Please run migrations first.',
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'data' => $businessTag,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, BusinessTag $businessTag)
    {
        // Check if table exists
        if (!Schema::hasTable('business_tags')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Business tags table does not exist. Please run migrations first.',
            ], 500);
        }

        $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('business_tags', 'name')->ignore($businessTag->id),
            ],
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:255',
            'color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        $businessTag->update([
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'description' => $request->description,
            'icon' => $request->icon,
            'color' => $request->color,
            'is_active' => $request->boolean('is_active', true),
            'sort_order' => $request->sort_order ?? 0,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Business tag updated successfully',
            'data' => $businessTag,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(BusinessTag $businessTag)
    {
        // Check if table exists
        if (!Schema::hasTable('business_tags')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Business tags table does not exist. Please run migrations first.',
            ], 500);
        }

        // Check if tag is being used by businesses
        if ($businessTag->businesses()->count() > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot delete business tag. It is currently assigned to one or more businesses.',
            ], 422);
        }

        $businessTag->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Business tag deleted successfully',
        ]);
    }

    /**
     * Toggle tag status
     */
    public function toggleStatus(BusinessTag $businessTag)
    {
        // Check if table exists
        if (!Schema::hasTable('business_tags')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Business tags table does not exist. Please run migrations first.',
            ], 500);
        }

        $businessTag->update(['is_active' => !$businessTag->is_active]);

        return response()->json([
            'status' => 'success',
            'message' => 'Business tag status updated successfully',
            'data' => $businessTag,
        ]);
    }

    /**
     * Get all active business tags (for dropdowns, etc.)
     */
    public function active()
    {
        // Check if table exists
        if (!Schema::hasTable('business_tags')) {
            return response()->json([
                'status' => 'success',
                'data' => [],
            ]);
        }

        $tags = BusinessTag::active()->ordered()->get();

        return response()->json([
            'status' => 'success',
            'data' => $tags,
        ]);
    }

    /**
     * Get active business tags for public use
     */
    public function public()
    {
        // Check if table exists
        if (!Schema::hasTable('business_tags')) {
            return response()->json([
                'status' => 'success',
                'data' => [],
            ]);
        }

        $tags = BusinessTag::active()->ordered()->get();

        return response()->json([
            'status' => 'success',
            'data' => $tags,
        ]);
    }
}

