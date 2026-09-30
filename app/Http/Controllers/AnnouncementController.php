<?php

namespace App\Http\Controllers;

use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use App\Models\Business;
use App\Models\User;
use App\Services\ShopperAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * A business's announcements: the merchant side (behind
 * business:business.manage_events) and the public listing on a business
 * page (optional auth, so guests read it too).
 */
class AnnouncementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = $this->business($request);

        $rows = Announcement::query()
            ->where('business_id', $business->id)
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $data = $this->validated($request);

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('announcements', 'public');
        }
        // Explicit rather than the column default, so the created model (and
        // the alert check below) see the status without a refresh.
        $data['status'] = $data['status'] ?? 'published';
        if ($data['status'] === 'published') {
            $data['published_at'] = now();
        }

        $row = Announcement::create(['business_id' => $business->id] + $data);

        if ($row->status === 'published') {
            app(ShopperAlerts::class)->announceNews($row);
        }

        return response()->json([
            'status' => 'success',
            'message' => $row->status === 'published' ? 'Posted.' : 'Saved as a draft.',
            'data' => $row,
        ], 201);
    }

    public function update(Request $request, int $announcement): JsonResponse
    {
        $business = $this->business($request);
        $row = $this->rowOf($business, $announcement);
        $data = $this->validated($request);

        if ($request->hasFile('image')) {
            if ($row->image_path) {
                Storage::disk('public')->delete($row->image_path);
            }
            $data['image_path'] = $request->file('image')->store('announcements', 'public');
        }
        if (($data['status'] ?? $row->status) === 'published' && !$row->published_at) {
            $data['published_at'] = now();
        }

        $row->update($data);
        $row->refresh();

        if ($row->status === 'published') {
            app(ShopperAlerts::class)->announceNews($row);
        }

        return response()->json(['status' => 'success', 'message' => 'Updated.', 'data' => $row]);
    }

    public function destroy(Request $request, int $announcement): JsonResponse
    {
        $row = $this->rowOf($this->business($request), $announcement);
        $row->delete();

        return response()->json(['status' => 'success', 'message' => 'Deleted.']);
    }

    /** What a business has posted, newest first. The app addresses a business by its owner's id. */
    public function forBusiness(int $businessId)
    {
        $business = Business::query()->whereKey($businessId)->first()
            ?? Business::query()->where('owner_user_id', $businessId)->first();

        if (!$business || $business->status !== 'active') {
            return AnnouncementResource::collection(collect());
        }

        $rows = Announcement::query()
            ->where('business_id', $business->id)
            ->published()
            ->orderByDesc('published_at')
            ->limit(20)
            ->get();

        return AnnouncementResource::collection($rows);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'body' => ['nullable', 'string', 'max:5000'],
            'link_url' => ['nullable', 'url', 'max:255'],
            'status' => ['nullable', Rule::in(['draft', 'published'])],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:4096'],
        ]);
        unset($data['image']);

        return $data;
    }

    private function rowOf(Business $business, int $id): Announcement
    {
        return Announcement::query()->where('business_id', $business->id)->findOrFail($id);
    }

    private function business(Request $request): Business
    {
        $business = $request->attributes->get('business');

        abort_unless($business instanceof Business, 404, 'No business record found for this account.');

        return $business;
    }
}
