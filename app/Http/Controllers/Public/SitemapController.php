<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Coupon;
use Illuminate\Http\Response;

/**
 * sitemap.xml, generated on request rather than written to disk.
 *
 * Deals change constantly — a build-time file would be stale within hours, and
 * regenerating it per edit across thousands of businesses is not worth it. The
 * response is cached briefly instead, which is enough for a crawler.
 */
class SitemapController extends Controller
{
    private const CACHE_SECONDS = 3600;

    public function index(): Response
    {
        $urls = [];

        Business::active()->whereNotNull('slug')->orderBy('id')->chunk(500, function ($businesses) use (&$urls) {
            foreach ($businesses as $business) {
                $urls[] = [
                    'loc' => route('public.business', ['business' => $business->slug]),
                    'lastmod' => optional($business->updated_at)->toAtomString(),
                    'changefreq' => 'weekly',
                ];
            }
        });

        Coupon::query()
            ->whereNotNull('slug')
            ->whereNotNull('business_id')
            ->active()
            ->valid()
            ->with('business:id,slug,status')
            ->orderBy('id')
            ->chunk(500, function ($coupons) use (&$urls) {
                foreach ($coupons as $coupon) {
                    // Skip orphans and suspended businesses: a sitemap entry
                    // that 404s is worse than an absent one.
                    if (!$coupon->business || $coupon->business->status !== 'active' || !$coupon->business->slug) {
                        continue;
                    }

                    $urls[] = [
                        'loc' => route('public.deal', [
                            'business' => $coupon->business->slug,
                            'couponSlug' => $coupon->slug,
                        ]),
                        'lastmod' => optional($coupon->updated_at)->toAtomString(),
                        'changefreq' => 'daily',
                    ];
                }
            });

        return response($this->render($urls), 200, [
            'Content-Type' => 'application/xml',
            'Cache-Control' => 'public, max-age=' . self::CACHE_SECONDS,
        ]);
    }

    /**
     * @param array<array{loc:string, lastmod:?string, changefreq:string}> $urls
     */
    private function render(array $urls): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $url) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . htmlspecialchars($url['loc'], ENT_XML1) . "</loc>\n";

            if ($url['lastmod']) {
                $xml .= '    <lastmod>' . $url['lastmod'] . "</lastmod>\n";
            }

            $xml .= '    <changefreq>' . $url['changefreq'] . "</changefreq>\n";
            $xml .= "  </url>\n";
        }

        return $xml . '</urlset>';
    }
}
