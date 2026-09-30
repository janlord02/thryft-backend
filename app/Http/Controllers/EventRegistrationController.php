<?php

namespace App\Http\Controllers;

use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\EventRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A shopper's seat at an event.
 *
 * Registration is the same shape as claiming a coupon: the seat is taken with
 * a conditional UPDATE on the event row, then the registration row is written
 * inside the same transaction, so a full event can never oversell and a
 * retried request finds its existing row instead of a second seat.
 */
class EventRegistrationController extends Controller
{
    public function register(Request $request, int $event): JsonResponse
    {
        $user = $request->user();
        $row = Event::query()->published()->findOrFail($event);

        if ($row->isOver()) {
            return $this->refuse('event_over', 'This event has already happened.');
        }
        if (!$row->registration_enabled) {
            return $this->refuse('registration_closed', 'Registration is not open for this event.');
        }

        $result = DB::transaction(function () use ($row, $user) {
            $existing = EventRegistration::query()
                ->where('event_id', $row->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existing && $existing->isRegistered()) {
                return ['already' => true, 'registration' => $existing];
            }

            if (!$row->tryTakeSeat()) {
                return ['full' => true];
            }

            if ($existing) {
                $existing->update(['status' => 'registered', 'registered_at' => now(), 'cancelled_at' => null]);

                return ['registration' => $existing];
            }

            return ['registration' => EventRegistration::create([
                'event_id' => $row->id,
                'user_id' => $user->id,
                'status' => 'registered',
                'registered_at' => now(),
            ])];
        });

        if (!empty($result['full'])) {
            return $this->refuse('event_full', 'This event is full.');
        }

        return response()->json([
            'status' => 'success',
            'message' => !empty($result['already']) ? "You're already registered." : "You're registered. See you there!",
            'data' => [
                'already_registered' => !empty($result['already']),
                'event' => new EventResource($row->fresh()->load('business')),
            ],
        ]);
    }

    public function unregister(Request $request, int $event): JsonResponse
    {
        $user = $request->user();
        $row = Event::query()->findOrFail($event);

        DB::transaction(function () use ($row, $user) {
            $registration = EventRegistration::query()
                ->where('event_id', $row->id)
                ->where('user_id', $user->id)
                ->where('status', 'registered')
                ->first();

            if (!$registration) {
                return;
            }

            $registration->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $row->releaseSeat();
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Your registration has been cancelled.',
            'data' => ['event' => new EventResource($row->fresh()->load('business'))],
        ]);
    }

    /** The shopper's upcoming registrations. */
    public function mine(Request $request)
    {
        $events = Event::query()
            ->whereHas('registrations', fn ($q) => $q->where('user_id', $request->user()->id)->where('status', 'registered'))
            ->where('status', '!=', 'draft')
            ->upcoming()
            ->with('business')
            ->orderBy('starts_at')
            ->get();

        return EventResource::collection($events);
    }

    private function refuse(string $code, string $message): JsonResponse
    {
        return response()->json(['status' => 'error', 'code' => $code, 'message' => $message], 409);
    }
}
