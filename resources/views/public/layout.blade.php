{{--
    Shared shell for every crawlable public page.

    SECURITY: metadata arrives as VARIABLES and is rendered with {{ }}, never
    with @yield. Blade compiles @yield to a bare echo with no escaping, so a
    business named `"><script>…</script>` — business_name is validated only as
    string|max:255 — would break out of the <title> and the og:title content
    attribute and execute on every visitor of that page. Sections are still
    used for block-level markup, which child views control; only text that
    lands inside an attribute or <title> goes through this path.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $metaTitle }}</title>
    <meta name="description" content="{{ $metaDescription }}">
    <link rel="canonical" href="{{ $canonical ?? url()->current() }}">

    <meta property="og:type" content="{{ $ogType ?? 'website' }}">
    <meta property="og:title" content="{{ $metaTitle }}">
    <meta property="og:description" content="{{ $metaDescription }}">
    <meta property="og:url" content="{{ $canonical ?? url()->current() }}">
    @if(!empty($ogImage))
        <meta property="og:image" content="{{ $ogImage }}">
        <meta name="twitter:card" content="summary_large_image">
    @else
        <meta name="twitter:card" content="summary">
    @endif
    <meta name="twitter:title" content="{{ $metaTitle }}">
    <meta name="twitter:description" content="{{ $metaDescription }}">

    @stack('structured_data')

    @php
        // The brand colour set in admin Settings, so these pages match the app.
        // Checked as a hex value because it is printed into CSS.
        $accent = (string) \App\Models\Setting::getValue('primary', '#0f766e');
        $accent = preg_match('/^#[0-9A-Fa-f]{3,8}$/', $accent) ? $accent : '#0f766e';
    @endphp
    <style>
        :root { --ink:#1a1a1a; --muted:#6b7280; --line:#e5e7eb; --bg:#fff; --accent:{{ $accent }}; }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--bg); color: var(--ink);
            font: 16px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        .wrap { max-width: 720px; margin: 0 auto; padding: 32px 16px 64px; }
        a { color: var(--accent); }
        h1 { font-size: 1.9rem; line-height: 1.2; margin: 0 0 8px; }
        h2 { font-size: 1.2rem; margin: 32px 0 12px; }
        .muted { color: var(--muted); }
        .card { border: 1px solid var(--line); border-radius: 12px; padding: 16px; margin: 0 0 12px; }
        .card h3 { margin: 0 0 4px; font-size: 1.05rem; }
        .discount { font-weight: 600; color: var(--accent); }
        .cta {
            display: inline-block; margin-top: 20px; padding: 12px 20px; border-radius: 999px;
            background: var(--accent); color: var(--bg); text-decoration: none; font-weight: 600;
        }
        .cover { width: 100%; border-radius: 12px; margin-bottom: 20px; }
        footer { margin-top: 48px; padding-top: 16px; border-top: 1px solid var(--line); }
        .blk { margin: 28px 0; }
        .blk-hero__h { font-size: 1.6rem; margin: 12px 0 4px; }
        .blk-text { white-space: pre-line; }
        .blk-gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 8px; }
        .blk-gallery img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 10px; }
        .blk-hours { border-collapse: collapse; }
        .blk-hours th { text-align: left; padding: 4px 16px 4px 0; font-weight: 600; }
        .blk-hours td { padding: 4px 0; color: var(--muted); }
    </style>
</head>
<body>
    <div class="wrap">
        @yield('content')

        <footer class="muted">
            {{-- Deep link so a crawled page can hand the visitor to the app. --}}
            <a class="cta" href="{{ $appUrl ?? config('app.frontend_url') }}">Open in Thryft</a>
        </footer>
    </div>
</body>
</html>
