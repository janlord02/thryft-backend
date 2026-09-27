@extends('public.layout')

@php
    $public = (new \App\Http\Resources\PublicBusinessResource($business))->toArray(request());
    $location = $public['location'] ?? null;
    $summary = $public['description']
        ? \Illuminate\Support\Str::limit(strip_tags($public['description']), 155)
        : trim(($public['name'] ?? 'This business') . ' on Thryft'
            . ($location && $location['city'] ? ' — ' . $location['city'] : '')
            . '. See current deals and offers.');
@endphp

@section('title', $public['name'] . ' — Thryft')
@section('meta_description', $summary)
@section('og_type', 'business.business')
@if($public['cover_url'] ?? $public['logo_url'])
    @section('og_image', $public['cover_url'] ?? $public['logo_url'])
@endif

@push('structured_data')
{{-- schema.org LocalBusiness: lets search engines read the address, contact
     details and current offers as data rather than inferring them. --}}
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
]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
</script>
@endpush

@section('content')
    @if($public['cover_url'])
        <img class="cover" src="{{ $public['cover_url'] }}" alt="{{ $public['name'] }}">
    @endif

    <h1>{{ $public['name'] }}</h1>

    @if($location && ($location['city'] || $location['address']))
        <p class="muted">{{ collect([$location['address'], $location['city'], $location['state'], $location['zipcode']])->filter()->implode(', ') }}</p>
    @endif

    @if($public['description'])
        <p>{{ $public['description'] }}</p>
    @endif

    @if($public['phone'] || $public['website'])
        <p class="muted">
            @if($public['phone'])<span>{{ $public['phone'] }}</span>@endif
            @if($public['website']) · <a href="{{ $public['website'] }}" rel="nofollow noopener">Website</a>@endif
        </p>
    @endif

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
@endsection
