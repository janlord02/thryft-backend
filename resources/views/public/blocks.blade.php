{{-- The merchant-built page, rendered from the same resolved blocks the app
     shows. $blocks comes from App\Support\PageBlocks::resolve(). Everything
     is user text, so it all goes through {{ }}. --}}
@foreach($blocks as $block)
    @switch($block['type'])
        @case('hero')
            <section class="blk blk-hero">
                @if(!empty($block['image_url']))
                    <img class="cover" src="{{ $block['image_url'] }}" alt="{{ $block['headline'] ?? '' }}">
                @endif
                @if(!empty($block['headline']))
                    @if(!empty($pageHeading))<h1 class="blk-hero__h">{{ $block['headline'] }}</h1>
                    @else<h2 class="blk-hero__h">{{ $block['headline'] }}</h2>@endif
                @endif
                @if(!empty($block['subheadline']))<p class="muted">{{ $block['subheadline'] }}</p>@endif
            </section>
            @break

        @case('about')
        @case('text')
            <section class="blk">
                @if(!empty($block['title']))<h2>{{ $block['title'] }}</h2>@endif
                @php $body = $block['text'] ?? $block['body'] ?? null; @endphp
                @if($body)<p class="blk-text">{{ $body }}</p>@endif
            </section>
            @break

        @case('gallery')
            @if(!empty($block['image_urls']))
                <section class="blk">
                    @if(!empty($block['title']))<h2>{{ $block['title'] }}</h2>@endif
                    <div class="blk-gallery">
                        @foreach($block['image_urls'] as $url)
                            <img src="{{ $url }}" alt="" loading="lazy">
                        @endforeach
                    </div>
                </section>
            @endif
            @break

        @case('hours')
            @if(!empty($block['rows']))
                <section class="blk">
                    <h2>{{ $block['title'] ?? 'Hours' }}</h2>
                    <table class="blk-hours">
                        @foreach($block['rows'] as $row)
                            <tr><th>{{ $row['label'] }}</th><td>{{ $row['value'] }}</td></tr>
                        @endforeach
                    </table>
                </section>
            @endif
            @break

        @case('deals')
            <section class="blk">
                <h2>{{ $block['title'] ?? 'Current deals' }}</h2>
                @forelse($block['items'] as $deal)
                    <div class="card">
                        <h3>
                            @if(!empty($deal['slug']) && $business->slug)
                                <a href="{{ route('public.deal', ['business' => $business->slug, 'couponSlug' => $deal['slug']]) }}">{{ $deal['title'] }}</a>
                            @else
                                {{ $deal['title'] }}
                            @endif
                        </h3>
                        <p class="discount">{{ $deal['formatted_discount'] }} off</p>
                        @if(!empty($deal['description']))<p class="muted">{{ \Illuminate\Support\Str::limit($deal['description'], 140) }}</p>@endif
                        @if(!empty($deal['expires_at']))<p class="muted">Ends {{ \Carbon\Carbon::parse($deal['expires_at'])->toFormattedDateString() }}</p>@endif
                    </div>
                @empty
                    <p class="muted">No active deals right now.</p>
                @endforelse
            </section>
            @break

        @case('events')
            @if(!empty($block['items']))
                <section class="blk">
                    <h2>{{ $block['title'] ?? 'Coming up' }}</h2>
                    @foreach($block['items'] as $event)
                        <div class="card">
                            <h3><a href="{{ $event['public_url'] }}">{{ $event['title'] }}</a></h3>
                            <p class="muted">
                                {{ \Carbon\Carbon::parse($event['starts_at'])->setTimezone($event['timezone'] ?? config('app.timezone'))->format('D, M j \a\t g:i A') }}
                                @if(!empty($event['venue_name'])) · {{ $event['venue_name'] }}@endif
                            </p>
                        </div>
                    @endforeach
                </section>
            @endif
            @break

        @case('contact')
            <section class="blk">
                <h2>{{ $block['title'] ?? 'Find us' }}</h2>
                @if(!empty($block['address']))<p>{{ $block['address'] }}</p>@endif
                @if(!empty($block['phone']))<p><a href="tel:{{ $block['phone'] }}">{{ $block['phone'] }}</a></p>@endif
                @if(!empty($block['website']))<p><a href="{{ $block['website'] }}" rel="nofollow noopener">Website</a></p>@endif
                @if(!empty($block['map_url']))<p><a href="{{ $block['map_url'] }}" rel="nofollow noopener">Directions</a></p>@endif
                @if(!empty($block['note']))<p class="muted">{{ $block['note'] }}</p>@endif
            </section>
            @break

        @case('cta')
            @if(!empty($block['url']) && !empty($block['label']))
                <p><a class="cta" href="{{ $block['url'] }}" rel="nofollow noopener">{{ $block['label'] }}</a></p>
            @endif
            @break
    @endswitch
@endforeach
