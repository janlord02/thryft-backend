<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Category;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Product::with(['category', 'user', 'tags', 'coupons'])
            ->byUser(Auth::id());

        // Search functionality
        if ($request->has('search') && $request->search) {
            $query->search($request->search);
        }

        // Filter by category
        if ($request->has('category_id') && $request->category_id) {
            $query->byCategory($request->category_id);
        }

        // Filter by status
        if ($request->has('status') && $request->status !== '') {
            $query->where('is_active', $request->status === 'active');
        }

        // Filter by featured
        if ($request->has('featured') && $request->featured !== '') {
            $query->where('is_featured', $request->featured === 'true');
        }

        $products = $query->ordered()->paginate(15);

        return response()->json([
            'status' => 'success',
            'data' => $products,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'nullable|in:0,1,true,false',
            'is_featured' => 'nullable|in:0,1,true,false',
            'category_id' => 'nullable|exists:categories,id',
            'tags' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        return DB::transaction(function () use ($request) {
            $data = [
                'user_id' => Auth::id(),
                'category_id' => $request->category_id,
                'name' => $request->name,
                'slug' => Str::slug($request->name),
                'description' => $request->description,
                'is_active' => $request->boolean('is_active', true),
                'is_featured' => $request->boolean('is_featured', false),
                'sort_order' => $request->sort_order ?? 0,
            ];

            $imagePath = null;
            // Handle image upload
            if ($request->hasFile('image')) {
                $imagePath = $request->file('image')->store('products', 'public');
                $data['image'] = $imagePath;
            }

            $product = Product::create($data);

            // Handle tags
            if ($request->has('tags')) {
                $tags = json_decode($request->tags, true);
                if (is_array($tags)) {
                    $tagIds = [];
                    foreach ($tags as $tagName) {
                        $tagName = trim($tagName);
                        if (!empty($tagName)) {
                            $tag = Tag::firstOrCreate(
                                ['name' => $tagName],
                                ['slug' => Str::slug($tagName)]
                            );
                            $tagIds[] = $tag->id;
                            $tag->incrementUsage();
                        }
                    }
                    $product->tags()->sync($tagIds);
                } else {
                    // If tags is not a valid array, sync empty array
                    $product->tags()->sync([]);
                }
            }

            $product->load(['category', 'user', 'tags']);

            return response()->json([
                'status' => 'success',
                'message' => 'Product created successfully',
                'data' => $product,
            ], 201);
        });
    }

    /**
     * Display the specified resource.
     */
    public function show(Product $product)
    {
        // Ensure user can only access their own products
        if ($product->user_id !== Auth::id()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Product not found',
            ], 404);
        }

        $product->load(['category', 'user', 'coupons']);

        return response()->json([
            'status' => 'success',
            'data' => $product,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Product $product)
    {
        // Ensure user can only update their own products
        if ($product->user_id !== Auth::id()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Product not found',
            ], 404);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'nullable|in:0,1,true,false',
            'is_featured' => 'nullable|in:0,1,true,false',
            'category_id' => 'nullable|integer|exists:categories,id',
            'tags' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        return DB::transaction(function () use ($request, $product) {
            $data = [
                'category_id' => $request->category_id,
                'name' => $request->name,
                'slug' => Str::slug($request->name),
                'description' => $request->description,
                'is_active' => $request->boolean('is_active', true),
                'is_featured' => $request->boolean('is_featured', false),
                'sort_order' => $request->sort_order ?? 0,
            ];

            // Handle image upload
            if ($request->hasFile('image')) {
                // Delete old image if exists
                if ($product->image) {
                    Storage::disk('public')->delete($product->image);
                }
                $imagePath = $request->file('image')->store('products', 'public');
                $data['image'] = $imagePath;
            }

            $product->update($data);

            // Handle tags
            if ($request->has('tags')) {
                $tags = json_decode($request->tags, true);
                if (is_array($tags)) {
                    $tagIds = [];
                    foreach ($tags as $tagName) {
                        $tagName = trim($tagName);
                        if (!empty($tagName)) {
                            $tag = Tag::firstOrCreate(
                                ['name' => $tagName],
                                ['slug' => Str::slug($tagName)]
                            );
                            $tagIds[] = $tag->id;
                            $tag->incrementUsage();
                        }
                    }
                    $product->tags()->sync($tagIds);
                } else {
                    // If tags is not a valid array, sync empty array
                    $product->tags()->sync([]);
                }
            }

            $product->load(['category', 'user', 'tags']);

            return response()->json([
                'status' => 'success',
                'message' => 'Product updated successfully',
                'data' => $product,
            ]);
        });
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Product $product)
    {
        // Ensure user can only delete their own products
        if ($product->user_id !== Auth::id()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Product not found',
            ], 404);
        }

        return DB::transaction(function () use ($product) {
            // Delete associated image if exists
            if ($product->image) {
                Storage::disk('public')->delete($product->image);
            }

            // Delete the product (tags will be automatically removed due to cascade)
            $product->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Product deleted successfully',
            ]);
        });
    }

    /**
     * Toggle product status
     */
    public function toggleStatus(Product $product)
    {
        // Ensure user can only toggle their own products
        if ($product->user_id !== Auth::id()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Product not found',
            ], 404);
        }

        $product->update(['is_active' => !$product->is_active]);

        return response()->json([
            'status' => 'success',
            'message' => 'Product status updated successfully',
            'data' => $product,
        ]);
    }

    /**
     * Toggle featured status
     */
    public function toggleFeatured(Product $product)
    {
        // Ensure user can only toggle their own products
        if ($product->user_id !== Auth::id()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Product not found',
            ], 404);
        }

        $product->update(['is_featured' => !$product->is_featured]);

        return response()->json([
            'status' => 'success',
            'message' => 'Product featured status updated successfully',
            'data' => $product,
        ]);
    }

    /**
     * Get categories for dropdown
     */
    public function getCategories()
    {
        $categories = Category::active()->ordered()->get();

        return response()->json([
            'status' => 'success',
            'data' => $categories,
        ]);
    }
}
