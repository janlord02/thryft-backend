<?php

namespace App\Http\Controllers;

use App\Http\Resources\EventResource;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Upcoming events for shoppers and guests. Runs under auth.optional: with a
 * token the payload says whether the shopper is registered, without one it
 * is a public listing.
 */
class EventBrowseController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'numeric', 'min:1', 'max:200'],
            'business_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Event::query()
            ->published()
            ->upcoming()
            ->with('business')
            ->whereHas('business', fn ($q) => $q->where('status', 'active'));

        if (!empty($validated['business_id'])) {
            // The app knows a business by its owner's id; both work here.
            $id = (int) $validated['business_id'];
            $query->where(function ($q) use ($id) {
                $q->where('business_id', $id)
                    ->orWhereHas('business', fn ($b) => $b->where('owner_user_id', $id));
            });
        }

        $lat = $validated['latitude'] ?? null;
        $lng = $validated['longitude'] ?? null;

        if ($lat !== null && $lng !== null) {
            // Whole miles, bound as an integer on purpose: PDO binds floats as
            // strings, and SQLite orders every number below every string, so
            // "8911 <= '10'" would be true and the radius would filter nothing.
            $radius = (int) ceil((float) ($validated['radius'] ?? 25));
            // Haversine in miles, portable across MySQL and SQLite: no
            // engine-specific functions beyond the trig set both provide.
            $distance = '(3959 * acos(least(1.0, cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))))';
            if (DB::getDriverName() === 'sqlite') {
                $distance = str_replace('least(1.0, ', 'min(1.0, ', $distance);
            }
            // The radius filter repeats the expression in WHERE rather than
            // using HAVING: SQLite refuses HAVING on a non-aggregate query.
            $query->whereNotNull('latitude')->whereNotNull('longitude')
                ->select('events.*')
                ->selectRaw("{$distance} as distance", [$lat, $lng, $lat])
                ->whereRaw("{$distance} <= ?", [$lat, $lng, $lat, $radius])
                ->orderBy('distance')
                ->orderBy('starts_at');
        } else {
            $query->orderBy('starts_at');
        }

        $events = $query->limit((int) ($validated['limit'] ?? 50))->get();

        return EventResource::collection($events);
    }

    public function show(Request $request, string $slug)
    {
        $event = Event::query()
            ->where('slug', $slug)
            ->published()
            ->with('business')
            ->firstOrFail();

        return new EventResource($event);
    }
}
