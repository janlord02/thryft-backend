<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\BusinessLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A business's physical locations, behind business:business.manage_locations.
 *
 * There is always exactly one primary location. Its address is mirrored onto
 * the owner's user row, because nearby search and older screens still read
 * the address from there.
 */
class BusinessLocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = $this->business($request);

        return response()->json(['status' => 'success', 'data' => $this->listFor($business)]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $data = $this->validated($request);

        $location = DB::transaction(function () use ($business, $data) {
            $first = !$business->locations()->exists();
            $location = $business->locations()->create($data + ['is_primary' => $first]);
            if ($first || !empty($data['is_primary'])) {
                $this->makePrimary($business, $location);
            }

            return $location;
        });

        return response()->json(['status' => 'success', 'message' => 'Location added.', 'data' => $location->fresh()], 201);
    }

    public function update(Request $request, int $location): JsonResponse
    {
        $business = $this->business($request);
        $row = $this->rowOf($business, $location);
        $data = $this->validated($request);

        DB::transaction(function () use ($business, $row, $data) {
            $makePrimary = !empty($data['is_primary']);
            unset($data['is_primary']);
            $row->update($data);
            if ($makePrimary || $row->is_primary) {
                $this->makePrimary($business, $row);
            }
        });

        return response()->json(['status' => 'success', 'message' => 'Location saved.', 'data' => $row->fresh()]);
    }

    public function destroy(Request $request, int $location): JsonResponse
    {
        $business = $this->business($request);
        $row = $this->rowOf($business, $location);

        if ($row->is_primary) {
            return response()->json([
                'status' => 'error',
                'message' => 'Make another location your main one before removing this one.',
            ], 422);
        }

        // Offers and events pinned here fall back to "all locations" (FKs are nullOnDelete).
        $row->delete();

        return response()->json(['status' => 'success', 'message' => 'Location removed.']);
    }

    private function makePrimary(Business $business, BusinessLocation $location): void
    {
        $business->locations()->whereKeyNot($location->id)->update(['is_primary' => false]);
        $location->forceFill(['is_primary' => true])->save();

        $business->owner?->forceFill([
            'address' => $location->address,
            'city' => $location->city,
            'state' => $location->state,
            'zipcode' => $location->zipcode,
            'country' => $location->country,
            'latitude' => $location->latitude,
            'longitude' => $location->longitude,
        ])->save();
    }

    private function listFor(Business $business)
    {
        return $business->locations()->orderByDesc('is_primary')->orderBy('label')->get();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'zipcode' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:120'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'hours' => ['nullable', 'array', 'max:7'],
            'hours.*.label' => ['nullable', 'string', 'max:40'],
            'hours.*.value' => ['nullable', 'string', 'max:60'],
            'is_primary' => ['nullable', 'boolean'],
        ]);
    }

    private function rowOf(Business $business, int $id): BusinessLocation
    {
        return $business->locations()->findOrFail($id);
    }

    private function business(Request $request): Business
    {
        $business = $request->attributes->get('business');
        abort_unless($business instanceof Business, 404, 'No business record found for this account.');

        return $business;
    }
}
