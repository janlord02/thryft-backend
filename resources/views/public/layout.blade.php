{{--
    Shared shell for every crawlable public page.

    Carries the three things that make these pages worth having: descriptive
    metadata, Open Graph/Twitter cards so shared links unfurl, and a JSON-LD
    block so search engines can read the business, offer or event as structured
    data rather than guessing from prose.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>@yield('title')</title>
    <meta name="description" content="@yield('meta_description')">
    <link rel="canonical" href="{{ $canonical ?? url()->current() }}">

    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:title" content="@yield('title')">
    <meta property="og:description" content="@yield('meta_description')">
    <meta property="og:url" content="{{ $canonical ?? url()->current() }}">
    @hasSection('og_image')
        <meta property="og:image" content="@yield('og_image')">
        <meta name="twitter:card" content="summary_large_image">
    @else
        <meta name="twitter:card" content="summary">
    @endif
    <meta name="twitter:title" content="@yield('title')">
    <meta name="twitter:description" content="@yield('meta_description')">

    @stack('structured_data')

    <style>
        :root {
            --ink: #1a1a1a;
            --muted: #6b7280;
            --line: #e5e7eb;
            --bg: #ffffff;
            --accent: #0f766e;
        }
        @media (prefers-color-scheme: dark) {
            :root { --ink:#f3f4f6; --muted:#9ca3af; --line:#374151; --bg:#111827; --accent:#5eead4; }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font: 16px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        .wrap { max-width: 720px; margin: 0 auto; padding: 32px 16px 64px; }
        a { color: var(--accent); }
        h1 { font-size: 1.9rem; line-height: 1.2; margin: 0 0 8px; }
        h2 { font-size: 1.2rem; margin: 32px 0 12px; }
        .muted { color: var(--muted); }
        .card {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 16px;
            margin: 0 0 12px;
        }
        .card h3 { margin: 0 0 4px; font-size: 1.05rem; }
        .discount { font-weight: 600; color: var(--accent); }
        .cta {
            display: inline-block;
            margin-top: 20px;
            padding: 12px 20px;
            border-radius: 999px;
            background: var(--accent);
            color: var(--bg);
            text-decoration: none;
            font-weight: 600;
        }
        .cover { width: 100%; border-radius: 12px; margin-bottom: 20px; }
        footer { margin-top: 48px; padding-top: 16px; border-top: 1px solid var(--line); }
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
