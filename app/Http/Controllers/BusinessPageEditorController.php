<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Support\PageBlocks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The merchant side of the page editor. Behind business:business.edit_page,
 * so the owner and admins.
 */
class BusinessPageEditorController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $business = $this->business($request);

        return response()->json([
            'status' => 'success',
            'data' => [
                'blocks' => $business->page_blocks ?? [],
                'preview' => PageBlocks::resolve($business),
                'updated_at' => $business->page_updated_at,
                'public_url' => $business->slug ? route('public.business', ['business' => $business->slug]) : null,
                'catalog' => PageBlocks::catalog(),
                'templates' => PageBlocks::templates(),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $business = $this->business($request);

        $blocks = PageBlocks::validate($request->input('blocks', []));

        $business->forceFill([
            'page_blocks' => $blocks,
            'page_updated_at' => now(),
        ])->save();

        return response()->json([
            'status' => 'success',
            'message' => $blocks === [] ? 'Page cleared.' : 'Page saved.',
            'data' => [
                'blocks' => $blocks,
                'preview' => PageBlocks::resolve($business->fresh(), $blocks),
                'updated_at' => $business->page_updated_at,
            ],
        ]);
    }

    /** One image for a hero or gallery block. */
    public function media(Request $request): JsonResponse
    {
        $this->business($request);

        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:4096'],
        ]);

        $path = $request->file('image')->store('page-media', 'public');

        return response()->json([
            'status' => 'success',
            'data' => ['path' => $path, 'url' => asset('storage/' . $path)],
        ], 201);
    }

    private function business(Request $request): Business
    {
        $business = $request->attributes->get('business');

        abort_unless($business instanceof Business, 404, 'No business record found for this account.');

        return $business;
    }
}
