<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Event;
use App\Services\NotificationService;
use App\Services\ShopperAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * A merchant's events. Every route sits behind business:business.manage_events,
 * so the acting business is already resolved on the request.
 */
class EventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = $this->business($request);

        $events = Event::query()
            ->where('business_id', $business->id)
            ->orderByRaw("CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END")
            ->orderBy('starts_at')
            ->get();

        return response()->json(['status' => 'success', 'data' => $events]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $data = $this->validated($request);

        $event = DB::transaction(function () use ($request, $business, $data) {
            if ($request->hasFile('image')) {
                $data['image_path'] = $request->file('image')->store('events', 'public');
            }

            // No venue given: the event is at the chosen location, or the main one.
            if (empty($data['address']) && empty($data['latitude'])) {
                $data = array_merge($data, $this->businessVenue($business, $data['location_id'] ?? null));
            }

            if (($data['status'] ?? 'draft') === 'published') {
                $data['published_at'] = now();
            }

            return Event::create(['business_id' => $business->id] + $data);
        });

        $this->afterPublish($event);

        return response()->json([
            'status' => 'success',
            'message' => $event->status === 'published' ? 'Event published.' : 'Event saved as a draft.',
            'data' => $event,
        ], 201);
    }

    public function show(Request $request, int $event): JsonResponse
    {
        $row = $this->eventOf($this->business($request), $event);

        return response()->json(['status' => 'success', 'data' => $row]);
    }

    public function update(Request $request, int $event): JsonResponse
    {
        $business = $this->business($request);
        $row = $this->eventOf($business, $event);
        $data = $this->validated($request, $row);

        $wasPublished = $row->status === 'published';
        $wasCancelled = $row->status === 'cancelled';

        DB::transaction(function () use ($request, $row, $data) {
            if ($request->hasFile('image')) {
                if ($row->image_path) {
                    Storage::disk('public')->delete($row->image_path);
                }
                $data['image_path'] = $request->file('image')->store('events', 'public');
            }

            if (($data['status'] ?? $row->status) === 'published' && !$row->published_at) {
                $data['published_at'] = now();
            }

            $row->update($data);
        });

        $row->refresh();

        if (!$wasPublished) {
            $this->afterPublish($row);
        }

        if ($row->status === 'cancelled' && !$wasCancelled) {
            $this->tellRegistrantsItIsCancelled($row);
        }

        return response()->json(['status' => 'success', 'message' => 'Event updated.', 'data' => $row]);
    }

    public function destroy(Request $request, int $event): JsonResponse
    {
        $row = $this->eventOf($this->business($request), $event);

        // A published event with people signed up is cancelled, not deleted,
        // so they can be told and the record survives.
        if ($row->status === 'published' && $row->registered_count > 0) {
            $row->update(['status' => 'cancelled']);
            $this->tellRegistrantsItIsCancelled($row);

            return response()->json(['status' => 'success', 'message' => 'Event cancelled and attendees notified.']);
        }

        $row->delete();

        return response()->json(['status' => 'success', 'message' => 'Event deleted.']);
    }

    /** Who is coming. The host may see names; nobody else sees this list. */
    public function registrations(Request $request, int $event): JsonResponse
    {
        $row = $this->eventOf($this->business($request), $event);

        $people = $row->registrations()
            ->where('status', 'registered')
            ->with('user:id,name,firstname,lastname,email')
            ->orderBy('registered_at')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->user?->display_name,
                'email' => $r->user?->email,
                'registered_at' => $r->registered_at,
            ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'event' => ['id' => $row->id, 'title' => $row->title, 'capacity' => $row->capacity],
                'registrations' => $people,
            ],
        ]);
    }

    private function validated(Request $request, ?Event $existing = null): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'timezone' => ['nullable', 'timezone'],
            'location_id' => ['nullable', 'integer', Rule::exists('business_locations', 'id')->where('business_id', $request->attributes->get('business')?->id)],
            'venue_name' => ['nullable', 'string', 'max:160'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'registration_enabled' => ['nullable'],
            'status' => ['nullable', Rule::in(['draft', 'published', 'cancelled'])],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:4096'],
        ];

        $data = $request->validate($rules);
        unset($data['image']);

        // FormData sends booleans as strings.
        if ($request->has('registration_enabled')) {
            $data['registration_enabled'] = $request->boolean('registration_enabled');
        }

        if ($existing && isset($data['capacity']) && $data['capacity'] < $existing->registered_count) {
            abort(422, "Capacity cannot be below the {$existing->registered_count} people already registered.");
        }

        return $data;
    }

    private function businessVenue(Business $business, ?int $locationId = null): array
    {
        $location = ($locationId ? $business->locations()->find($locationId) : null) ?? $business->primaryLocation;
        $owner = $business->owner;

        return [
            'venue_name' => $business->name,
            'address' => $location?->address ?: $owner?->address,
            'city' => $location?->city ?: $owner?->city,
            'latitude' => $location?->latitude ?: $owner?->latitude,
            'longitude' => $location?->longitude ?: $owner?->longitude,
        ];
    }

    private function afterPublish(Event $event): void
    {
        if ($event->status === 'published') {
            app(ShopperAlerts::class)->announceNewEvent($event);
        }
    }

    private function tellRegistrantsItIsCancelled(Event $event): void
    {
        $userIds = $event->registrations()->where('status', 'registered')->pluck('user_id')->all();
        if ($userIds === []) {
            return;
        }

        try {
            app(NotificationService::class)->send(
                title: "Cancelled: {$event->title}",
                message: "{$event->business?->name} has cancelled this event on {$event->localStartsAt()->format('M j')}. Sorry for the change of plans.",
                type: 'warning',
                userIds: $userIds,
                data: [
                    'kind' => 'event_cancelled',
                    'event_id' => $event->id,
                    'action_url' => '/user/events',
                ],
                channel: 'shopper',
            );
        } catch (\Throwable $e) {
            Log::error('Event cancellation notice failed', ['event_id' => $event->id, 'error' => $e->getMessage()]);
        }
    }

    private function eventOf(Business $business, int $id): Event
    {
        return Event::query()->where('business_id', $business->id)->findOrFail($id);
    }

    private function business(Request $request): Business
    {
        $business = $request->attributes->get('business');

        abort_unless($business instanceof Business, 404, 'No business record found for this account.');

        return $business;
    }
}
