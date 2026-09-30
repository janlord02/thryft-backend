<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Http\Resources\PublicBusinessResource;
use App\Models\Event;
use Illuminate\Support\Str;

/**
 * The crawlable, shareable page for one event: /e/{slug}.
 *
 * Only published events of active businesses render; a draft or a cancelled
 * event is a 404 to the outside world so a stale share does not advertise
 * something that is not happening.
 */
class EventPageController extends Controller
{
    public function show(string $slug)
    {
        $event = Event::query()
            ->where('slug', $slug)
            ->published()
            ->with(['business.primaryLocation'])
            ->firstOrFail();

        abort_if(!$event->business || $event->business->status !== 'active', 404);

        $public = (new EventResource($event))->toArray(request());
        $biz = (new PublicBusinessResource($event->business))->toArray(request());

        $when = $event->starts_at->format('D, M j \a\t g:i A');
        $where = collect([$event->venue_name, $event->address, $event->city])->filter()->unique()->implode(', ');

        return view('public.event', [
            'event' => $event,
            'public' => $public,
            'biz' => $biz,
            'when' => $when,
            'where' => $where,
            'metaTitle' => $event->title . ' — ' . $biz['name'] . ' — Thryft',
            'metaDescription' => Str::limit(trim(($event->description ?: "{$event->title} at {$biz['name']}.") . " {$when}" . ($where ? ", {$where}" : '')), 160),
            'ogType' => 'event',
            'ogImage' => $public['image_url'] ?? $biz['cover_url'] ?? $biz['logo_url'],
            'appUrl' => rtrim(config('app.frontend_url'), '/') . '/user/events/' . $event->slug,
        ]);
    }
}
