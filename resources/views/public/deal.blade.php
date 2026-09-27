@extends('public.layout')

@php
    $biz = (new \App\Http\Resources\PublicBusinessResource($business))->toArray(request());
    $deal = (new \App\Http\Resources\PublicCouponResource($coupon))->toArray(request());
    $summary = $deal['description']
        ? \Illuminate\Support\Str::limit(strip_tags($deal['description']), 155)
        : $deal['formatted_discount'] . ' off at ' . $biz['name'] . '. Claim on Thryft.';
@endphp

@section('title', $deal['title'] . ' at ' . $biz['name'] . ' — Thryft')
@section('meta_description', $summary)
@section('og_type', 'product')
@if($deal['banner_url'] ?? $biz['logo_url'])
    @section('og_image', $deal['banner_url'] ?? $biz['logo_url'])
@endif

@push('structured_data')
{{-- schema.org Offer, linked back to the seller so the deal and the business
     are connected in the knowledge graph rather than being two loose pages. --}}
<script type="application/ld+json">
{!! json_encode(array_filter([
    '@context' => 'https://schema.org',
    '@type' => 'Offer',
    'name' => $deal['title'],
    'description' => $deal['description'],
    'url' => url()->current(),
    'image' => $deal['banner_url'],
    'availabilityStarts' => optional($coupon->starts_at)->toIso8601String(),
    'availabilityEnds' => optional($coupon->expires_at)->toIso8601String(),
    'offeredBy' => array_filter([
        '@type' => 'LocalBusiness',
        'name' => $biz['name'],
        'url' => route('public.business', ['business' => $business->slug]),
        'telephone' => $biz['phone'],
    ]),
]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
</script>
@endpush

@section('content')
    <p class="muted">
        <a href="{{ route('public.business', ['business' => $business->slug]) }}">{{ $biz['name'] }}</a>
    </p>

    @if($deal['banner_url'])
        <img class="cover" src="{{ $deal['banner_url'] }}" alt="{{ $deal['title'] }}">
    @endif

    <h1>{{ $deal['title'] }}</h1>
    <p class="discount">{{ $deal['formatted_discount'] }} off</p>

    @if($deal['description'])<p>{{ $deal['description'] }}</p>@endif

    @if($deal['minimum_amount'])
        <p class="muted">Minimum spend {{ $deal['minimum_amount'] }}</p>
    @endif

    @if($deal['expires_at'])
        <p class="muted">Ends {{ $coupon->expires_at->toFormattedDateString() }}</p>
    @endif

    {{-- Scarcity is useful to a shopper; the underlying counts are not public. --}}
    @if(!is_null($deal['remaining']))
        <p class="muted">{{ $deal['remaining'] }} left</p>
    @endif

    @if(!empty($deal['terms_conditions']))
        <h2>Terms</h2>
        <ul class="muted">
            @foreach((array) $deal['terms_conditions'] as $term)
                <li>{{ is_string($term) ? $term : json_encode($term) }}</li>
            @endforeach
        </ul>
    @endif

    {{-- Claiming requires an account: the coupon code is never rendered here,
         only to the customer who actually holds the claim. --}}
@endsection
