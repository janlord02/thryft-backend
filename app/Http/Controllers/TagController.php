<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TagController extends Controller
{
    /**
     * Search tags for autocomplete
     */
    public function search(Request $request)
    {
        $query = $request->get('q', '');

        if (empty($query)) {
            return response()->json([
                'status' => 'success',
                'data' => [],
            ]);
        }

        $tags = Tag::active()
            ->search($query)
            ->orderBy('usage_count', 'desc')
            ->orderBy('name')
            ->limit(10)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $tags,
        ]);
    }

    /**
     * Get popular tags
     */
    public function popular()
    {
        $tags = Tag::active()
            ->popular()
            ->limit(20)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $tags,
        ]);
    }

    /**
     * Create a new tag
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:50|unique:tags,name',
            'description' => 'nullable|string|max:255',
            'color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
        ]);

        $tag = Tag::create([
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'description' => $request->description,
            'color' => $request->color,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Tag created successfully',
            'data' => $tag,
        ], 201);
    }

    /**
     * Get all tags
     */
    public function index(Request $request)
    {
        $query = Tag::active();

        if ($request->has('search') && $request->search) {
            $query->search($request->search);
        }

        $tags = $query->popular()->paginate(20);

        return response()->json([
            'status' => 'success',
            'data' => $tags,
        ]);
    }

    /**
     * Update tag usage count
     */
    public function updateUsage(Tag $tag)
    {
        $tag->incrementUsage();

        return response()->json([
            'status' => 'success',
            'data' => $tag,
        ]);
    }
}
