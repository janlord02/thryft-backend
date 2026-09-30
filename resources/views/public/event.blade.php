@extends('public.layout')

{{-- $event, $public, $biz, $when, $where and the meta* variables come from
     EventPageController. Metadata is passed as variables, not sections,
     because @yield is unescaped — see the note in public/layout.blade.php. --}}

@push('structured_data')
{{-- schema.org Event, so search engines can list it with its date and place. --}}
<script type="application/ld+json">
{!! json_encode(array_filter([
    '@context' => 'https://schema.org',
    '@type' => 'Event',
    'name' => $event->title,
    'description' => $event->description,
    'url' => url()->current(),
    'image' => $public['image_url'],
    'startDate' => $event->starts_at->toIso8601String(),
    'endDate' => optional($event->ends_at)->toIso8601String(),
    'eventStatus' => 'https://schema.org/EventScheduled',
    'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
    'location' => array_filter([
        '@type' => 'Place',
        'name' => $event->venue_name ?: $biz['name'],
        'address' => array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => $event->address,
            'addressLocality' => $event->city,
        ]),
        'geo' => ($event->latitude && $event->longitude) ? [
            '@type' => 'GeoCoordinates',
            'latitude' => (float) $event->latitude,
            'longitude' => (float) $event->longitude,
        ] : null,
    ]),
    'organizer' => array_filter([
        '@type' => 'LocalBusiness',
        'name' => $biz['name'],
        'url' => $event->business->slug ? route('public.business', ['business' => $event->business->slug]) : null,
        'telephone' => $biz['phone'],
    ]),
    'offers' => $event->registration_enabled ? [
        '@type' => 'Offer',
        'price' => '0',
        'priceCurrency' => 'USD',
        'availability' => $event->isFull() ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock',
        'url' => url()->current(),
    ] : null,
]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>
@endpush

@section('content')
    <p class="muted">
        @if($event->business->slug)
            <a href="{{ route('public.business', ['business' => $event->business->slug]) }}">{{ $biz['name'] }}</a>
        @else
            {{ $biz['name'] }}
        @endif
    </p>

    @if($public['image_url'])
        <img class="cover" src="{{ $public['image_url'] }}" alt="{{ $event->title }}">
    @endif

    <h1>{{ $event->title }}</h1>
    <p class="discount">{{ $when }}@if($event->ends_at) – {{ $event->ends_at->format('g:i A') }}@endif</p>

    @if($where)
        <p class="muted">{{ $where }}</p>
    @endif

    @if($event->description)
        <p>{{ $event->description }}</p>
    @endif

    @if($event->registration_enabled)
        @if($event->isFull())
            <p class="muted">This event is full.</p>
        @elseif(!is_null($public['spots_left']))
            <p class="muted">{{ $public['spots_left'] }} spots left</p>
        @endif
        <p class="muted">Register in the Thryft app with a free account.</p>
    @endif
@endsection
