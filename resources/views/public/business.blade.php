@extends('public.layout')

@php
    // $public, $metaTitle, $metaDescription, $ogType and $ogImage come from
    // BusinessPageController. Metadata is NOT declared as sections: the layout
    // renders it with {{ }}, because @yield is unescaped.
    $location = $public['location'] ?? null;
    // A page that opens with a Hero block brings its own photo and heading;
    // printing the cover and name above it showed both twice.
    $opensWithHero = !empty($blocks) && ($blocks[0]['type'] ?? null) === 'hero';
@endphp

@push('structured_data')
{{-- schema.org LocalBusiness: lets search engines read the address, contact
     details and current offers as data rather than inferring them.
     json_encode escapes </script> via JSON_HEX_TAG, so user text is safe here. --}}
<script type="application/ld+json">
{!! json_encode(array_filter([
    '@context' => 'https://schema.org',
    '@type' => 'LocalBusiness',
    'name' => $public['name'],
    'description' => $public['description'],
    'url' => url()->current(),
    'image' => $public['logo_url'],
    'telephone' => $public['phone'],
    'address' => $location ? array_filter([
        '@type' => 'PostalAddress',
        'streetAddress' => $location['address'],
        'addressLocality' => $location['city'],
        'addressRegion' => $location['state'],
        'postalCode' => $location['zipcode'],
        'addressCountry' => $location['country'],
    ]) : null,
    'geo' => ($location && $location['latitude'] && $location['longitude']) ? [
        '@type' => 'GeoCoordinates',
        'latitude' => (float) $location['latitude'],
        'longitude' => (float) $location['longitude'],
    ] : null,
    'makesOffer' => $coupons->map(fn ($c) => array_filter([
        '@type' => 'Offer',
        'name' => $c->title,
        'description' => $c->description,
        'url' => route('public.deal', ['business' => $business->slug, 'couponSlug' => $c->slug]),
        'availabilityEnds' => optional($c->expires_at)->toIso8601String(),
    ]))->values()->all(),
]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>
@endpush

@section('content')
    @if($opensWithHero)
        @include('public.blocks', ['blocks' => [array_shift($blocks)], 'business' => $business, 'pageHeading' => true])
    @else
        @if($public['cover_url'])
            <img class="cover" src="{{ $public['cover_url'] }}" alt="{{ $public['name'] }}">
        @endif

        <h1>{{ $public['name'] }}</h1>
    @endif

    @if($location && ($location['city'] || $location['address']))
        <p class="muted">{{ collect([$location['address'], $location['city'], $location['state'], $location['zipcode']])->filter()->implode(', ') }}</p>
    @endif

    @if(empty($blocks) && $public['description'])
        <p>{{ $public['description'] }}</p>
    @endif

    @if($public['phone'] || $public['website'])
        <p class="muted">
            @if($public['phone'])<span>{{ $public['phone'] }}</span>@endif
            @if($public['website']) · <a href="{{ $public['website'] }}" rel="nofollow noopener">Website</a>@endif
        </p>
    @endif

    @if(isset($announcements) && $announcements->isNotEmpty())
        <h2>Latest from {{ $public['name'] }}</h2>
        @foreach($announcements as $note)
            <div class="card">
                <h3>{{ $note->title }}</h3>
                @if($note->body)<p class="muted">{{ \Illuminate\Support\Str::limit($note->body, 200) }}</p>@endif
                @if($note->link_url)<p><a href="{{ $note->link_url }}" rel="nofollow noopener">Read more</a></p>@endif
                <p class="muted">{{ optional($note->published_at)->toFormattedDateString() }}</p>
            </div>
        @endforeach
    @endif

    @if(!empty($blocks))
        @include('public.blocks', ['blocks' => $blocks, 'business' => $business])
    @endif

    @if(empty($blocks))
    <h2>Current deals</h2>

    @forelse($coupons as $coupon)
        <div class="card">
            <h3>
                <a href="{{ route('public.deal', ['business' => $business->slug, 'couponSlug' => $coupon->slug]) }}">
                    {{ $coupon->title }}
                </a>
            </h3>
            <p class="discount">{{ $coupon->formatted_discount }} off</p>
            @if($coupon->description)<p class="muted">{{ \Illuminate\Support\Str::limit($coupon->description, 140) }}</p>@endif
            @if($coupon->expires_at)<p class="muted">Ends {{ $coupon->expires_at->toFormattedDateString() }}</p>@endif
        </div>
    @empty
        <p class="muted">No active deals right now.</p>
    @endforelse
    @endif
@endsection
