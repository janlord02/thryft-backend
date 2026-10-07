@extends('public.layout')

{{-- $promotion is PromotionController::detail(); metadata comes as variables
     from PromotionPageController. Everything is user text, so {{ }} only. --}}

@section('content')
    @php
        $label = ['partner' => 'Partner offer', 'bundle' => 'Local bundle', 'campaign' => 'Community promotion'][$promotion['type']] ?? 'Promotion';
    @endphp

    <p class="muted">{{ $label }} · by {{ $promotion['organizer']['name'] }}</p>

    @if($promotion['image_url'])
        <img class="cover" src="{{ $promotion['image_url'] }}" alt="{{ $promotion['title'] }}">
    @endif

    <h1>{{ $promotion['title'] }}</h1>

    @if($promotion['starts_at'] || $promotion['ends_at'])
        <p class="discount">
            @if($promotion['starts_at']){{ \Carbon\Carbon::parse($promotion['starts_at'])->toFormattedDateString() }}@endif
            @if($promotion['ends_at']) – {{ \Carbon\Carbon::parse($promotion['ends_at'])->toFormattedDateString() }}@endif
        </p>
    @endif

    @if(!$promotion['is_running'])
        <p class="muted">This promotion has ended.</p>
    @endif

    @if($promotion['description'])
        <p>{{ $promotion['description'] }}</p>
    @endif

    <h2>{{ $promotion['type'] === 'bundle' ? "What's in it" : 'Taking part' }}</h2>
    @foreach($promotion['offers'] as $offer)
        <div class="card">
            @if($offer['role'])<p class="muted">{{ $offer['role'] }}</p>@endif
            <h3>
                @if($offer['business']['slug'])
                    <a href="{{ route('public.business', ['business' => $offer['business']['slug']]) }}">{{ $offer['business']['name'] }}</a>
                @else
                    {{ $offer['business']['name'] }}
                @endif
            </h3>
            <p class="discount">{{ $offer['coupon']['formatted_discount'] }} off · {{ $offer['coupon']['title'] }}</p>
            @if($offer['unlocked_by'])
                <p class="muted">Unlocks after you use the offer at {{ $offer['unlocked_by'] }}.</p>
            @endif
        </div>
    @endforeach

    <p class="muted">Claim the offers in the Thryft app with a free account.</p>
@endsection
